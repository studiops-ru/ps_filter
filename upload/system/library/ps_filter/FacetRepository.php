<?php
namespace PsFilter;

/**
 * Определения фасетов и значений для языка: то, что нужно витрине для разбора URL,
 * подписей и порядка. Читается одним снимком и кешируется (scope 'defs'),
 * сбрасывается при правке фасетов/значений и после полной переиндексации.
 *
 * Виды фасетов (kind): 0 — дискретный (ps_filter_index), 1 — диапазон (index_range или цена),
 * 2 — вычисляемый из ps_filter_product (наличие).
 */
class FacetRepository
{
    const KIND_LIST = 0;
    const KIND_RANGE = 1;
    const KIND_COMPUTED = 2;

    /** @var Kernel */
    private $k;
    /** @var array language_id => snapshot */
    private $loaded = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Снимок: ['facets' => [facet_id => facet], 'codes' => [code => facet_id]].
     * Фасет: facet_id, code, source, ref_id, kind, widget, settings, sort_order, name, hint, prefix, suffix,
     * values => [value_id => [value_id, slug, name, sort_order, pinned, color, image, hidden]], slugs => [slug => value_id].
     *
     * @param int $languageId
     * @return array
     */
    public function snapshot($languageId)
    {
        $languageId = (int)$languageId;
        if (isset($this->loaded[$languageId])) {
            return $this->loaded[$languageId];
        }
        // Версия кода в ключе: после обновления модуля снимок пересобирается сам (в нем могут быть новые поля).
        // value_capitalize меняет названия значений — свой снимок на каждое положение настройки.
        $key = 'defs|' . Kernel::VERSION . '|' . $languageId . ((bool)$this->k->settings()->get('value_capitalize', 0) ? '|cap' : '');
        $data = $this->k->cache()->get($key);
        if (!is_array($data)) {
            $data = $this->build($languageId);
            $this->k->cache()->set($key, 'defs', $data, 86400);
        }
        return $this->loaded[$languageId] = $data;
    }

