<?php
namespace PsFilter\View;

use PsFilter\Context;
use PsFilter\Engine\Result;
use PsFilter\FacetRepository;
use PsFilter\Settings;
use PsFilter\State;
use PsFilter\Url\Builder;
use PsFilter\Url\Codec;

/**
 * Данные панели для twig: только простые массивы, никакой логики в шаблонах.
 * Тема может переопределить любой шаблон и получит те же данные.
 */
class Presenter
{
    const MIN_TOKEN = '__MIN__';
    const MAX_TOKEN = '__MAX__';

    /** @var Settings */
    private $settings;
    /** @var Builder */
    private $builder;
    /** @var callable|null function(Context, State $target, int $count, int $baseTotal): bool — индексируемо ли состояние */
    private $indexable;
    /** @var callable|null function(): array facet_id => true — закрепленные во всех корневых разделах (auto_unbound) */
    private $pinned;

    /**
     * @param Settings      $settings
     * @param Builder       $builder
     * @param callable|null $indexable индексируемые состояния (посадочная или SEO-шаблон) получают
     *                                 настоящую ссылку <a href>, остальные — только data-атрибут для JS:
     *                                 поисковик не тратит обход на тысячи комбинаций под noindex
     * @param callable|null $pinned    FacetRepository::pinnedEverywhere — читается только при auto_unbound
     */
    public function __construct(Settings $settings, Builder $builder, ?callable $indexable = null, ?callable $pinned = null)
    {
        $this->settings = $settings;
        $this->builder = $builder;
        $this->indexable = $indexable;
        $this->pinned = $pinned;
    }

