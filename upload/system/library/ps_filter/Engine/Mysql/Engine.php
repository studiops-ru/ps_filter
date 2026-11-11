<?php
namespace PsFilter\Engine\Mysql;

use PsFilter\Context;
use PsFilter\State;

/**
 * Выборка на MySQL.
 *
 * Идея — считать пропорционально числу ПОДХОДЯЩИХ товаров, а не размеру раздела:
 *   M_F    — для каждого выбранного фасета F множество товаров раздела, подходящих под F.
 *            Для списков и диапазонов строится прямо по индексу значений (PK facet_id, value_id),
 *            то есть читаются только строки выбранных значений;
 *   S_full — пересечение всех M_F (JOIN по product_id, PRIMARY KEY в MEMORY-таблицах);
 *   счетчики невыбранных фасетов — один GROUP BY по S_full ⋈ index (покрывающий idx_product);
 *   счетчики выбранного F — по пересечению остальных M_G; при одном выбранном фасете это
 *            просто статистика раздела без фильтра, которая уже в кеше.
 * Статистика раздела без фильтра (что вообще есть на странице) кешируется отдельно и
 * обслуживает «пустую» страницу без запросов к индексу.
 *
 * Временные MEMORY-таблицы живут до конца соединения; если хостинг их запрещает
 * (set_strategy = derived), те же множества подставляются подзапросами.
 *
 * Группировка выдачи (group_by, 0.7.0): множества остаются множествами товаров, а считается по группам —
 * COUNT(DISTINCT grp) вместо COUNT(*); страница — по представителям групп (reps): товар в наличии с меньшей
 * ценой из подходящих под фильтр (выбран размер — товар этого размера).
 */
class Engine extends \PsFilter\Engine\Base
{
    /** @var int счетчик имен временных таблиц: вложенные запросы в одном соединении не должны конфликтовать */
    private static $seq = 0;
    /** @var array ключ множества => SQL-источник (временная таблица или derived-подзапрос); живет весь запрос */
    private $sets = [];

    /** @return string ключи кеша MySQL-движка — без префикса (как до появления Manticore); при группировке — свой */
    protected function cachePrefix()
    {
        $g = $this->k->groupField();
        return $g === '' ? "" : 'g:' . $g . '|';
    }

    /** @return bool выдача группируется (group_by) */
    private function grouped()
    {
        return $this->k->groupField() !== '';
    }

    /**
     * Что считать по множеству s: товары или группы. Для групп к множеству присоединяется ps_filter_product gf.
     *
     * @param string $alias таблица, по которой считались товары без группировки (s — само множество)
     * @return array [string $countExpr, string $join]
     */
    private function counter($alias)
    {
        if (!$this->grouped()) {
            return [$alias === 's' ? 'COUNT(*)' : 'COUNT(DISTINCT ' . $alias . '.product_id)', ''];
        }
        return ['COUNT(DISTINCT gf.grp)', " JOIN `" . $this->k->table('product') . "` gf ON gf.product_id = s.product_id"];
    }

    /**
     * Удаляет временные таблицы запроса. Нужно в длинных пакетных проходах (посадочные по всем
     * разделам), где за один процесс создаются тысячи множеств; на витрине их уберет закрытие соединения.
     */
    public function release()
    {
        foreach ($this->sets as $source) {
            if ($source !== '' && $source[0] === '`') {
                $this->k->db()->query("DROP TEMPORARY TABLE IF EXISTS " . $source);
            }
        }
        $this->sets = [];
    }

    /* ===================== Статистика ===================== */