    /**
     * Настройки фасетов для категории: facet_id => [hidden, sort_order, collapsed, widget].
     *
     * Строки ps_filter_facet_to_category наследуются вниз по дереву: ближайшая к странице категория
     * (сама или ближайший предок) перекрывает дальних. status = 0 — фасет скрыт в ветке.
     * category_mode = 1 («только привязанные»): фасет виден, только если есть строка со status = 1.
     * Для брендов и поиска переопределений нет.
     *
     * @return array
     */
    public function overrides(Context $ctx)
    {
        // Поиск по разделу (category_id у страницы поиска) — набор и порядок фасетов этого раздела.
        $categoryId = $ctx->type === Context::CATEGORY ? (int)$ctx->id
            : ($ctx->type === Context::SEARCH && !empty($ctx->search['category_id']) ? (int)$ctx->search['category_id'] : 0);
        if ($categoryId <= 0) {
            return [];
        }
        $key = 'ovr|' . Kernel::VERSION . '|' . $categoryId;
        $out = $this->k->cache()->get($key);
        if (is_array($out)) {
            return $out;
        }
        $db = $this->k->db();
        $out = [];
        $rows = $db->query("SELECT fc.*, cp.level FROM `" . $this->k->table('facet_to_category') . "` fc
            JOIN `" . $this->k->prefix() . "category_path` cp ON cp.path_id = fc.category_id AND cp.category_id = " . $categoryId . "
            WHERE fc.is_auto = 0
            ORDER BY cp.level DESC")->rows;
        foreach ($rows as $r) {
            $fid = (int)$r['facet_id'];
            if (isset($out[$fid])) {
                continue; // уже есть строка ближе к странице
            }
            $out[$fid] = [
                'hidden'     => !(int)$r['status'],
                'bound'      => (bool)(int)$r['status'],
                'sort_order' => $r['sort_order'] === null ? null : (int)$r['sort_order'],
                'collapsed'  => $r['collapsed'] === null ? null : (int)$r['collapsed'],
                'widget'     => $r['widget'] === null ? null : (string)$r['widget'],
            ];
        }
        foreach ($this->k->db()->query("SELECT facet_id FROM `" . $this->k->table('facet') . "` WHERE status = 1 AND category_mode = 1")->rows as $r) {
            $fid = (int)$r['facet_id'];
            if (empty($out[$fid]['bound'])) {
                $out[$fid] = array_merge(['sort_order' => null, 'collapsed' => null, 'widget' => null], isset($out[$fid]) ? $out[$fid] : [], ['hidden' => true]);
            }
        }
        $this->k->cache()->set($key, 'defs', $out, 86400);
        return $out;
    }

    /**
     * Первая буква значения — заглавная (value_capitalize, 0.7.6): «для волос» → «Для волос». Составные значения
     * («Для кожи, для волос») после разбиения дают части со строчной, а первое встреченное написание становится
     * названием значения. Первое слово с заглавными внутри («iPhone», «pH») не трогается.
     *
     * @param string $name
     * @return string
     */
    public static function capitalize($name)
    {
        $name = (string)$name;
        $first = preg_split('/[\s,;\/(]/u', $name, 2)[0];
        if ($first === '' || mb_strtolower($first, 'UTF-8') !== $first) {
            return $name;
        }
        $char = mb_substr($name, 0, 1, 'UTF-8');
        $upper = mb_strtoupper($char, 'UTF-8');
        return $upper === $char ? $name : $upper . mb_substr($name, 1, null, 'UTF-8');
    }

    /**
     * Фасеты, закрепленные строкой «Показывать» во всех включенных корневых разделах, — «закреплены на весь магазин».
     * Автоподбор у производителя и в поиске (auto_unbound) держит их в основных: раздела там нет, а такой фасет
     * нужен в любом разделе (срок поставки, наличие на складе).
     *
     * @return int[] facet_id => true
     */
    public function pinnedEverywhere()
    {
        $key = 'pin|' . Kernel::VERSION;
        $out = $this->k->cache()->get($key);
        if (is_array($out)) {
            return $out;
        }
        $db = $this->k->db();
        $roots = "SELECT category_id FROM `" . $this->k->prefix() . "category` WHERE parent_id = 0 AND status = 1";
        $total = (int)$db->query("SELECT COUNT(*) AS c FROM (" . $roots . ") r")->row['c'];
        $out = [];
        if ($total > 0) {
            $rows = $db->query("SELECT fc.facet_id FROM `" . $this->k->table('facet_to_category') . "` fc
                WHERE fc.is_auto = 0 AND fc.status = 1 AND fc.category_id IN (" . $roots . ")
                GROUP BY fc.facet_id HAVING COUNT(*) = " . $total)->rows;
            foreach ($rows as $r) {
                $out[(int)$r['facet_id']] = true;
            }
        }
        $this->k->cache()->set($key, 'defs', $out, 86400);
        return $out;
    }

    /**
     * Статусы наличия магазина: stock_status_id => название на языке витрины (иначе основном).
     *
     * @return array
     */
    public function stockStatuses($languageId, $defaultLang = null)
    {
        $defaultLang = $defaultLang === null ? $this->k->defaultLanguageId() : (int)$defaultLang;
        $out = [];
        $q = $this->k->db()->query("SELECT stock_status_id, language_id, name FROM `" . $this->k->prefix() . "stock_status`
            WHERE language_id IN (" . (int)$languageId . ", " . (int)$defaultLang . ") ORDER BY stock_status_id");
        foreach ($q->rows as $r) {
            $id = (int)$r['stock_status_id'];
            if (!isset($out[$id]) || (int)$r['language_id'] === (int)$languageId) {
                $out[$id] = $r['name'];
            }
        }
        return $out;
    }

    /** Сбрасывает определения (после правки в админке). */
    public function invalidate()
    {
        $this->loaded = [];
        $this->k->cache()->deleteScopes(['defs']);
    }

    /**
     * @param int $languageId
     * @return array
     */
    private function build($languageId)
    {
        $db = $this->k->db();
        $defaultLang = $this->k->defaultLanguageId();
        $facets = [];
        $codes = [];

        $q = $db->query("SELECT f.*, COALESCE(fd.name, fdd.name, f.code) AS name, COALESCE(fd.hint, '') AS hint,
                COALESCE(fd.prefix, '') AS prefix, COALESCE(fd.suffix, '') AS suffix
            FROM `" . $this->k->table('facet') . "` f
            LEFT JOIN `" . $this->k->table('facet_description') . "` fd ON fd.facet_id = f.facet_id AND fd.language_id = " . $languageId . "
            LEFT JOIN `" . $this->k->table('facet_description') . "` fdd ON fdd.facet_id = f.facet_id AND fdd.language_id = " . $defaultLang . "
            WHERE f.status = 1
            ORDER BY f.sort_order, f.facet_id");
        foreach ($q->rows as $row) {
            $settings = json_decode($row['settings'], true);
            $id = (int)$row['facet_id'];
            $facets[$id] = [
                'facet_id'   => $id,
                'code'       => $row['code'],
                'source'     => $row['source'],
                'ref_id'     => (int)$row['ref_id'],
                'kind'       => (int)$row['kind'],
                'widget'     => $row['widget'],
                'settings'   => is_array($settings) ? $settings : [],
                'sort_order' => (int)$row['sort_order'],
                'seo_index'  => (int)$row['seo_index'],
                'category_mode' => (int)$row['category_mode'],
                'name'       => $row['name'],
                'hint'       => $row['hint'],
                'prefix'     => $row['prefix'],
                'suffix'     => $row['suffix'],
                'values'     => [],
                'slugs'      => [],
            ];
            $codes[$row['code']] = $id;
        }

        if ($facets) {
            $cap = (bool)$this->k->settings()->get('value_capitalize', 0);
            $q = $db->query("SELECT v.value_id, v.facet_id, v.slug, v.sort_order, v.pinned, v.color, v.image, v.hidden, v.numeric_value, v.products, v.alias_of,
                    COALESCE(vd.name, vdd.name, v.slug) AS name
                FROM `" . $this->k->table('value') . "` v
                LEFT JOIN `" . $this->k->table('value_description') . "` vd ON vd.value_id = v.value_id AND vd.language_id = " . $languageId . "
                LEFT JOIN `" . $this->k->table('value_description') . "` vdd ON vdd.value_id = v.value_id AND vdd.language_id = " . $defaultLang . "
                WHERE v.facet_id IN (" . implode(',', array_keys($facets)) . ")
                ORDER BY v.alias_of = 0 DESC");
            foreach ($q->rows as $row) {
                $f = (int)$row['facet_id'];
                $id = (int)$row['value_id'];
                if ((int)$row['alias_of']) {
                    // Синоним: в панели его нет, но старый адрес с его slug разбирается в основное значение
                    // (а канонический редирект переведет на адрес основного).
                    if (isset($facets[$f]['values'][(int)$row['alias_of']])) {
                        $facets[$f]['slugs'][$row['slug']] = (int)$row['alias_of'];
                    }
                    continue;
                }
                $facets[$f]['values'][$id] = [
                    'value_id'   => $id,
                    'slug'       => $row['slug'],
                    'name'       => $cap ? self::capitalize($row['name']) : $row['name'],
                    'sort_order' => (int)$row['sort_order'],
                    'pinned'     => (int)$row['pinned'],
                    'color'      => $row['color'],
                    'image'      => $row['image'],
                    'hidden'     => (int)$row['hidden'],
                    'numeric'    => $row['numeric_value'] === null ? null : (float)$row['numeric_value'],
                    'products'   => (int)$row['products'],
                ];
                $facets[$f]['slugs'][$row['slug']] = $id;
            }
            foreach ($facets as &$facet) {
                $facet['values'] = $this->sortValues($facet['values'], isset($facet['settings']['value_sort']) ? $facet['settings']['value_sort'] : 'auto');
            }
            unset($facet);

            // Вычисляемые фасеты: значения фиксированы (наличие — см. Stock: по остатку или по статусам).
            $statuses = null;
            foreach ($facets as &$facet) {
                if ($facet['source'] === 'stock') {
                    if ($statuses === null && Stock::byStatus($facet['settings'])) {
                        $statuses = $this->stockStatuses($languageId, $defaultLang);
                    }
                    $facet['values'] = Stock::values($facet['settings'], (array)$statuses);
                    $facet['slugs'] = [];
                    foreach ($facet['values'] as $id => $value) {
                        $facet['slugs'][$value['slug']] = $id;
                    }
                }
            }
            unset($facet);
        }

        return ['facets' => $facets, 'codes' => $codes];
    }

    /**
     * Порядок значений: закрепленные вверху, затем sort_order; при равенстве — числа по величине,
     * остальное «натурально» по имени («2 л» раньше «10 л»).
     *
     * @param array  $values
     * @param string $mode auto|name
     * @return array
     */
    private function sortValues(array $values, $mode)
    {
        uasort($values, function ($a, $b) use ($mode) {
            if ($a['pinned'] !== $b['pinned']) {
                return $b['pinned'] - $a['pinned'];
            }
            if ($mode !== 'name' && $a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] - $b['sort_order'];
            }
            if ($a['numeric'] !== null && $b['numeric'] !== null && $a['numeric'] != $b['numeric']) {
                return $a['numeric'] < $b['numeric'] ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $values;
    }
}
