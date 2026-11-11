<?php
namespace PsFilter\Engine\Manticore;

use PsFilter\Context;
use PsFilter\Engine\Backend;
use PsFilter\Kernel;
use PsFilter\State;

/**
 * Выборка на Manticore Search (режим 2, для каталогов от ~200 тыс. товаров).
 *
 * Та же модель, что у MySQL-движка, но множества не материализуются: каждое состояние — это
 * условие WHERE по документам товаров, а счетчики считает сам Manticore:
 *   агрегат   — COUNT(*), пределы диапазонов и цены одним запросом;
 *   FACET     — счетчики значений (vals — все значения товара одним множеством) и наличия;
 *   выбранный фасет F — те же запросы по условию без F.
 * Все запросы клика уходят одним пакетом (multi_query) — один обмен с сервером.
 *
 * Поиск (product/search) считает MySQL: раздел там — результат LIKE-запроса к описаниям.
 * Если Manticore не ответил — запрос пересчитывается на MySQL, сервер на минуту считается недоступным.
 */
class Engine extends \PsFilter\Engine\Base
{

    /** @var Manager */
    private $m;
    /** @var Backend */
    private $fallback;
    /** @var bool сервер не ответил в этом запросе */
    private $down = false;

    public function __construct(Kernel $kernel, Manager $manager, Backend $fallback)
    {
        parent::__construct($kernel);
        $this->m = $manager;
        $this->fallback = $fallback;
    }

    public function search(Context $ctx, State $state, $listing = null)
    {
        // Исключенные разделы у производителя (manufacturer_exclude_categories) считает только MySQL.
        if ($ctx->type === Context::SEARCH || $this->k->applyScope($ctx)->excludeCategories) {
            return $this->fallback->search($ctx, $state, $listing);
        }
        if ($this->down) {
            return $this->fallback->search($ctx, $state, $listing);
        }
        try {
            return parent::search($ctx, $state, $listing);
        } catch (\RuntimeException $e) {
            // До конца запроса — только MySQL: страница вызывает поиск несколько раз (панель, SEO, листинг),
            // и каждый раз ждать таймаут соединения нельзя.
            $this->down = true;
            $this->m->markDown($e->getMessage());
            return $this->fallback->search($ctx, $state, $listing);
        }
    }

    /** Как search(): поиск и недоступный сервер — на MySQL, иначе канонизация адреса падала бы с 500. */
    public function restrict(Context $ctx, State $state)
    {
        if ($ctx->type === Context::SEARCH || $this->down || $this->k->applyScope($ctx)->excludeCategories) {
            return $this->fallback->restrict($ctx, $state);
        }
        try {
            return parent::restrict($ctx, $state);
        } catch (\RuntimeException $e) {
            $this->down = true;
            $this->m->markDown($e->getMessage());
            return $this->fallback->restrict($ctx, $state);
        }
    }

    public function release()
    {
        $this->fallback->release();
    }

    protected function cachePrefix()
    {
        return 'mt|';
    }

    /* ===================== Статистика ===================== */

    protected function baseStats(Context $ctx, array $defs)
    {
        $groups = $this->groups($defs);
        $stats = $this->stats($ctx, new State(), $defs, $groups);
        return $stats['total'] ? $stats : ['total' => 0, 'counts' => [], 'ranges' => []];
    }

    protected function filteredStats(Context $ctx, State $state, array $defs, array $base)
    {
        $unselected = $this->unselected($state, $defs, $base);
        list($recount, $fromBase) = $this->selectedPlan($state, $base);

        // Один пакет: статистика по полному состоянию + по «остальным» для каждого выбранного фасета.
        $plans = [['state' => $state, 'groups' => $unselected]];
        foreach ($recount as $fid => $others) {
            $plans[] = ['state' => $others, 'groups' => $this->groupsOf([$fid], $defs)];
        }
        $results = $this->statsBatch($ctx, $plans, $defs);

        $out = $results[0];
        if (!$out['total']) {
            $out['counts'] = [];
            $out['ranges'] = [];
        }
        foreach ($fromBase as $fid) {
            $this->copyFromBase($out, $base, $fid);
        }
        $i = 1;
        foreach ($recount as $fid => $others) {
            $r = $results[$i++];
            if (isset($r['counts'][$fid])) {
                $out['counts'][$fid] = $r['counts'][$fid];
            } elseif ($this->type($defs[$fid]) !== 'range' && $this->type($defs[$fid]) !== 'price') {
                $out['counts'][$fid] = [];
            }
            if (isset($r['ranges'][$fid])) {
                $out['ranges'][$fid] = $r['ranges'][$fid];
            }
        }
        return $out;
    }

