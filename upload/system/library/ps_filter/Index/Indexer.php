<?php
namespace PsFilter\Index;

use PsFilter\Kernel;
use PsFilter\Platform;

/**
 * Индексация. Единица работы — пачка товаров, все в одной транзакции:
 * витрина видит либо старые строки товара, либо новые, но никогда — пустой фасет.
 * Так работают и полная переиндексация (Job, пачками по курсору), и сохранение товара в админке.
 *
 * Что пишется на товар:
 *  - ps_filter_product       — все «живое» из oc_product (статус, остаток, бренд, налог, рейтинг…);
 *  - ps_filter_category_product — прямые привязки и все предки по category_path (is_direct);
 *  - ps_filter_price          — итоговая цена по каждой группе покупателей, как ее покажет витрина;
 *  - ps_filter_index / index_range — значения фасетов.
 */
class Indexer
{
    /** Источники, строки которых пишет индексатор (custom пишет админка, у них generation = 0). */
    const INDEXED_SOURCES = ['attribute', 'option', 'oc_filter', 'manufacturer', 'product_field'];

    /** oc_product.date_modified как его запоминает индекс (нулевая дата — NULL, строгий sql_mode ее не примет). */
    const SRC_MODIFIED = "IF(p.date_modified < '1000-01-01', NULL, p.date_modified)";