    /**
     * @param Context $ctx
     * @param State   $state
     * @param Result  $result
     * @param array   $defs   FacetRepository::snapshot()['facets']
     * @param array   $query  параметры листинга, которые сохраняются при смене фильтра (sort, order, limit)
     * @param array   $overrides настройки фасетов для текущей категории: facet_id => [hidden, sort_order, collapsed, widget]
     *                           (FacetRepository::overrides); null в поле — наследовать от фасета
     * @return array
     */
    public function panel(Context $ctx, State $state, Result $result, array $defs, array $query, array $overrides = [])
    {
        $hideEmpty = (bool)$this->settings->get('hide_empty');
        $hideSingle = (bool)$this->settings->get('hide_single');
        $hideUseless = (bool)$this->settings->get('hide_useless', 1);
        $perFacet = max(1, (int)$this->settings->get('values_per_facet', 50));
        $facets = [];
        $selected = [];

        // Порядок фасетов с учетом переопределений категории (стабильно: при равенстве — исходный).
        if ($overrides) {
            $pos = 0;
            $order = [];
            foreach ($defs as $fid => $facet) {
                $sort = isset($overrides[$fid]['sort_order']) && $overrides[$fid]['sort_order'] !== null ? (int)$overrides[$fid]['sort_order'] : (int)$facet['sort_order'];
                $order[$fid] = [$sort, $pos++];
            }
            uksort($defs, function ($a, $b) use ($order) {
                return $order[$a] <=> $order[$b];
            });
        }

        foreach ($defs as $fid => $facet) {
            $o = isset($overrides[$fid]) ? $overrides[$fid] : [];
            $sel = $state->get($fid);
            // Фасет скрыт в этой категории (или «только привязанные», а привязки нет) — кроме уже выбранного.
            if (!empty($o['hidden']) && $sel === null) {
                continue;
            }
            $isRange = (int)$facet['kind'] === FacetRepository::KIND_RANGE;
            $collapsed = isset($o['collapsed']) && $o['collapsed'] !== null ? (bool)$o['collapsed'] : !empty($facet['settings']['collapsed']);
            $item = [
                'facet_id'  => $fid,
                'code'      => $facet['code'],
                'name'      => $facet['name'],
                'hint'      => $facet['hint'],
                'widget'    => !empty($o['widget']) ? $o['widget'] : $facet['widget'],
                'type'      => $isRange ? 'range' : 'list',
                'selected'  => $sel !== null,
                'collapsed' => $collapsed && $sel === null,
                'uncounted' => false,
                'more'      => 0,
                'values'    => [],
                'range'     => null,
            ];

            if ($isRange) {
                if (!isset($result->baseRanges[$fid])) {
                    continue;
                }
                $isPrice = $facet['source'] === 'price';
                $rate = $isPrice ? (float)$ctx->currencyRate : 1.0;
                list($bmin, $bmax) = $result->baseRanges[$fid];
                list($amin, $amax) = isset($result->ranges[$fid]) ? $result->ranges[$fid] : [$bmin, $bmax];
                if ($bmax <= $bmin && $sel === null) {
                    continue;
                }
                $decimals = $isPrice ? 0 : (isset($facet['settings']['decimals']) ? (int)$facet['settings']['decimals'] : ($bmax - $bmin < 10 && floor($bmax) != $bmax ? 1 : 0));
                $item['range'] = [
                    'min'       => $this->round($bmin * $rate, $decimals, 'floor'),
                    'max'       => $this->round($bmax * $rate, $decimals, 'ceil'),
                    'avail_min' => $this->round($amin * $rate, $decimals, 'floor'),
                    'avail_max' => $this->round($amax * $rate, $decimals, 'ceil'),
                    'from'      => $sel !== null && $sel['min'] !== null ? $this->round($sel['min'] * $rate, $decimals, 'floor') : '',
                    'to'        => $sel !== null && $sel['max'] !== null ? $this->round($sel['max'] * $rate, $decimals, 'ceil') : '',
                    'step'      => $decimals ? pow(10, -$decimals) : 1,
                    'decimals'  => $decimals,
                    'rate'      => $rate,
                    'prefix'    => $facet['prefix'],
                    'suffix'    => $facet['suffix'],
                    // JS подставляет числа вместо __MIN__/__MAX__ (в валюте магазина, делит на rate сам).
                    'href_template' => $this->builder->url($ctx, $state, $query, [$fid => self::MIN_TOKEN . '-' . self::MAX_TOKEN]),
                    'href_clear'    => $this->builder->url($ctx, $state->without($fid), $query),
                ];
                // Шкала — по товарам, которые остались при других фильтрах (свой выбор в них не учтен), а не
                // по всему разделу: иначе пара дорогих моделей растягивает ее, и небольшой сдвиг ползунка уводит
                // за все доступные товары. Выбранные края в шкалу входят всегда. Если остался один товар
                // (диапазон схлопнулся) — шкала раздела, иначе ползунку некуда двигаться.
                if ($amax > $amin) {
                    $lo = $item['range']['avail_min'];
                    $hi = $item['range']['avail_max'];
                    foreach ([$item['range']['from'], $item['range']['to']] as $edge) {
                        if ($edge !== '') {
                            $lo = min($lo, $edge);
                            $hi = max($hi, $edge);
                        }
                    }
                    $item['range']['min'] = $lo;
                    $item['range']['max'] = $hi;
                }
                if ($sel !== null) {
                    // Подпись «от … до …» собирает шаблон: слова — в языковом файле, не в библиотеке.
                    $selected[] = [
                        'facet'  => $facet['name'],
                        'name'   => '',
                        'from'   => $item['range']['from'],
                        'to'     => $item['range']['to'],
                        'decimals' => $item['range']['decimals'],
                        'suffix' => $facet['suffix'],
                        'href'   => $item['range']['href_clear'],
                    ];
                }
                $facets[] = $item;
                continue;
            }

            $base = isset($result->baseCounts[$fid]) ? $result->baseCounts[$fid] : [];
            if (!$base && $sel === null) {
                continue;
            }
            $counts = isset($result->counts[$fid]) ? $result->counts[$fid] : [];
            // Свернутый фасет без пересчета (count_collapsed = 0): движок его не считал — значения без чисел.
            $uncounted = !$state->isEmpty() && $sel === null && !isset($result->counts[$fid]) && !empty($facet['settings']['collapsed']);
            $item['uncounted'] = $uncounted;
            $single = in_array($item['widget'], ['radio', 'select'], true);
            foreach ($facet['values'] as $vid => $value) {
                $isSelected = $state->hasValue($fid, $vid);
                if (!$isSelected && (empty($base[$vid]) || $value['hidden'])) {
                    continue;
                }
                $count = $uncounted ? (int)$base[$vid] : (isset($counts[$vid]) ? (int)$counts[$vid] : 0);
                if ($hideEmpty && !$count && !$isSelected) {
                    continue;
                }
                $target = $state->toggle($fid, $vid, $single);
                $href = $this->builder->url($ctx, $target, $query);
                $item['values'][] = [
                    'link'     => !$isSelected && $count > 0 && $this->indexable && call_user_func($this->indexable, $ctx, $target, $count, (int)$result->totalBase),
                    'value_id' => $vid,
                    'slug'     => $value['slug'],
                    'name'     => $value['name'],
                    'count'    => $uncounted ? null : $count,
                    'selected' => $isSelected,
                    'disabled' => !$uncounted && !$count && !$isSelected,
                    'href'     => $href,
                    'color'    => $value['color'],
                    'image'    => $value['image'],
                    'extra'    => false,
                ];
                if ($isSelected) {
                    $selected[] = ['facet' => $facet['name'], 'name' => $value['name'], 'from' => '', 'to' => '', 'suffix' => '', 'href' => $href];
                }
            }
            if (!$item['values'] || ($hideSingle && count($item['values']) === 1 && !$item['selected'])) {
                continue;
            }
            // Фасет ничего не сужает: каждое показанное значение есть у всех товаров раздела. Считаем по разделу
            // без фильтра (baseCounts), а не по текущему выбору — иначе фасеты пропадали бы и появлялись при кликах.
            if ($hideUseless && !$item['selected'] && $result->totalBase > 0) {
                $narrows = false;
                foreach ($item['values'] as $v) {
                    if (isset($base[$v['value_id']]) && (int)$base[$v['value_id']] < $result->totalBase) {
                        $narrows = true;
                        break;
                    }
                }
                if (!$narrows) {
                    continue;
                }
            }
            // «Показать еще»: выбранные значения всегда видны, остальные — первые N.
            $shown = 0;
            foreach ($item['values'] as &$v) {
                if (!$v['selected'] && ++$shown > $perFacet) {
                    $v['extra'] = true;
                    $item['more']++;
                }
            }
            unset($v);
            if ($item['widget'] === 'select' || $item['widget'] === 'radio') {
                $item['href_clear'] = $this->builder->url($ctx, $state->without($fid), $query);
            }
            $facets[] = $item;
        }

        // Автоподбор — там, где раздела нет: у производителя и в поиске по всему магазину.
        // Поиск по разделу (category_id) берет фасеты раздела через overrides, как сама категория.
        // Не попавшие в подбор (auto_rest ≠ drop) уходят под кнопку «Все фильтры» — свернутыми, в порядке панели.
        $auto = (int)$this->settings->get('auto_facets', 0);
        $inCategory = $ctx->type === Context::CATEGORY || ($ctx->type === Context::SEARCH && !empty($ctx->search['category_id']));
        $rest = [];
        if ($auto > 0 && !$inCategory) {
            list($facets, $rest) = $this->autoPick($facets, $defs, $result, $auto);
        }
        // Автоподбор характеристик в разделе — для каталогов, где разделов слишком много для ручного отбора.
        $catAuto = (int)$this->settings->get('category_auto_facets', 0);
        if ($catAuto > 0 && $inCategory) {
            list($facets, $rest) = $this->categoryAutoPick($facets, $defs, $result, $catAuto, $overrides);
        }
        if ((string)$this->settings->get('auto_rest', 'drop') === 'drop') {
            $rest = [];
        }
        foreach ($rest as &$item) {
            $item['collapsed'] = true;
        }
        unset($item);

        return [
            'facets'   => $facets,
            'rest'     => $rest,
            'selected' => $selected,
            'reset'    => $this->builder->url($ctx, new State(), $query),
            'url'      => $this->builder->url($ctx, $state, $query),
            'total'    => (int)$result->total,
            'count'    => $state->count(),
        ];
    }

