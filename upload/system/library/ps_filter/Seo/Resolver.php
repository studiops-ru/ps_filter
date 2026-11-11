<?php
namespace PsFilter\Seo;

use PsFilter\Context;
use PsFilter\Engine\Result;
use PsFilter\Kernel;
use PsFilter\State;
use PsFilter\Url\Builder;

/**
 * SEO-решение для страницы с фильтром: индексировать ли, canonical, title, H1, текст.
 *
 * Правила:
 *  1. Без фильтра и посадочной — штатное поведение темы (решение null).
 *  2. Посадочная: index, если товаров не меньше seo_min_products (иначе noindex); self-canonical;
 *     тексты из страницы с плейсхолдерами.
 *  3. Комбинация подходит SEO-шаблону: index, self-canonical (адрес фильтра), мета из шаблона.
 *  4. Иначе noindex, follow; canonical — на раздел без фильтра (canonical_target = category)
 *     или на сам адрес (self).
 *  5. Сортировка, порядок и лимит в адресе — всегда noindex, canonical без них.
 * На страницах пагинации индексируемого состояния: canonical на себя (canonical_paginated = self)
 * или на первую страницу (first), prev/next — с состоянием фильтра.
 */
class Resolver
{
    /** @var Kernel */
    private $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * @param Context    $ctx
     * @param State      $state    примененное состояние
     * @param Result     $result
     * @param array|null $landing  посадочная (PageRepository) или null
     * @param Builder    $builder
     * @param array      $request  page, limit, sort, order, limit_default
     * @param array      $names    category, brand (названия раздела)
     * @return array|null robots, canonical, prev, next, title, description, keywords, h1, text, text_position, indexable, kind
     */
    public function decide(Context $ctx, State $state, Result $result, $landing, Builder $builder, array $request, array $names)
    {
        if ($state->isEmpty() && !$landing) {
            return null;
        }
        if ($ctx->type === Context::SEARCH) {
            // Поиск с фильтром: всегда noindex, canonical не ставим (как у штатной страницы поиска).
            return [
                'kind' => 'search', 'indexable' => false, 'robots' => 'noindex, follow', 'canonical' => null, 'prev' => null, 'next' => null,
                'title' => null, 'description' => null, 'keywords' => null, 'h1' => null, 'text' => null, 'text_position' => 0,
            ];
        }
        $settings = $this->k->settings();
        $defs = $this->k->facets()->snapshot($ctx->languageId)['facets'];
        $vars = $this->vars($ctx, $state, $result, $defs, $names);
        $page = max(1, (int)(isset($request['page']) ? $request['page'] : 1));
        $hasListingParams = !empty($request['sort']) || !empty($request['order']) || !empty($request['limit']);

        $out = [
            'kind' => 'filter', 'indexable' => false, 'robots' => 'noindex, follow',
            'canonical' => null, 'prev' => null, 'next' => null,
            'title' => null, 'description' => null, 'keywords' => null, 'h1' => null, 'text' => null, 'text_position' => 0,
        ];

        // Тексты, которые тема выводит без экранирования (title, h1, meta, крошки), — через Templates::text;
        // text — HTML посадочной, в нем экранируются только подстановки.
        if ($landing) {
            $d = $this->k->pages()->description($landing['page_id'], $ctx->languageId);
            $out['kind'] = 'landing';
            $out['indexable'] = (int)$result->total >= max(1, (int)$settings->get('seo_min_products', 1));
            $out['title'] = Templates::text(Templates::render($d['meta_title'] !== '' ? $d['meta_title'] : $d['name'], $vars));
            $out['h1'] = Templates::text(Templates::render($d['h1'] !== '' ? $d['h1'] : $d['name'], $vars));
            $out['description'] = Templates::text(Templates::render($d['meta_description'], $vars));
            $out['keywords'] = Templates::text(Templates::render($d['meta_keyword'], $vars));
            $out['text'] = Templates::render($d['description'], $vars);
            $out['text_position'] = (int)$d['text_position'];
            $out['name'] = Templates::text($d['name']);
        } else {
            $template = $this->k->templates()->match($ctx, $state, $defs, (int)$result->total, (int)$result->totalBase);
            if ($template) {
                $out['kind'] = 'template';
                $out['indexable'] = true;
                $out['title'] = Templates::text(Templates::render($template['title'], $vars));
                $out['h1'] = Templates::text(Templates::render($template['h1'], $vars));
                $out['description'] = Templates::text(Templates::render($template['meta_description'], $vars));
                $out['text'] = Templates::render($template['text'], $vars);
            }
        }

        $limit = (int)(!empty($request['limit']) ? $request['limit'] : (isset($request['limit_default']) ? $request['limit_default'] : 0));
        // Страница за последней (?page=99 у состояния на 3 страницы): товаров нет — не индексируем, canonical на первую.
        if ($out['indexable'] && $page > 1 && $limit > 0 && ($page - 1) * $limit >= (int)$result->total) {
            $out['indexable'] = false;
            $own = $landing ? $this->k->pages()->url($landing['page_id'], $ctx->storeId, $ctx->languageId) : null;
            $out['canonical'] = $own ? $builder->landingUrl($ctx, $own['keyword'], $own['url_mode']) : $builder->url($ctx, $state);
            $out['text'] = $out['text'] !== null ? '' : null;
            return $out;
        }

        if ($out['indexable']) {
            $out['robots'] = $hasListingParams ? 'noindex, follow' : 'index, follow';
            $selfPage = $page > 1 && $settings->get('canonical_paginated', 'self') === 'self' ? ['page' => $page] : [];
            // Адрес посадочной — свой, а не адрес фильтра по состоянию: выборка могла урезать состояние
            // (фасет не из раздела), и адрес по нему увел бы canonical с посадочной.
            $own = $landing ? $this->k->pages()->url($landing['page_id'], $ctx->storeId, $ctx->languageId) : null;
            $link = function (array $query) use ($own, $builder, $ctx, $state) {
                return $own ? $builder->landingUrl($ctx, $own['keyword'], $own['url_mode'], $query) : $builder->url($ctx, $state, $query);
            };
            $out['canonical'] = $link($selfPage);
            if ($page > 1 && !$hasListingParams) {
                $out['prev'] = $link($page > 2 ? ['page' => $page - 1] : []);
            }
            if ($limit > 0 && $page * $limit < (int)$result->total && !$hasListingParams) {
                $out['next'] = $link(['page' => $page + 1]);
            }
            if ($page > 1 && $out['text'] !== null) {
                // Текст посадочной — только на первой странице, иначе дубли.
                $out['text'] = '';
            }
        } else {
            $target = (string)$settings->get('canonical_target', 'category');
            $out['canonical'] = $target === 'self' ? $builder->url($ctx, $state) : $builder->url($ctx, new State());
        }
        return $out;
    }