    /** @return array статистика одного состояния */
    private function stats(Context $ctx, State $state, array $defs, array $groups)
    {
        $r = $this->statsBatch($ctx, [['state' => $state, 'groups' => $groups]], $defs);
        return $r[0];
    }

    /**
     * Пакет статистик: на каждое состояние агрегатный запрос и запрос с FACET.
     *
     * @param array $plans [['state' => State, 'groups' => [type => [fid]]]]
     * @return array[] ['total', 'counts', 'ranges'] по порядку планов
     */
    private function statsBatch(Context $ctx, array $plans, array $defs)
    {
        $sqls = [];
        $layout = [];
        $vals = $this->valueMap($defs);
        // Предел групп FACET — не меньше числа значений: иначе часть счетчиков потеряется.
        // Лишний запас дорог: сортировщик на 100 тыс. групп стоит ~28 мс на запрос против ~2 мс на 1000.
        $groupsLimit = max(1000, (int)(ceil((count($vals) + 100) / 1000) * 1000));
        foreach ($plans as $p) {
            list($select, $where) = $this->conditions($ctx, $p['state'], $defs);
            $from = " FROM " . $this->m->index()->table() . " WHERE " . implode(' AND ', $where);

            // Агрегаты: число товаров, пределы диапазонов, цена.
            $agg = ['COUNT(*) AS c'];
            foreach ($p['groups']['range'] as $fid) {
                $agg[] = "MIN(IF(ranges.lo$fid IS NULL, 999999999999999, BIGINT(ranges.lo$fid))) AS mn$fid";
                $agg[] = "MAX(IF(ranges.hi$fid IS NULL, -999999999999999, BIGINT(ranges.hi$fid))) AS mx$fid";
            }
            if ($p['groups']['price']) {
                $price = $this->priceExpr($ctx);
                $agg[] = "MIN($price) AS mnp";
                $agg[] = "MAX($price) AS mxp";
            }
            $sqls[] = "SELECT " . implode(', ', array_merge($agg, $select)) . $from;

            // FACET: значения списков и наличие.
            $facets = [];
            if ($p['groups']['list']) {
                $facets[] = " FACET vals ORDER BY COUNT(*) DESC LIMIT " . $groupsLimit;
            }
            if ($p['groups']['computed']) {
                $facets[] = " FACET stock LIMIT 10";
            }
            if ($facets) {
                $sqls[] = "SELECT id" . ($select ? ', ' . implode(', ', $select) : '') . $from
                    . " LIMIT 0 OPTION max_matches=" . $groupsLimit . implode('', $facets);
            }
            $layout[] = $facets ? 1 + count($facets) + 1 : 1; // агрегат + (основной набор FACET-запроса + наборы фасетов)
        }

        $sets = $this->m->client()->batch($sqls);
        $out = [];
        $pos = 0;
        foreach ($plans as $n => $p) {
            $agg = isset($sets[$pos][0]) ? $sets[$pos][0] : ['c' => 0];
            $pos++;
            $res = ['total' => (int)$agg['c'], 'counts' => [], 'ranges' => []];
            if ($res['total'] > 0) {
                foreach ($p['groups']['range'] as $fid) {
                    if (isset($agg['mn' . $fid]) && (float)$agg['mn' . $fid] < 9.9e14 && (float)$agg['mx' . $fid] > -9.9e14) {
                        $res['ranges'][$fid] = [(int)$agg['mn' . $fid] / Index::SCALE, (int)$agg['mx' . $fid] / Index::SCALE];
                    }
                }
                foreach ($p['groups']['price'] as $fid) {
                    if (isset($agg['mnp']) && $agg['mnp'] !== null) {
                        $res['ranges'][$fid] = [self::unprice($agg['mnp']), self::unprice($agg['mxp'])];
                    }
                }
            }
            if ($layout[$n] > 1) {
                $pos++; // основной набор FACET-запроса (LIMIT 0) пуст
                if ($p['groups']['list']) {
                    $wanted = array_flip($p['groups']['list']);
                    foreach ($sets[$pos++] as $row) {
                        $vid = (int)$row['vals'];
                        if (isset($vals[$vid]) && isset($wanted[$vals[$vid]]) && $res['total'] > 0) {
                            $res['counts'][$vals[$vid]][$vid] = (int)$row['count(*)'];
                        }
                    }
                }
                if ($p['groups']['computed']) {
                    $stock = [];
                    foreach ($sets[$pos++] as $row) {
                        $stock[(int)$row['stock']] = (int)$row['count(*)'];
                    }
                    if ($res['total'] > 0) {
                        foreach ($p['groups']['computed'] as $fid) {
                            if ($defs[$fid]['source'] === 'stock') {
                                $res['counts'][$fid] = $stock;
                            }
                        }
                    }
                }
            }
            $out[] = $res;
        }
        return $out;
    }