    /**
     * Автоподбор на странице производителя и поиска. Фасеты «только в отмеченных категориях» привязаны
     * к разделам, а здесь раздела нет — без отбора панель показала бы все характеристики магазина.
     * Остаются те, у которых от 2 до auto_max_values значений и которые есть у auto_min_coverage %
     * товаров страницы, не больше $limit, самые покрывающие — первыми. Выбранные покупателем и фасеты
     * «во всех категориях» (цена, бренд, наличие) не трогаются; диапазоны в отбор не идут.
     * auto_unbound (0.7.5): в отбор идут и характеристики, опции и поля товара «во всех категориях» — для каталогов,
     * где фасеты не привязаны к разделам (их отбирает автоподбор в разделе); закрепленные строкой «Показывать»
     * во всех корневых разделах остаются в основных всегда.
     *
     * @return array [показанные, не попавшие в подбор — в порядке панели]
     */
    private function autoPick(array $facets, array $defs, Result $result, $limit)
    {
        $maxValues = max(2, (int)$this->settings->get('auto_max_values', 12));
        $minShare = max(0, min(100, (int)$this->settings->get('auto_min_coverage', 25))) / 100;
        $total = max(1, (int)$result->totalBase);
        $unbound = (bool)$this->settings->get('auto_unbound', 0);
        $pinned = $unbound && $this->pinned ? (array)call_user_func($this->pinned) : [];
        $keep = [];
        $picked = [];
        $rest = [];
        foreach ($facets as $i => $item) {
            $fid = $item['facet_id'];
            if (!$item['selected'] && !isset($pinned[$fid]) && $this->restOnly($defs, $fid)) {
                $rest[$i] = $item;
                continue;
            }
            $source = isset($defs[$fid]['source']) ? $defs[$fid]['source'] : '';
            $candidate = !empty($defs[$fid]['category_mode'])
                || ($unbound && in_array($source, ['attribute', 'option', 'product_field'], true));
            if ($item['selected'] || !$candidate || isset($pinned[$fid])) {
                $keep[] = $item;
                continue;
            }
            if ($item['type'] === 'range') {
                $rest[$i] = $item;
                continue;
            }
            $base = isset($result->baseCounts[$fid]) ? array_filter($result->baseCounts[$fid]) : [];
            $variants = count($base);
            // Доля товаров с фасетом: сумма по значениям, не больше 100 % (у товара бывает несколько значений).
            $share = min(1, array_sum($base) / $total);
            if ($variants < 2 || $variants > $maxValues || $share < $minShare) {
                $rest[$i] = $item;
                continue;
            }
            $picked[] = [$share, $variants, $item, $i];
        }
        usort($picked, function ($a, $b) {
            return [$b[0], $a[1]] <=> [$a[0], $b[1]];
        });
        foreach ($picked as $n => $p) {
            if ($n < $limit) {
                $keep[] = $p[2];
            } else {
                $rest[$p[3]] = $p[2];
            }
        }
        ksort($rest);
        return [$keep, array_values($rest)];
    }