    /**
     * Переменные для плейсхолдеров.
     *
     * @return array
     */
    public function vars(Context $ctx, State $state, Result $result, array $defs, array $names)
    {
        $values = [];
        $facets = [];
        $brand = isset($names['brand']) ? (string)$names['brand'] : '';
        foreach ($defs as $fid => $facet) {
            $sel = $state->get($fid);
            if ($sel === null || !isset($sel['ids'])) {
                continue;
            }
            $list = [];
            foreach ($facet['values'] as $vid => $v) {
                if (in_array((int)$vid, $sel['ids'], true)) {
                    $list[] = $v['name'];
                }
            }
            $facets[$facet['code']] = implode(', ', $list);
            $values = array_merge($values, $list);
            if ($facet['source'] === 'manufacturer' && $list) {
                $brand = implode(', ', $list);
            }
        }
        $minPrice = '';
        foreach ($defs as $fid => $facet) {
            if ($facet['source'] === 'price' && isset($result->ranges[$fid])) {
                $minPrice = (string)(int)floor($result->ranges[$fid][0] * (float)$ctx->currencyRate);
            }
        }
        return [
            'category'  => isset($names['category']) ? (string)$names['category'] : '',
            'brand'     => $brand,
            'values'    => implode(', ', $values),
            'facets'    => $facets,
            'count'     => (int)$result->total,
            'min_price' => $minPrice,
        ];
    }
}
