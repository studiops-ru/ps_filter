<?php
namespace PsFilter\Engine;

use PsFilter\Context;
use PsFilter\FacetRepository;
use PsFilter\Kernel;
use PsFilter\State;

/**
 * Общее у движков выборки: определения фасетов, типы, отсечение состояния по разделу,
 * кеширование статистики. Сами множества и счетчики считает наследник.
 */
abstract class Base implements Backend
{
    /** @var Kernel */
    protected $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Общий ход выборки: статистика раздела (кеш на час) → статистика с фильтром (кеш на cache_ttl_counters)
     * → страница товаров. Ключи кеша включают префикс движка: результаты MySQL и Manticore не смешиваются.
     */
    public function search(Context $ctx, State $state, $listing = null)
    {
        $this->k->applyScope($ctx);
        $result = new Result();
        $defs = $this->defs($ctx);
        $t0 = microtime(true);
        $prefix = $this->cachePrefix();

        // 1. Раздел без фильтров: что вообще есть на этой странице.
        $baseKey = $prefix . 'base|' . $ctx->key();
        $base = $this->k->cache()->get($baseKey);
        if (!is_array($base)) {
            $base = $this->baseStats($ctx, $defs);
            $this->k->cache()->set($baseKey, $ctx->scope(), $base, 3600);
        }
        $result->totalBase = (int)$base['total'];
        $result->baseCounts = $base['counts'];
        $result->baseRanges = $base['ranges'];
        $result->baseCoverage = isset($base['coverage']) ? $base['coverage'] : null;
        $result->timings['base'] = round((microtime(true) - $t0) * 1000, 1);

        // Выбор по фасетам, которых нет в разделе, отбрасываем: такой URL — мусор.
        $state = $this->restrictToContext($state, $defs, $base);
        $result->state = $state;

        // 2. С фильтром.
        if ($state->isEmpty()) {
            $result->total = $result->totalBase;
            $result->counts = $base['counts'];
            $result->ranges = $base['ranges'];
        } else {
            $t1 = microtime(true);
            $cntKey = $prefix . 'cnt|' . $ctx->key() . '|' . $state->hash();
            $stats = $this->k->cache()->get($cntKey);
            if (!is_array($stats)) {
                $stats = $this->filteredStats($ctx, $state, $defs, $base);
                $this->k->cache()->set($cntKey, $ctx->scope(), $stats, (int)$this->k->settings()->get('cache_ttl_counters', 600));
            }
            $result->total = (int)$stats['total'];
            $result->counts = $stats['counts'];
            $result->ranges = $stats['ranges'];
            $result->timings['filtered'] = round((microtime(true) - $t1) * 1000, 1);
        }

        // 3. Страница товаров.
        if ($listing !== null) {
            $t2 = microtime(true);
            $result->productIds = $result->total > 0 ? $this->pageIds($ctx, $state, $defs, $listing, $result->totalBase) : [];
            $result->timings['page'] = round((microtime(true) - $t2) * 1000, 1);
        }

        $result->timings['total'] = round((microtime(true) - $t0) * 1000, 1);
        return $result;
    }

    /** @return string префикс ключей кеша движка */
    abstract protected function cachePrefix();

    /** @return array ['total' => int, 'counts' => [fid => [vid => n]], 'ranges' => [fid => [min, max]]] */
    abstract protected function baseStats(Context $ctx, array $defs);

    /** @return array как baseStats, но с учетом фильтра */
    abstract protected function filteredStats(Context $ctx, State $state, array $defs, array $base);

    /** @return int[] id товаров страницы */
    abstract protected function pageIds(Context $ctx, State $state, array $defs, array $listing, $baseTotal);