    /* ===================== Список товаров ===================== */

    protected function pageIds(Context $ctx, State $state, array $defs, array $listing, $baseTotal)
    {
        $dir = isset($listing['order']) && strtoupper($listing['order']) === 'DESC' ? 'DESC' : 'ASC';
        $sort = isset($listing['sort']) ? $listing['sort'] : 'p.sort_order';
        $start = max(0, (int)(isset($listing['start']) ? $listing['start'] : 0));
        $limit = max(1, (int)(isset($listing['limit']) ? $listing['limit'] : 20));

        list($select, $where) = $this->conditions($ctx, $state, $defs);
        $columns = ['p.sort_order' => 'sort_order', 'pd.name' => 'name_sort', 'rating' => 'rating', 'p.model' => 'model_sort',
            'p.quantity' => 'quantity', 'p.date_added' => 'date_added', 'p.viewed' => 'viewed',
            'popular' => $this->k->sales()->enabled() ? 'sold' : 'viewed'];
        // При равенстве — как у MySQL-движка: название от А до Я, у «Популярных» и просмотров сначала новинки.
        $tie = in_array($sort, ['popular', 'p.viewed'], true) ? 'date_added DESC, name_sort ASC, id ASC' : ($sort === 'pd.name' ? 'id ASC' : 'name_sort ASC, id ASC');
        if ($sort === 'p.price') {
            // Как у MySQL-движка: по цене группы без налога.
            $select[] = "BIGINT(prices.g" . (int)$ctx->customerGroupId . ") AS sort_price";
            $order = "sort_price";
        } else {
            $order = isset($columns[$sort]) ? $columns[$sort] : 'sort_order';
        }
        $rows = $this->m->client()->query("SELECT id" . ($select ? ', ' . implode(', ', $select) : '') . " FROM " . $this->m->index()->table()
            . " WHERE " . implode(' AND ', $where)
            . " ORDER BY " . $order . " " . $dir . ", " . $tie . " LIMIT " . $start . ", " . $limit
            . " OPTION max_matches=" . max(1000, $start + $limit));
        return array_map('intval', array_column($rows, 'id'));
    }

    /* ===================== Условия ===================== */