    /**
     * Раздел без фильтров: число товаров, значения с количеством, диапазоны.
     *
     * @return array
     */
    protected function baseStats(Context $ctx, array $defs)
    {
        $db = $this->k->db();
        $s0 = $this->s0($ctx);
        $out = ['total' => 0, 'counts' => [], 'ranges' => [], 'coverage' => []];
        list($cnt, $join) = $this->counter('s');
        $out['total'] = (int)$db->query("SELECT " . $cnt . " AS c FROM " . $s0 . " s" . $join)->row['c'];
        if (!$out['total']) {
            return $out;
        }
        $groups = $this->groups($defs);
        $this->countLists($out, $s0, $groups['list']);
        // Автоподбор характеристик в разделе: доля товаров с хотя бы одним значением фасета. Сумма по значениям
        // завышает ее у характеристик с несколькими значениями на товар, поэтому — отдельный COUNT(DISTINCT).
        if ($groups['list'] && (int)$this->k->settings()->get('category_auto_facets', 0) > 0) {
            list($cov, $join) = $this->counter('i');
            $q = $db->query("SELECT i.facet_id, " . $cov . " AS c FROM " . $s0 . " s
                JOIN `" . $this->k->table('index') . "` i ON i.product_id = s.product_id" . $join . "
                WHERE i.facet_id IN (" . implode(',', array_map('intval', $groups['list'])) . ")
                GROUP BY i.facet_id");
            foreach ($q->rows as $r) {
                $out['coverage'][(int)$r['facet_id']] = (int)$r['c'];
            }
        }
        foreach ($groups['computed'] as $fid) {
            $out['counts'][$fid] = $this->computedCounts($s0, $defs[$fid]);
        }
        $this->rangeLimits($out, $s0, $groups['range']);
        foreach ($groups['price'] as $fid) {
            $range = $this->priceRange($ctx, $s0);
            if ($range) {
                $out['ranges'][$fid] = $range;
            }
        }
        return $out;
    }

    /**
     * Статистика с учетом фильтра.
     *
     * @return array
     */
    protected function filteredStats(Context $ctx, State $state, array $defs, array $base)
    {
        $db = $this->k->db();
        $sf = $this->sf($ctx, $state, $defs, (int)$base['total']);
        $out = ['total' => 0, 'counts' => [], 'ranges' => []];
        list($cnt, $join) = $this->counter('s');
        $out['total'] = (int)$db->query("SELECT " . $cnt . " AS c FROM " . $sf . " s" . $join)->row['c'];

        // Невыбранные фасеты, которые есть в разделе (GROUP BY по ним — основная стоимость клика).
        $unselected = $this->unselected($state, $defs, $base);
        if ($out['total'] > 0) {
            $this->countLists($out, $sf, $unselected['list']);
            foreach ($unselected['computed'] as $fid) {
                $out['counts'][$fid] = $this->computedCounts($sf, $defs[$fid]);
            }
            $this->rangeLimits($out, $sf, $unselected['range']);
            foreach ($unselected['price'] as $fid) {
                $range = $this->priceRange($ctx, $sf);
                if ($range) {
                    $out['ranges'][$fid] = $range;
                }
            }
        }

        // Выбранные фасеты: по пересечению остальных выбранных. Один выбранный — это статистика раздела.
        list($recount, $fromBase) = $this->selectedPlan($state, $base);
        foreach ($fromBase as $fid) {
            $this->copyFromBase($out, $base, $fid);
        }
        foreach ($recount as $fid => $others) {
            $set = $this->intersection($ctx, $others, $defs, (int)$base['total']);
            $facet = $defs[$fid];
            switch ($this->type($facet)) {
                case 'list':
                    $tmp = ['counts' => []];
                    $this->countLists($tmp, $set, [$fid]);
                    $out['counts'][$fid] = isset($tmp['counts'][$fid]) ? $tmp['counts'][$fid] : [];
                    break;
                case 'computed':
                    $out['counts'][$fid] = $this->computedCounts($set, $facet);
                    break;
                case 'range':
                    $tmp = ['ranges' => []];
                    $this->rangeLimits($tmp, $set, [$fid]);
                    if (isset($tmp['ranges'][$fid])) {
                        $out['ranges'][$fid] = $tmp['ranges'][$fid];
                    }
                    break;
                case 'price':
                    $range = $this->priceRange($ctx, $set);
                    if ($range) {
                        $out['ranges'][$fid] = $range;
                    }
                    break;
            }
        }
        return $out;
    }

    /** Счетчики значений дискретных фасетов по множеству: один GROUP BY. */
    private function countLists(array &$out, $set, array $facetIds)
    {
        if (!$facetIds) {
            return;
        }
        list($cnt, $join) = $this->counter('s');
        $q = $this->k->db()->query("SELECT i.facet_id, i.value_id, " . $cnt . " AS c FROM " . $set . " s
            JOIN `" . $this->k->table('index') . "` i ON i.product_id = s.product_id" . $join . "
            WHERE i.facet_id IN (" . implode(',', array_map('intval', $facetIds)) . ")
            GROUP BY i.facet_id, i.value_id");
        foreach ($q->rows as $r) {
            $out['counts'][(int)$r['facet_id']][(int)$r['value_id']] = (int)$r['c'];
        }
    }

    /** MIN/MAX числовых фасетов по множеству. */
    private function rangeLimits(array &$out, $set, array $facetIds)
    {
        if (!$facetIds) {
            return;
        }
        // Число товаров с значением — для автоподбора фасетов раздела (доля товаров с полем), тем же проходом.
        list($cov, $join) = $this->counter('r');
        $q = $this->k->db()->query("SELECT r.facet_id, MIN(r.value) AS mn, MAX(r.value) AS mx, " . $cov . " AS c FROM " . $set . " s
            JOIN `" . $this->k->table('index_range') . "` r ON r.product_id = s.product_id" . $join . "
            WHERE r.facet_id IN (" . implode(',', array_map('intval', $facetIds)) . ")
            GROUP BY r.facet_id");
        foreach ($q->rows as $r) {
            $out['ranges'][(int)$r['facet_id']] = [(float)$r['mn'], (float)$r['mx']];
            $out['coverage'][(int)$r['facet_id']] = (int)$r['c'];
        }
    }

    /**
     * Счетчики вычисляемого фасета (наличие).
     *
     * @return array value_id => count
     */
    private function computedCounts($set, array $facet)
    {
        $expr = $this->computedExpr($facet);
        if ($expr === null) {
            return [];
        }
        $cnt = $this->grouped() ? 'COUNT(DISTINCT f.grp)' : 'COUNT(*)';
        $q = $this->k->db()->query("SELECT " . $expr . " AS v, " . $cnt . " AS c FROM " . $set . " s
            JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id GROUP BY v");
        $out = [];
        foreach ($q->rows as $r) {
            $out[(int)$r['v']] = (int)$r['c'];
        }
        return $out;
    }

    /** @return array|null [min, max] цены (с налогом) */
    private function priceRange(Context $ctx, $set)
    {
        $expr = $this->priceExpr($ctx);
        $r = $this->k->db()->query("SELECT MIN(" . $expr . ") AS mn, MAX(" . $expr . ") AS mx FROM " . $set . " s
            JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id" . $this->priceJoin($ctx))->row;
        return $r['mn'] === null ? null : [(float)$r['mn'], (float)$r['mx']];
    }

    /* ===================== Список товаров ===================== */

    /**
     * id товаров страницы в порядке сортировки витрины.
     * При равенстве — по названию от А до Я при любом направлении основной сортировки (штатный getProducts
     * берет направление основной, и «Популярные» по убыванию шли от Я до А), у «Популярных» и просмотров —
     * сначала новинки. Ключ названия лежит в ps_filter_product, соединение с product_description не нужно.
     * «Популярные» (sort=popular) — по продажам из ps_filter_sold или по просмотрам: настройка popular_by.
     *
     * @return int[]
     */
    protected function pageIds(Context $ctx, State $state, array $defs, array $listing, $baseTotal)
    {
        $dir = isset($listing['order']) && strtoupper($listing['order']) === 'DESC' ? 'DESC' : 'ASC';
        $sort = isset($listing['sort']) ? $listing['sort'] : 'p.sort_order';
        $start = max(0, (int)(isset($listing['start']) ? $listing['start'] : 0));
        $limit = max(1, (int)(isset($listing['limit']) ? $listing['limit'] : 20));
        $P = $this->k->prefix();

        if ($this->grouped()) {
            // Страница — по представителям групп; сортировка — по их цене, названию, дате.
            $source = $state->isEmpty() ? $this->s0($ctx) : $this->sf($ctx, $state, $defs, $baseTotal);
            $sql = "SELECT s.product_id FROM " . $this->reps($ctx, $source, $defs) . " s JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id";
            $tail = '';
        } elseif ($state->isEmpty()) {
            list($from, $where) = $this->contextSql($ctx, 'f');
            $sql = "SELECT f.product_id FROM " . $from;
            $tail = " WHERE " . $where;
        } else {
            $sql = "SELECT s.product_id FROM " . $this->sf($ctx, $state, $defs, $baseTotal) . " s JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id";
            $tail = '';
        }
        if ($ctx->type === Context::SEARCH && $ctx->searchRank && $sort === 'p.sort_order') {
            // Сортировка по умолчанию на поиске — порядок релевантности ps_search: позиция в его наборе,
            // а для временного набора (роботы, превышение лимита) — готовый список id.
            if (!empty($ctx->searchRank['ids'])) {
                $order = "FIELD(f.product_id, " . implode(',', array_map('intval', $ctx->searchRank['ids'])) . ") " . $dir;
            } else {
                $sql .= " JOIN `" . $ctx->searchRank['table'] . "` rk ON rk.set_id = " . (int)$ctx->searchRank['set'] . " AND rk.product_id = f.product_id";
                $order = "rk.pos " . $dir;
            }
            $q = $this->k->db()->query($sql . $tail . " ORDER BY " . $order . ", f.product_id ASC LIMIT " . $start . ", " . $limit);
            return array_map('intval', array_column($q->rows, 'product_id'));
        }
        $tie = "f.name_sort ASC, f.product_id ASC";
        switch ($sort) {
            case 'pd.name':
                $order = "f.name_sort $dir";
                $tie = "f.product_id ASC";
                break;
            case 'popular':
                if ($this->k->sales()->enabled() && $this->grouped()) {
                    // Продажи модели — сумма по всем ее товарам (размерам), а не по представителю.
                    $sql .= " LEFT JOIN (SELECT g.grp, SUM(sg.sold) AS sold FROM `" . $this->k->table('sold') . "` sg
                        JOIN `" . $this->k->table('product') . "` g ON g.product_id = sg.product_id GROUP BY g.grp) so ON so.grp = f.grp";
                    $order = "COALESCE(so.sold, 0) $dir";
                } elseif ($this->k->sales()->enabled()) {
                    $sql .= " LEFT JOIN `" . $this->k->table('sold') . "` so ON so.product_id = f.product_id";
                    $order = "COALESCE(so.sold, 0) $dir";
                } else {
                    $order = "f.viewed $dir";
                }
                $tie = "f.date_added DESC, f.name_sort ASC, f.product_id ASC";
                break;
            case 'p.price':
                $sql .= " LEFT JOIN `" . $this->k->table('price') . "` pr ON pr.product_id = f.product_id AND pr.customer_group_id = " . (int)$ctx->customerGroupId;
                $order = "pr.price $dir";
                break;
            case 'rating':
                $order = "f.rating $dir";
                break;
            case 'p.model':
                $sql .= " LEFT JOIN `{$P}product` p ON p.product_id = f.product_id";
                $order = "LCASE(p.model) $dir";
                break;
            case 'p.quantity':
                $order = "f.quantity $dir";
                break;
            case 'p.date_added':
                $order = "f.date_added $dir";
                break;
            case 'p.viewed':
                // Не из штатного списка OpenCart: просмотры явно (тема так просит), независимо от popular_by.
                $order = "f.viewed $dir";
                $tie = "f.date_added DESC, f.name_sort ASC, f.product_id ASC";
                break;
            default:
                $order = "f.sort_order $dir";
        }
        $q = $this->k->db()->query($sql . $tail . " ORDER BY " . $order . ", " . $tie . " LIMIT " . $start . ", " . $limit);
        return array_map('intval', array_column($q->rows, 'product_id'));
    }

    /* ===================== Множества ===================== */

    /**
     * Представители групп множества: по одному товару на группу — в наличии, с меньшей ценой, при равенстве
     * с меньшим id. Без оконных функций (MySQL 5.7): первый id из упорядоченного GROUP_CONCAT.
     *
     * @param string $set SQL-источник множества товаров
     * @return string SQL-источник (product_id)
     */
    private function reps(Context $ctx, $set, array $defs)
    {
        $key = 'g|' . (int)$ctx->customerGroupId . '|' . $set;
        if (!isset($this->sets[$key])) {
            // «В наличии» — как у фасета наличия магазина (по остатку или по статусам), без фасета — остаток.
            $instock = '(f.quantity > 0)';
            foreach ($defs as $facet) {
                if ($facet['source'] === 'stock') {
                    $instock = '(' . \PsFilter\Stock::sql($facet['settings'], 'f') . ' = ' . \PsFilter\Stock::IN_STOCK . ')';
                    break;
                }
            }
            $this->k->db()->query("SET SESSION group_concat_max_len = 1048576");
            $this->sets[$key] = $this->materialize("SELECT CAST(SUBSTRING_INDEX(GROUP_CONCAT(f.product_id
                    ORDER BY " . $instock . " DESC, COALESCE(pr.price, 0) ASC, f.product_id ASC), ',', 1) AS UNSIGNED) AS product_id
                FROM " . $set . " s JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id" . $this->priceJoin($ctx) . "
                GROUP BY f.grp");
        }
        return $this->sets[$key];
    }

    /**
     * Раздел как FROM + WHERE для алиаса ps_filter_product.
     *
     * @return array [string $from, string $where]
     */
    private function contextSql(Context $ctx, $alias = 'f')
    {
        $where = [$alias . ".active = 1", $alias . ".date_available <= CURDATE()", "(" . $alias . ".store_mask & " . (1 << min(31, (int)$ctx->storeId)) . ") <> 0"];
        if ($ctx->onlyInstock) {
            $where[] = $alias . ".quantity > 0";
        }
        if ($ctx->type === Context::MANUFACTURER) {
            $where[] = $alias . ".manufacturer_id = " . (int)$ctx->id;
            // Исключенные разделы (с подразделами: в category_product у товара есть строки всех предков).
            if ($ctx->excludeCategories) {
                $where[] = "NOT EXISTS (SELECT 1 FROM `" . $this->k->table('category_product') . "` cx WHERE cx.product_id = " . $alias . ".product_id
                    AND cx.category_id IN (" . implode(',', array_map('intval', $ctx->excludeCategories)) . "))";
            }
            return ["`" . $this->k->table('product') . "` " . $alias, implode(' AND ', $where)];
        }
        if ($ctx->type === Context::SEARCH) {
            // Найденное штатным поиском (LIKE по названию и т.д.) — это и есть раздел; дальше все как у категории.
            $from = "(" . $ctx->searchSql . ") sx STRAIGHT_JOIN `" . $this->k->table('product') . "` " . $alias . " ON " . $alias . ".product_id = sx.product_id";
            return [$from, implode(' AND ', $where)];
        }
        // STRAIGHT_JOIN: без него оптимизатор читает весь ps_filter_product (десятки тысяч строк)
        // вместо диапазона PK (category_id, product_id) — в 2–5 раз медленнее на больших разделах.
        $from = "`" . $this->k->table('category_product') . "` c STRAIGHT_JOIN `" . $this->k->table('product') . "` " . $alias . " ON " . $alias . ".product_id = c.product_id";
        $where[] = "c.category_id = " . (int)$ctx->id;
        if (!$ctx->subtree) {
            $where[] = "c.is_direct = 1";
        }
        return [$from, implode(' AND ', $where)];
    }

    /** S0: раздел без фильтров. Строится один раз на запрос с фильтром, от него идут проверки фасетов. */
    private function s0(Context $ctx)
    {
        $key = 's0|' . $ctx->key();
        if (!isset($this->sets[$key])) {
            list($from, $where) = $this->contextSql($ctx, 'f');
            $this->sets[$key] = $this->materialize("SELECT f.product_id FROM " . $from . " WHERE " . $where);
        }
        return $this->sets[$key];
    }

    /**
     * M_F: товары раздела, подходящие под выбор одного фасета.
     *
     * Путь выбирается по оценке: редкие значения (суммарно товаров во всем каталоге меньше,
     * чем в разделе) читаются по индексу значений — PK (facet_id, value_id) отдает ровно их строки,
     * а принадлежность разделу проверяется хеш-поиском в S0. Частые значения, диапазоны, цена и
     * наличие проверяются проходом по S0 (EXISTS по покрывающему idx_product).
     *
     * @return string
     */
    private function match(Context $ctx, $fid, array $sel, array $facet, $baseTotal)
    {
        $key = 'm|' . $ctx->key() . '|' . (int)$fid . '|' . md5(json_encode($sel));
        if (isset($this->sets[$key])) {
            return $this->sets[$key];
        }
        $s0 = $this->s0($ctx);
        $type = $this->type($facet);

        if ($type === 'list') {
            $ids = implode(',', array_map('intval', $sel['ids']));
            $estimate = 0;
            foreach ($sel['ids'] as $vid) {
                $estimate += isset($facet['values'][$vid]['products']) ? (int)$facet['values'][$vid]['products'] : $baseTotal;
            }
            if ($estimate < $baseTotal) {
                $sql = "SELECT i.product_id FROM `" . $this->k->table('index') . "` i STRAIGHT_JOIN " . $s0 . " s ON s.product_id = i.product_id
                    WHERE i.facet_id = " . (int)$fid . " AND i.value_id IN (" . $ids . ")";
                return $this->sets[$key] = $this->materialize($sql, true);
            }
            $sql = "SELECT s.product_id FROM " . $s0 . " s WHERE EXISTS (SELECT 1 FROM `" . $this->k->table('index') . "` i
                WHERE i.product_id = s.product_id AND i.facet_id = " . (int)$fid . " AND i.value_id IN (" . $ids . "))";
            return $this->sets[$key] = $this->materialize($sql);
        }

        if ($type === 'range') {
            $cond = '';
            if ($sel['min'] !== null) {
                $cond .= " AND r.value >= " . $this->num($sel['min']);
            }
            if ($sel['max'] !== null) {
                $cond .= " AND r.value <= " . $this->num($sel['max']);
            }
            $sql = "SELECT s.product_id FROM " . $s0 . " s WHERE EXISTS (SELECT 1 FROM `" . $this->k->table('index_range') . "` r
                WHERE r.product_id = s.product_id AND r.facet_id = " . (int)$fid . $cond . ")";
            return $this->sets[$key] = $this->materialize($sql);
        }