    /**
     * Фасеты, которые пересчитываются при клике: невыбранные и есть в разделе.
     * Свернутые списки при count_collapsed = 0 не считаем: их значения видны только после раскрытия.
     *
     * @return array type => [facet_id]
     */
    protected function unselected(State $state, array $defs, array $base)
    {
        $countCollapsed = (bool)$this->k->settings()->get('count_collapsed', 1);
        $out = ['list' => [], 'range' => [], 'computed' => [], 'price' => []];
        foreach ($this->groups($defs) as $type => $ids) {
            foreach ($ids as $fid) {
                if ($state->has($fid) || (!isset($base['counts'][$fid]) && !isset($base['ranges'][$fid]))) {
                    continue;
                }
                if ($type === 'list' && !$countCollapsed && !empty($defs[$fid]['settings']['collapsed'])) {
                    continue;
                }
                $out[$type][] = $fid;
            }
        }
        return $out;
    }

    /**
     * Выбранные фасеты, для которых счетчики считаются по пересечению остальных выбранных.
     * Остальные (единственный выбранный, большой раздел, сверх лимита) берут статистику раздела.
     *
     * @return array [array $recount facet_id => State без него, array $fromBase facet_id]
     */
    protected function selectedPlan(State $state, array $base)
    {
        $limit = (int)$this->k->settings()->get('max_selected_facets', 10);
        $big = $base['total'] > (int)$this->k->settings()->get('big_scope_threshold', 100000);
        $recount = [];
        $fromBase = [];
        $n = 0;
        foreach ($state->all() as $fid => $sel) {
            $others = $state->without($fid);
            if ($others->isEmpty() || $big || ++$n > $limit) {
                $fromBase[] = $fid;
            } else {
                $recount[$fid] = $others;
            }
        }
        return [$recount, $fromBase];
    }

    /** Счетчики и диапазон фасета из статистики раздела. */
    protected function copyFromBase(array &$out, array $base, $fid)
    {
        if (isset($base['counts'][$fid])) {
            $out['counts'][$fid] = $base['counts'][$fid];
        }
        if (isset($base['ranges'][$fid])) {
            $out['ranges'][$fid] = $base['ranges'][$fid];
        }
    }

    /**
     * Тип фасета для движка.
     *
     * @return string list|range|price|computed
     */
    protected function type(array $facet)
    {
        if ($facet['source'] === 'price') {
            return 'price';
        }
        if ((int)$facet['kind'] === FacetRepository::KIND_COMPUTED || $facet['source'] === 'stock') {
            return 'computed';
        }
        return (int)$facet['kind'] === FacetRepository::KIND_RANGE ? 'range' : 'list';
    }

    /** @return array id фасетов по типам */
    protected function groups(array $defs)
    {
        $g = ['list' => [], 'range' => [], 'price' => [], 'computed' => []];
        foreach ($defs as $fid => $facet) {
            $g[$this->type($facet)][] = (int)$fid;
        }
        return $g;
    }

    /**
     * Состояние без фасетов, которых нет в разделе, — для канонизации адреса до выборки.
     * Базовая статистика раздела та же, что считает search(), и лежит в том же кеше.
     *
     * @return State
     */
    public function restrict(Context $ctx, State $state)
    {
        if ($state->isEmpty()) {
            return $state;
        }
        $this->k->applyScope($ctx);
        $defs = $this->defs($ctx);
        $baseKey = $this->cachePrefix() . 'base|' . $ctx->key();
        $base = $this->k->cache()->get($baseKey);
        if (!is_array($base)) {
            $base = $this->baseStats($ctx, $defs);
            $this->k->cache()->set($baseKey, $ctx->scope(), $base, 3600);
        }
        return $this->restrictToContext($state, $defs, $base);
    }

    /**
     * Убирает из состояния фасеты, которых нет в разделе или в определениях.
     *
     * @return State
     */
    protected function restrictToContext(State $state, array $defs, array $base)
    {
        foreach ($state->all() as $fid => $sel) {
            if (!isset($defs[$fid]) || (!isset($base['counts'][$fid]) && !isset($base['ranges'][$fid]))) {
                $state = $state->without($fid);
            }
        }
        return $state;
    }

    /** @return string число для SQL */
    protected function num($v)
    {
        return rtrim(rtrim(sprintf('%.4F', (float)$v), '0'), '.');
    }

    /** @return array facet_id => facet */
    protected function defs(Context $ctx)
    {
        return $this->k->facets()->snapshot($ctx->languageId)['facets'];
    }
}