    /**
     * Раздел и состояние как условия Manticore. Условия на выражения (диапазоны, цена) задаются
     * псевдонимом в списке выборки и проверкой псевдонима в WHERE — так требует Manticore.
     *
     * @return array [string[] $select, string[] $where]
     */
    private function conditions(Context $ctx, State $state, array $defs)
    {
        $where = ['active = 1', 'date_available <= ' . Index::day(date('Y-m-d')), 'stores = ' . (int)$ctx->storeId];
        $select = [];
        if ($ctx->onlyInstock) {
            $where[] = 'quantity > 0';
        }
        if ($ctx->type === Context::MANUFACTURER) {
            $where[] = 'manufacturer_id = ' . (int)$ctx->id;
        } else {
            $where[] = ($ctx->subtree ? 'cats' : 'cats_direct') . ' = ' . (int)$ctx->id;
        }

        foreach ($state->all() as $fid => $sel) {
            if (!isset($defs[$fid])) {
                continue;
            }
            switch ($this->type($defs[$fid])) {
                case 'list':
                    // Не «vals IN (a, b)»: в Manticore 29 такой фильтр по множеству через вторичный индекс
                    // считает товар с обоими значениями дважды. Цепочка OR дает правильный результат.
                    $or = [];
                    foreach ($sel['ids'] as $vid) {
                        $or[] = 'vals = ' . (int)$vid;
                    }
                    $where[] = count($or) > 1 ? '(' . implode(' OR ', $or) . ')' : $or[0];
                    break;
                case 'computed':
                    if ($defs[$fid]['source'] === 'stock' && !empty($sel['ids'])) {
                        $where[] = 'stock IN (' . implode(',', array_map('intval', $sel['ids'])) . ')';
                    }
                    break;
                case 'range':
                    $cond = [];
                    if ($sel['min'] !== null) {
                        $cond[] = 'x >= ' . self::boundUp($sel['min'], Index::SCALE);
                    }
                    if ($sel['max'] !== null) {
                        $cond[] = 'x <= ' . self::boundDown($sel['max'], Index::SCALE);
                    }
                    $select[] = 'ANY(' . implode(' AND ', $cond) . ' FOR x IN ranges.f' . (int)$fid . ') AS r' . (int)$fid;
                    $where[] = 'r' . (int)$fid . ' = 1';
                    break;
                case 'price':
                    $select[] = $this->priceExpr($ctx) . ' AS pr' . (int)$fid;
                    if ($sel['min'] !== null) {
                        $where[] = 'pr' . (int)$fid . ' >= ' . self::boundUp($sel['min'], Index::SCALE * 1000000);
                    }
                    if ($sel['max'] !== null) {
                        $where[] = 'pr' . (int)$fid . ' <= ' . self::boundDown($sel['max'], Index::SCALE * 1000000);
                    }
                    break;
            }
        }
        return [$select, $where];
    }

    /**
     * Цена группы с налогом в единицах 10⁻¹⁰ (цена ×10⁴, множитель налога ×10⁶) — целое, без потерь.
     *
     * @return string
     */
    private function priceExpr(Context $ctx)
    {
        $tax = '1000000';
        foreach (array_reverse($ctx->taxRates, true) as $classId => $rate) {
            $tax = 'IF(tax_class_id = ' . (int)$classId . ', ' . (int)round((float)$rate * 1000000) . ', ' . $tax . ')';
        }
        return 'BIGINT(prices.g' . (int)$ctx->customerGroupId . ') * ' . $tax;
    }

    /** @return float цена из единиц 10⁻¹⁰ */
    private static function unprice($v)
    {
        return round((float)$v / (Index::SCALE * 1000000), 6);
    }

    /**
     * Нижняя граница в целых единицах: значение ≥ границы ⇔ целое ≥ ⌈граница × масштаб⌉.
     * Границу в фильтре задают до 4 знаков (как num() у MySQL), поэтому считаем через строку.
     *
     * @return string
     */
    private static function boundUp($bound, $scale)
    {
        return self::scaleBound($bound, $scale, true);
    }

    /** @return string верхняя граница: целое ≤ ⌊граница × масштаб⌋ */
    private static function boundDown($bound, $scale)
    {
        return self::scaleBound($bound, $scale, false);
    }

    /** @return string */
    private static function scaleBound($bound, $scale, $up)
    {
        // Граница с 4 знаками после запятой (так ее сравнивает и MySQL-движок) — это целое ×10⁴.
        $units = (int)round((float)$bound * Index::SCALE);
        $extra = (int)($scale / Index::SCALE); // 1 для диапазонов, 10⁶ для цены
        $v = $units * $extra;
        return (string)$v;
    }

    /** @return array value_id => facet_id */
    private function valueMap(array $defs)
    {
        $map = [];
        foreach ($defs as $fid => $f) {
            if ($this->type($f) === 'list') {
                foreach ($f['values'] as $vid => $v) {
                    $map[(int)$vid] = (int)$fid;
                }
            }
        }
        return $map;
    }

    /**
     * Группы для одного фасета (статистика выбранного фасета по «остальным»).
     *
     * @return array
     */
    private function groupsOf(array $facetIds, array $defs)
    {
        $g = ['list' => [], 'range' => [], 'price' => [], 'computed' => []];
        foreach ($facetIds as $fid) {
            $g[$this->type($defs[$fid])][] = (int)$fid;
        }
        return $g;
    }
}