    /**
     * Автоподбор характеристик в разделе (category_auto_facets > 0). Кандидаты — характеристики, опции и поля
     * товара; цена, производитель, наличие, группы фильтров OpenCart и свои фасеты остаются всегда. Остаются
     * не больше $limit кандидатов, которые есть хотя бы у category_auto_min_coverage % товаров раздела
     * (самые покрывающие), в прежнем порядке панели. Выбранные покупателем и отмеченные для раздела вручную
     * (строка «показать» во вкладке «Категории» фасета) не отбираются. Доля — по разделу без фильтра: набор
     * фасетов не меняется от кликов. Доля — товары с хотя бы одним значением (MySQL); без этого числа
     * (Manticore, старый кеш) у списка — сумма по значениям, не больше 100 %, а диапазон остается.
     * При равной доле — кто выше в панели.
     *
     * @return array [показанные, не попавшие в подбор — в порядке панели]
     */
    private function categoryAutoPick(array $facets, array $defs, Result $result, $limit, array $overrides)
    {
        $minShare = max(0, min(100, (int)$this->settings->get('category_auto_min_coverage', 20))) / 100;
        $total = max(1, (int)$result->totalBase);
        $fixed = [];
        $candidates = [];
        foreach ($facets as $i => $item) {
            $fid = $item['facet_id'];
            $source = isset($defs[$fid]['source']) ? $defs[$fid]['source'] : '';
            if (!$item['selected'] && empty($overrides[$fid]['bound']) && $this->restOnly($defs, $fid)) {
                continue; // ни в основные, ни в кандидаты — под «Все фильтры»
            }
            if ($item['selected'] || !in_array($source, ['attribute', 'option', 'product_field'], true) || !empty($overrides[$fid]['bound'])) {
                $fixed[$i] = true;
                continue;
            }
            if (is_array($result->baseCoverage) && isset($result->baseCoverage[$fid])) {
                $share = min(1, $result->baseCoverage[$fid] / $total);
            } elseif ($item['type'] === 'range') {
                $fixed[$i] = true;
                continue;
            } else {
                $base = isset($result->baseCounts[$fid]) ? array_filter($result->baseCounts[$fid]) : [];
                // Сумма по значениям, не больше 100 % (у товара бывает несколько значений).
                $share = min(1, array_sum($base) / $total);
            }
            if ($share >= $minShare) {
                $candidates[$i] = $share;
            }
        }
        uksort($candidates, function ($a, $b) use ($candidates) {
            return [$candidates[$b], $a] <=> [$candidates[$a], $b];
        });
        $picked = array_slice($candidates, 0, $limit, true);
        $out = [];
        $rest = [];
        foreach ($facets as $i => $item) {
            if (isset($fixed[$i]) || isset($picked[$i])) {
                $out[] = $item;
            } else {
                $rest[] = $item;
            }
        }
        return [$out, $rest];
    }

    /**
     * Фасет с галочкой «В автоподборе — только под „Все фильтры“» (settings.rest_only): есть почти у всех товаров
     * (размеры, вес, гарантия, страна) и иначе вытеснял бы из основных профильные характеристики. В основные
     * попадает, только если в нем выбрано значение или он закреплен за разделом строкой «показать».
     *
     * @return bool
     */
    private function restOnly(array $defs, $fid)
    {
        return !empty($defs[$fid]['settings']['rest_only']);
    }

    /** @return float|int */
    private function round($v, $decimals, $mode)
    {
        $m = pow(10, $decimals);
        $v = $mode === 'floor' ? floor($v * $m) / $m : ceil($v * $m) / $m;
        return $decimals ? $v : (int)$v;
    }
}