        $where = [];
        $join = " JOIN `" . $this->k->table('product') . "` f ON f.product_id = s.product_id";
        if ($type === 'price') {
            $expr = $this->priceExpr($ctx);
            $join .= $this->priceJoin($ctx);
            if ($sel['min'] !== null) {
                $where[] = $expr . " >= " . $this->num($sel['min']);
            }
            if ($sel['max'] !== null) {
                $where[] = $expr . " <= " . $this->num($sel['max']);
            }
        } else {
            $cond = $this->computedWhere($facet, $sel);
            if ($cond !== null) {
                $where[] = $cond;
            }
        }
        $sql = "SELECT s.product_id FROM " . $s0 . " s" . $join . ($where ? " WHERE " . implode(' AND ', $where) : '');
        return $this->sets[$key] = $this->materialize($sql);
    }

    /**
     * Пересечение M_F по всем фасетам состояния (для одного фасета — сам M_F, без копирования).
     *
     * @return string SQL-источник
     */
    private function intersection(Context $ctx, State $state, array $defs, $baseTotal)
    {
        $parts = [];
        foreach ($state->all() as $fid => $sel) {
            $parts[] = $this->match($ctx, $fid, $sel, $defs[$fid], $baseTotal);
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $key = 'x|' . $ctx->key() . '|' . $state->hash();
        if (isset($this->sets[$key])) {
            return $this->sets[$key];
        }
        $sql = "SELECT m0.product_id FROM " . $parts[0] . " m0";
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $sql .= " JOIN " . $parts[$i] . " m" . $i . " ON m" . $i . ".product_id = m0.product_id";
        }
        return $this->sets[$key] = $this->materialize($sql);
    }

    /** S_full: раздел ∩ все выбранные фильтры. */
    private function sf(Context $ctx, State $state, array $defs, $baseTotal)
    {
        return $this->intersection($ctx, $state, $defs, $baseTotal);
    }

    /**
     * Временная MEMORY-таблица или, если хостинг их запрещает, derived-подзапрос.
     *
     * @param string $select
     * @param bool   $distinct в выборке возможны дубли товара (несколько выбранных значений)
     * @return string
     */
    private function materialize($select, $distinct = false)
    {
        if ($this->k->settings()->get('set_strategy') === 'derived') {
            return '(' . ($distinct ? preg_replace('/^\s*SELECT /', 'SELECT DISTINCT ', $select, 1) : $select) . ')';
        }
        $name = 'psf_set_' . (++self::$seq);
        $db = $this->k->db();
        $db->query("DROP TEMPORARY TABLE IF EXISTS `" . $name . "`");
        $db->query("CREATE TEMPORARY TABLE `" . $name . "` (product_id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY");
        try {
            $db->query("INSERT " . ($distinct ? 'IGNORE ' : '') . "INTO `" . $name . "` " . $select);
        } catch (\Exception $e) {
            // Множество не влезло в max_heap_table_size хостинга («table … is full», 1114):
            // до конца запроса — подзапросы, как при стратегии derived.
            if (strpos($e->getMessage(), 'is full') === false && strpos($e->getMessage(), '1114') === false) {
                throw $e;
            }
            $db->query("DROP TEMPORARY TABLE IF EXISTS `" . $name . "`");
            $this->k->settings()->override(['set_strategy' => 'derived']);
            $this->k->log('ps_filter: MEMORY-таблица переполнена (max_heap_table_size), выборка продолжена подзапросами');
            return $this->materialize($select, $distinct);
        }
        return '`' . $name . '`';
    }

    /* ===================== Выражения ===================== */

    /** @return string|null SQL-выражение значения вычисляемого фасета (алиас f) */
    private function computedExpr(array $facet)
    {
        if ($facet['source'] === 'stock') {
            // По остатку или по статусам наличия (id значений — см. \PsFilter\Stock).
            return \PsFilter\Stock::sql($facet['settings'], 'f');
        }
        return null;
    }

    /** @return string|null */
    private function computedWhere(array $facet, array $sel)
    {
        $expr = $this->computedExpr($facet);
        if ($expr === null || empty($sel['ids'])) {
            return null;
        }
        return $expr . " IN (" . implode(',', array_map('intval', $sel['ids'])) . ")";
    }

    /** @return string */
    private function priceExpr(Context $ctx)
    {
        $tax = $ctx->taxSql();
        return $tax === '1' ? 'COALESCE(pr.price, 0)' : '(COALESCE(pr.price, 0) * ' . $tax . ')';
    }

    /** @return string */
    private function priceJoin(Context $ctx)
    {
        return " LEFT JOIN `" . $this->k->table('price') . "` pr ON pr.product_id = s.product_id AND pr.customer_group_id = " . (int)$ctx->customerGroupId;
    }
}
