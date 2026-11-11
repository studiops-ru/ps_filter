<?php
namespace PsFilter\Engine;

/**
 * Результат выборки.
 */
class Result
{
    /** @var int товаров с учетом фильтра */
    public $total = 0;
    /** @var int товаров в контексте без фильтра */
    public $totalBase = 0;
    /** @var array facet_id => [value_id => count] — сколько товаров даст выбор значения при остальных фильтрах */
    public $counts = [];
    /** @var array facet_id => [value_id => count] — значения, которые вообще есть в контексте */
    public $baseCounts = [];
    /** @var array facet_id => [min, max] — доступный диапазон при остальных фильтрах */
    public $ranges = [];
    /** @var array facet_id => [min, max] — диапазон в контексте без фильтра */
    public $baseRanges = [];
    /** @var array|null facet_id => товаров с хотя бы одним значением в контексте без фильтра (MySQL: диапазоны всегда,
     *  списки — при автоподборе в разделе); null — неизвестно */
    public $baseCoverage;
    /** @var int[]|null товары страницы (если запрошен список) */
    public $productIds;
    /** @var \PsFilter\State|null состояние, фактически примененное (без фасетов, которых нет в контексте) */
    public $state;
    /** @var array замеры для отладки: имя => мс */
    public $timings = [];
}