    /** @var Kernel */
    private $k;
    /** @var ValueStore */
    private $values;
    /** @var array|null */
    private $facets;
    /** @var int[]|null */
    private $languages;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
        $this->values = new ValueStore($kernel);
    }

    /**
     * Переиндексирует товары. Несуществующие id удаляются из индекса.
     *
     * @param int[] $ids
     * @param int   $generation метка полной переиндексации (см. Job)
     * @return array статистика: products, rows, ranges
     */
    public function indexProducts(array $ids, $generation)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['products' => 0, 'rows' => 0, 'ranges' => 0];
        }
        $db = $this->k->db();
        $in = implode(',', $ids);
        $gen = (int)$generation;
        $P = $this->k->prefix();
        $T = function ($n) { return '`' . $this->k->table($n) . '`'; };

        $db->query("START TRANSACTION");
        try {
            // 1. Товар: все, что витрина иначе считала бы на каждом клике.
            // name_sort — название на языке по умолчанию: вторичная сортировка листинга без JOIN описаний.
            $db->query("INSERT INTO " . $T('product') . " (product_id, active, store_mask, quantity, stock_status_id, manufacturer_id, tax_class_id, rating, sort_order, name_sort, grp, viewed, date_added, date_available, weight, length, width, height, generation, date_indexed, src_modified)
                SELECT p.product_id, IF(p.status = 1, 1, 0), COALESCE(s.mask, 0), p.quantity, p.stock_status_id, p.manufacturer_id, p.tax_class_id,
                       COALESCE(ROUND(r.rating), 0), p.sort_order, LCASE(LEFT(COALESCE(pd.name, ''), 64)), " . $this->groupExpr('p') . ", " . (Platform::oc4() ? "0" : "p.viewed") . ", DATE(p.date_added),
                       IF(p.date_available < '1000-01-01', '1970-01-01', p.date_available),
                       p.weight, p.length, p.width, p.height, " . $gen . ", NOW(), " . self::SRC_MODIFIED . "
                FROM `{$P}product` p
                LEFT JOIN (SELECT product_id, BIT_OR(1 << LEAST(store_id, 31)) AS mask FROM `{$P}product_to_store` WHERE product_id IN ($in) GROUP BY product_id) s ON s.product_id = p.product_id
                LEFT JOIN (SELECT product_id, AVG(rating) AS rating FROM `{$P}review` WHERE status = 1 AND product_id IN ($in) GROUP BY product_id) r ON r.product_id = p.product_id
                LEFT JOIN `{$P}product_description` pd ON pd.product_id = p.product_id AND pd.language_id = " . (int)$this->k->defaultLanguageId() . "
                WHERE p.product_id IN ($in)
                ON DUPLICATE KEY UPDATE active = VALUES(active), store_mask = VALUES(store_mask), quantity = VALUES(quantity),
                    stock_status_id = VALUES(stock_status_id), manufacturer_id = VALUES(manufacturer_id), tax_class_id = VALUES(tax_class_id),
                    rating = VALUES(rating), sort_order = VALUES(sort_order), name_sort = VALUES(name_sort), grp = VALUES(grp), viewed = VALUES(viewed), date_added = VALUES(date_added),
                    date_available = VALUES(date_available), weight = VALUES(weight), length = VALUES(length), width = VALUES(width),
                    height = VALUES(height), generation = VALUES(generation), date_indexed = VALUES(date_indexed), src_modified = VALUES(src_modified)");

            // Товары, которых больше нет в oc_product, — убрать отовсюду.
            $db->query("DELETE fp FROM " . $T('product') . " fp LEFT JOIN `{$P}product` p ON p.product_id = fp.product_id WHERE fp.product_id IN ($in) AND p.product_id IS NULL");

            // 2. Категории: прямая привязка + все предки. is_direct = 1, если хоть где-то привязан напрямую.
            $db->query("DELETE FROM " . $T('category_product') . " WHERE product_id IN ($in)");
            $db->query("INSERT INTO " . $T('category_product') . " (category_id, product_id, is_direct)
                SELECT cp.path_id, p2c.product_id, MAX(IF(cp.path_id = p2c.category_id, 1, 0))
                FROM `{$P}product_to_category` p2c
                JOIN `{$P}category_path` cp ON cp.category_id = p2c.category_id
                JOIN " . $T('product') . " fp ON fp.product_id = p2c.product_id
                WHERE p2c.product_id IN ($in)
                GROUP BY cp.path_id, p2c.product_id");

            // 3. Цены по группам — та же логика, что у витрины: акция, иначе скидка от 1 шт., иначе цена.
            // Внутри уже открытой транзакции — pricesSql(), не writePrices(): вложенный START TRANSACTION
            // в MySQL молча фиксирует внешнюю, и строки фасетов ниже писались бы без транзакции.
            $this->pricesSql("p.product_id IN ($in)");

            // 4. Значения фасетов.
            $stats = $this->writeFacetRows($ids, $gen);

            $db->query("COMMIT");
        } catch (\Exception $e) {
            $db->query("ROLLBACK");
            throw $e;
        }

        // Внешний движок (Manticore) получает изменения после фиксации транзакции.
        $this->k->touched($ids);

        $stats['products'] = count($ids);
        return $stats;
    }

    /**
     * Ключ группы выдачи (настройка group_by): поле товара, у пустого — свой id (товар сам себе группа).
     *
     * @param string $alias алиас oc_product
     * @return string SQL-выражение
     */
    public function groupExpr($alias)
    {
        $own = "CONCAT('#', " . $alias . ".product_id)";
        $field = $this->k->groupField();
        if ($field === '') {
            return $own;
        }
        $v = 'TRIM(' . $alias . '.`' . $field . '`)';
        return 'IF(' . $v . " <> '', LEFT(" . $v . ', 64), ' . $own . ')';
    }

    /** Пересчет ключей группы у всех товаров — после смены настройки group_by (без полной переиндексации). */
    public function regroup()
    {
        $this->k->db()->query('UPDATE `' . $this->k->table('product') . '` fp JOIN `' . $this->k->prefix() . 'product` p ON p.product_id = fp.product_id SET fp.grp = ' . $this->groupExpr('p'));
    }

    /**
     * Пересчет цен (для всех товаров или по условию). Нужен при смене групп покупателей
     * и по ночному cron: акции и скидки включаются и выключаются по датам.
     *
     * @param string $where условие на алиас p (oc_product)
     */
    public function writePrices($where = '1')
    {
        // В транзакции: иначе между DELETE и INSERT витрина видела бы пустую таблицу цен (ночной cron, смена групп).
        // Вызывать только вне транзакции: внутри indexProducts — pricesSql() напрямую.
        $db = $this->k->db();
        $db->query("START TRANSACTION");
        try {
            $this->pricesSql($where);
            $db->query("COMMIT");
        } catch (\Exception $e) {
            $db->query("ROLLBACK");
            throw $e;
        }
        if ($where === '1') {
            $this->k->touched(null); // цены всех товаров — в Manticore целиком
        }
    }

    /**
     * Пересчитывает только привязки товаров к категориям (с предками) — после правки дерева категорий.
     * То же, что шаг 2 indexProducts, без перечитывания остального.
     *
     * @param int[] $ids
     */
    public function rebindCategories(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $db = $this->k->db();
        $P = $this->k->prefix();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', $chunk);
            $this->k->cache()->invalidateProducts($chunk);
            $db->query("DELETE FROM `" . $this->k->table('category_product') . "` WHERE product_id IN ($in)");
            $db->query("INSERT INTO `" . $this->k->table('category_product') . "` (category_id, product_id, is_direct)
                SELECT cp.path_id, p2c.product_id, MAX(IF(cp.path_id = p2c.category_id, 1, 0))
                FROM `{$P}product_to_category` p2c
                JOIN `{$P}category_path` cp ON cp.category_id = p2c.category_id
                JOIN `" . $this->k->table('product') . "` fp ON fp.product_id = p2c.product_id
                WHERE p2c.product_id IN ($in)
                GROUP BY cp.path_id, p2c.product_id");
            $this->k->cache()->invalidateProducts($chunk);
            $this->k->touched($chunk);
        }
    }

    /** @param string $where условие на алиас p (oc_product) */
    private function pricesSql($where)
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        $now = "'" . date('Y-m-d') . "'";
        $db->query("DELETE pr FROM `" . $this->k->table('price') . "` pr JOIN `{$P}product` p ON p.product_id = pr.product_id WHERE " . $where);
        if (Platform::oc4()) {
            // OC4: акции и скидки в одной product_discount (special = 1 — акция), скидка бывает
            // фиксированной ценой (F), процентом (P) или вычитаемой суммой (S) — как в catalog/model/catalog/product.
            $deal = function ($special) use ($P, $now) {
                return "(SELECT CASE WHEN d.type = 'P' THEN p.price - p.price * d.price / 100 WHEN d.type = 'S' THEN p.price - d.price ELSE d.price END
                    FROM `{$P}product_discount` d
                    WHERE d.product_id = p.product_id AND d.customer_group_id = cg.customer_group_id AND d.quantity = 1 AND d.special = " . $special . "
                      AND (d.date_start = '0000-00-00' OR d.date_start <= $now) AND (d.date_end = '0000-00-00' OR d.date_end > $now)
                    ORDER BY d.priority ASC, d.price ASC LIMIT 1)";
            };
            $db->query("INSERT INTO `" . $this->k->table('price') . "` (product_id, customer_group_id, price, base_price)
                SELECT p.product_id, cg.customer_group_id, COALESCE(" . $deal(1) . ", " . $deal(0) . ", p.price), p.price
                FROM `{$P}product` p CROSS JOIN `{$P}customer_group` cg
                WHERE " . $where);
            return;
        }
        $db->query("INSERT INTO `" . $this->k->table('price') . "` (product_id, customer_group_id, price, base_price)
            SELECT p.product_id, cg.customer_group_id,
                COALESCE(
                    (SELECT ps.price FROM `{$P}product_special` ps
                      WHERE ps.product_id = p.product_id AND ps.customer_group_id = cg.customer_group_id
                        AND (ps.date_start = '0000-00-00' OR ps.date_start <= $now)
                        AND (ps.date_end = '0000-00-00' OR ps.date_end > $now)
                      ORDER BY ps.priority ASC, ps.price ASC LIMIT 1),
                    (SELECT pd.price FROM `{$P}product_discount` pd
                      WHERE pd.product_id = p.product_id AND pd.customer_group_id = cg.customer_group_id AND pd.quantity = 1
                        AND (pd.date_start = '0000-00-00' OR pd.date_start <= $now)
                        AND (pd.date_end = '0000-00-00' OR pd.date_end > $now)
                      ORDER BY pd.priority ASC, pd.price ASC LIMIT 1),
                    p.price),
                p.price
            FROM `{$P}product` p CROSS JOIN `{$P}customer_group` cg
            WHERE " . $where);
    }

    /**
     * Удаляет товары из индекса полностью.
     *
     * @param int[] $ids
     */
    public function deleteProducts(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $in = implode(',', $ids);
        foreach (['index', 'index_range', 'category_product', 'price', 'product'] as $t) {
            $this->k->db()->query("DELETE FROM `" . $this->k->table($t) . "` WHERE product_id IN ($in)");
        }
        $this->k->touched($ids);
    }

    /**
     * Догон изменений в обход админки (импорт, правка БД): переиндексирует товары, которых нет
     * в индексе или у которых oc_product.date_modified не совпадает с запомненным при индексации
     * (сравнение на равенство не зависит от часового пояса импорта), и убирает удаленные.
     * Во время полной переиндексации ничего не делает — она и так все перепишет.
     *
     * @param float $timeLimit секунд
     * @return array indexed, deleted, more (остались товары на следующий запуск)
     */
    public function reconcile($timeLimit = 50.0)
    {
        $out = ['indexed' => 0, 'deleted' => 0, 'more' => false];
        // Идущая полная переиндексация все перепишет сама; брошенный job (без шагов дольше STALE_MINUTES) не мешает.
        if ($this->k->job()->running(true)) {
            return $out;
        }
        $db = $this->k->db();
        $P = $this->k->prefix();
        $FP = '`' . $this->k->table('product') . '`';
        $started = microtime(true);
        $batch = max(50, (int)$this->k->settings()->get('batch_size', 500));

        $gone = array_column($db->query("SELECT fp.product_id FROM " . $FP . " fp LEFT JOIN `{$P}product` p ON p.product_id = fp.product_id WHERE p.product_id IS NULL")->rows, 'product_id');
        $indexed = (int)$db->query("SELECT COUNT(*) AS c FROM " . $FP)->row['c'];
        if (count($gone) > 100 && count($gone) > $indexed * 0.5) {
            // Исчезла большая часть каталога — скорее всего, обмен опустошил oc_product на время (TRUNCATE + INSERT).
            // Индекс не трогаем: следующий прогон либо найдет товары на месте, либо подтвердит удаление.
            $this->k->log('reconcile: в oc_product нет ' . count($gone) . ' из ' . $indexed . ' товаров индекса — удаление отложено до следующего прогона');
            $gone = [];
            $out['skipped_delete'] = true;
        }
        foreach (array_chunk($gone, 1000) as $chunk) {
            $this->k->cache()->invalidateProducts($chunk);
            $this->deleteProducts($chunk);
        }
        $out['deleted'] = count($gone);

        $lastId = 0;
        while (true) {
            if (microtime(true) - $started > $timeLimit) {
                $out['more'] = true;
                break;
            }
            // Курсор по id: товар, который не удалось проиндексировать, не зациклит прогон.
            // Кроме date_modified сверяются поля, которые индекс и так хранит: правка в обход админки
            // (прямой SQL, обмен без date_modified) — выключенный товар, остаток, магазин — тоже подхватывается.
            $ids = array_column($db->query("SELECT p.product_id FROM `{$P}product` p LEFT JOIN " . $FP . " fp ON fp.product_id = p.product_id
                WHERE p.product_id > " . $lastId . " AND (fp.product_id IS NULL OR NOT (" . self::SRC_MODIFIED . " <=> fp.src_modified)
                    OR IF(p.status = 1, 1, 0) <> fp.active OR p.quantity <> fp.quantity OR p.stock_status_id <> fp.stock_status_id
                    OR p.manufacturer_id <> fp.manufacturer_id
                    " . ($this->k->groupField() !== '' ? "OR " . $this->groupExpr('p') . " <> fp.grp" : "") . "
                    OR IF(p.date_available < '1000-01-01', '1970-01-01', DATE(p.date_available)) <> fp.date_available
                    OR COALESCE((SELECT BIT_OR(1 << LEAST(ps.store_id, 31)) FROM `{$P}product_to_store` ps WHERE ps.product_id = p.product_id), 0) <> fp.store_mask)
                ORDER BY p.product_id LIMIT " . $batch)->rows, 'product_id');
            if (!$ids) {
                break;
            }
            $this->k->cache()->invalidateProducts($ids); // старые категории и бренд
            // Поколение — на каждую пачку: job, начатый во время прогона, не удалит в cleanup товары, записанные старым.
            $this->indexProducts($ids, $this->k->job()->currentGeneration());
            $this->k->cache()->invalidateProducts($ids); // новые
            $out['indexed'] += count($ids);
            $lastId = (int)end($ids);
        }
        if ($out['indexed'] || $out['deleted']) {
            $this->k->job()->recountValues();
            $this->k->facets()->invalidate();
        }
        // Просмотры (сортировка «Популярные») меняются у каждого открытого товара: переносим одним UPDATE,
        // без переиндексации и сброса кеша — на счетчики фасетов они не влияют, а страница выдачи не кешируется.
        if (!Platform::oc4() && microtime(true) - $started < $timeLimit) {
            $viewed = array_column($db->query("SELECT fp.product_id FROM " . $FP . " fp JOIN `{$P}product` p ON p.product_id = fp.product_id
                WHERE fp.viewed <> p.viewed LIMIT 100000")->rows, 'product_id');
            foreach (array_chunk($viewed, 1000) as $chunk) {
                $db->query("UPDATE " . $FP . " fp JOIN `{$P}product` p ON p.product_id = fp.product_id SET fp.viewed = p.viewed
                    WHERE fp.product_id IN (" . implode(',', $chunk) . ")");
                $this->k->touched($chunk); // Manticore хранит viewed в документе
            }
            $out['viewed'] = count($viewed);
        }
        return $out;
    }

    /** Сбрасывает закешированные определения фасетов (после их правки). */
    public function resetFacets()
    {
        $this->facets = null;
    }

    /**
     * @param int[] $ids
     * @param int   $gen
     * @return array
     */
    private function writeFacetRows(array $ids, $gen)
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        $in = implode(',', $ids);
        $facets = $this->facets();

        // Строки не-custom фасетов удаляем целиком: у товара могли пропасть значения.
        $db->query("DELETE FROM `" . $this->k->table('index') . "` WHERE product_id IN ($in) AND generation > 0");
        $db->query("DELETE FROM `" . $this->k->table('index_range') . "` WHERE product_id IN ($in) AND generation > 0");

        $rows = [];   // "facet,value,product,gen"
        $ranges = []; // "facet,product,value,gen"
        $this->values->load(array_keys($facets['by_id']));
        $defaultLang = $this->k->defaultLanguageId();
        $separators = (array)$this->k->settings()->get('separators');

        // --- Атрибуты
        if (!empty($facets['attribute'])) {
            $q = $db->query("SELECT product_id, attribute_id, language_id, `text` FROM `{$P}product_attribute`
                WHERE product_id IN ($in) AND attribute_id IN (" . implode(',', array_keys($facets['attribute'])) . ")");
            $texts = [];
            foreach ($q->rows as $r) {
                $texts[(int)$r['product_id']][(int)$r['attribute_id']][(int)$r['language_id']] = $r['text'];
            }
            foreach ($texts as $productId => $byAttr) {
                foreach ($byAttr as $attrId => $byLang) {
                    $base = isset($byLang[$defaultLang]) ? $byLang[$defaultLang] : reset($byLang);
                    foreach ($facets['attribute'][$attrId] as $facet) {
                        $seps = isset($facet['settings']['separators']) ? (array)$facet['settings']['separators'] : $separators;
                        $parts = Normalizer::split($base, $seps);
                        if ((int)$facet['kind'] === 1) {
                            $mult = isset($facet['settings']['unit_multiplier']) ? (float)$facet['settings']['unit_multiplier'] : 1.0;
                            foreach ($parts as $part) {
                                $n = Normalizer::number($part);
                                if ($n !== null) {
                                    $ranges[] = $facet['facet_id'] . ',' . $productId . ',' . round($n * ($mult ?: 1), 4) . ',' . $gen;
                                }
                            }
                            continue;
                        }
                        $translated = [];
                        foreach ($byLang as $lang => $text) {
                            $translated[$lang] = $lang === $defaultLang ? $parts : Normalizer::split($text, $seps);
                        }
                        foreach ($parts as $pos => $part) {
                            $names = [];
                            foreach ($translated as $lang => $langParts) {
                                // Переводы сопоставляются по позиции части; если частей не столько же — берем основной язык.
                                $names[$lang] = (count($langParts) === count($parts) && isset($langParts[$pos])) ? $langParts[$pos] : $part;
                            }
                            $valueId = $this->values->resolve($facet['facet_id'], Normalizer::key($part), $names, ['numeric_value' => Normalizer::number($part)]);
                            $rows[] = $facet['facet_id'] . ',' . $valueId . ',' . $productId . ',' . $gen;
                        }
                    }
                }
            }
        }

        // --- Опции
        if (!empty($facets['option'])) {
            $q = $db->query("SELECT pov.product_id, pov.option_id, pov.option_value_id, pov.quantity, ov.sort_order, ov.image
                FROM `{$P}product_option_value` pov
                LEFT JOIN `{$P}option_value` ov ON ov.option_value_id = pov.option_value_id
                WHERE pov.product_id IN ($in) AND pov.option_id IN (" . implode(',', array_keys($facets['option'])) . ")");
            $names = $this->names("SELECT option_value_id AS id, language_id, name FROM `{$P}option_value_description` WHERE option_value_id IN (%s)", array_column($q->rows, 'option_value_id'));
            foreach ($q->rows as $r) {
                foreach ($facets['option'][(int)$r['option_id']] as $facet) {
                    if (!empty($facet['settings']['in_stock_only']) && (int)$r['quantity'] <= 0) {
                        continue;
                    }
                    $id = (int)$r['option_value_id'];
                    $valueId = $this->values->resolve($facet['facet_id'], (string)$id, isset($names[$id]) ? $names[$id] : [$defaultLang => '#' . $id], [
                        'sort_order' => (int)$r['sort_order'], 'image' => (string)$r['image'],
                    ]);
                    $rows[] = $facet['facet_id'] . ',' . $valueId . ',' . (int)$r['product_id'] . ',' . $gen;
                }
            }
        }

        // --- Штатные фильтры OpenCart (группы фильтров)
        if (!empty($facets['oc_filter'])) {
            $q = $db->query("SELECT pf.product_id, f.filter_group_id, f.filter_id, f.sort_order
                FROM `{$P}product_filter` pf JOIN `{$P}filter` f ON f.filter_id = pf.filter_id
                WHERE pf.product_id IN ($in) AND f.filter_group_id IN (" . implode(',', array_keys($facets['oc_filter'])) . ")");
            $names = $this->names("SELECT filter_id AS id, language_id, name FROM `{$P}filter_description` WHERE filter_id IN (%s)", array_column($q->rows, 'filter_id'));
            foreach ($q->rows as $r) {
                foreach ($facets['oc_filter'][(int)$r['filter_group_id']] as $facet) {
                    $id = (int)$r['filter_id'];
                    $valueId = $this->values->resolve($facet['facet_id'], (string)$id, isset($names[$id]) ? $names[$id] : [$defaultLang => '#' . $id], ['sort_order' => (int)$r['sort_order']]);
                    $rows[] = $facet['facet_id'] . ',' . $valueId . ',' . (int)$r['product_id'] . ',' . $gen;
                }
            }
        }

        // --- Производитель (одна и та же модель фасета, что и у атрибутов: EXISTS по индексу)
        if (!empty($facets['manufacturer'])) {
            $q = $db->query("SELECT p.product_id, m.manufacturer_id, m.name, m.sort_order, m.image
                FROM `{$P}product` p JOIN `{$P}manufacturer` m ON m.manufacturer_id = p.manufacturer_id
                WHERE p.product_id IN ($in)");
            foreach ($q->rows as $r) {
                foreach ($facets['manufacturer'][0] as $facet) {
                    $names = [];
                    foreach ($this->languages() as $lang) {
                        $names[$lang] = Normalizer::clean($r['name']); // OpenCart хранит название экранированным
                    }
                    $valueId = $this->values->resolve($facet['facet_id'], (string)(int)$r['manufacturer_id'], $names, [
                        'sort_order' => (int)$r['sort_order'], 'image' => (string)$r['image'],
                    ]);
                    $rows[] = $facet['facet_id'] . ',' . $valueId . ',' . (int)$r['product_id'] . ',' . $gen;
                }
            }
        }

        // --- Поля товара (вес, габариты) — диапазоны
        if (!empty($facets['product_field'])) {
            $q = $db->query("SELECT product_id, weight, length, width, height FROM `{$P}product` WHERE product_id IN ($in)");
            foreach ($q->rows as $r) {
                foreach ($facets['product_field'][0] as $facet) {
                    $col = isset($facet['settings']['column']) ? $facet['settings']['column'] : 'weight';
                    if (isset($r[$col]) && (float)$r[$col] > 0) {
                        $ranges[] = $facet['facet_id'] . ',' . (int)$r['product_id'] . ',' . round((float)$r[$col], 4) . ',' . $gen;
                    }
                }
            }
        }

        $this->insertChunks('index', '(facet_id, value_id, product_id, generation)', $rows);
        $this->insertChunks('index_range', '(facet_id, product_id, value, generation)', $ranges);

        return ['rows' => count($rows), 'ranges' => count($ranges)];
    }

    /**
     * Активные фасеты, сгруппированные по источнику и ref_id.
     *
     * @return array
     */
    private function facets()
    {
        if ($this->facets !== null) {
            return $this->facets;
        }
        $out = ['by_id' => []];
        $q = $this->k->db()->query("SELECT facet_id, source, ref_id, kind, settings FROM `" . $this->k->table('facet') . "` WHERE status = 1 AND source IN ('" . implode("','", self::INDEXED_SOURCES) . "')");
        foreach ($q->rows as $row) {
            $settings = json_decode($row['settings'], true);
            $facet = [
                'facet_id' => (int)$row['facet_id'],
                'kind'     => (int)$row['kind'],
                'settings' => is_array($settings) ? $settings : [],
            ];
            $ref = in_array($row['source'], ['manufacturer', 'product_field'], true) ? 0 : (int)$row['ref_id'];
            $out[$row['source']][$ref][] = $facet;
            $out['by_id'][$facet['facet_id']] = $facet;
        }
        return $this->facets = $out;
    }

    /** @return int[] */
    private function languages()
    {
        if ($this->languages === null) {
            $this->languages = array_map('intval', array_column($this->k->db()->query("SELECT language_id FROM `" . $this->k->prefix() . "language`")->rows, 'language_id'));
        }
        return $this->languages;
    }

    /**
     * Названия по языкам: id => [language_id => name].
     *
     * @param string $sql с плейсхолдером %s для списка id
     * @param array  $ids
     * @return array
     */
    private function names($sql, array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->k->db()->query(sprintf($sql, implode(',', $ids)))->rows as $r) {
            // Названия в OpenCart лежат экранированными (Request::clean); в индексе — текст, экранирует шаблон.
            $out[(int)$r['id']][(int)$r['language_id']] = Normalizer::clean($r['name']);
        }
        return $out;
    }

    /**
     * Пакетная вставка строк индекса по 1000.
     *
     * @param string   $table
     * @param string   $cols
     * @param string[] $rows
     */
    private function insertChunks($table, $cols, array $rows)
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->k->db()->query("INSERT IGNORE INTO `" . $this->k->table($table) . "` " . $cols . " VALUES (" . implode('),(', $chunk) . ")");
        }
    }
}
