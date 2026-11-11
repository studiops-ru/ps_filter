<?php
namespace PsFilter\Url;

use PsFilter\Context;
use PsFilter\State;

/**
 * Ссылки на состояния фильтра.
 *
 * Базовый адрес страницы (категории или бренда) строится штатным url->link ОДИН раз,
 * дальше ссылки на сотни значений панели — склейка строк. SeoPro при выключенном кеше ЧПУ
 * делает SQL на каждый сегмент каждой ссылки, так что это заметная экономия.
 *
 * Режимы:
 *  - path:  /категория/подкатегория/filter/brand=titan,ural/price=100-500/?sort=…&page=2
 *  - query: штатный адрес страницы + &f=brand=titan,ural;price=100-500
 *           (включается сам, если ЧПУ выключен или у категории нет ключевого слова).
 * Отфильтрованный адрес в path-режиме всегда заканчивается «/»: так первая страница пагинации
 * (у которой штатная Pagination вырезает «?page={page}») совпадает с каноническим адресом.
 */
class Builder
{
    /** Параметры листинга, которые переносятся в ссылки, в каноническом порядке. */
    const LISTING_PARAMS = ['sort', 'order', 'limit', 'page'];

    /** @var Codec */
    private $codec;
    /** @var callable function(string $route, string $args): string — штатный url->link (сырые &) */
    private $link;
    /** @var string */
    private $prefix;
    /** @var string */
    private $postfix;
    /** @var bool */
    private $pathMode;
    /** @var array */
    private $base = [];
    /** @var bool config_seopro_addslash */
    private $addslash;
    /** @var string адрес главной (для посадочных в корне) */
    private $home = '';
    /** @var callable|null function(Context, State): ?array [keyword, url_mode] — посадочная для состояния */
    private $landing;
    /** @var array маршруты страниц: category, manufacturer, search */
    private $routes = ['category' => 'product/category', 'manufacturer' => 'product/manufacturer/info', 'search' => 'product/search'];
    /** @var string общие аргументы ссылок (OC4: language=ru-ru&) */
    private $commonArgs = '';

    /**
     * @param Codec    $codec
     * @param callable $link     function($route, $args) → абсолютный URL без &amp;
     * @param array    $options  prefix, postfix (config_page_postfix), path_mode (bool), addslash, home, landing,
     *                           routes (маршруты страниц), common_args (аргументы перед своими, например language=…)
     */
    public function __construct(Codec $codec, callable $link, array $options)
    {
        $this->codec = $codec;
        $this->link = $link;
        $this->prefix = isset($options['prefix']) && $options['prefix'] !== '' ? $options['prefix'] : 'filter';
        $this->postfix = isset($options['postfix']) ? (string)$options['postfix'] : '';
        $this->pathMode = !empty($options['path_mode']);
        $this->addslash = !empty($options['addslash']);
        $this->home = isset($options['home']) ? (string)$options['home'] : '';
        $this->landing = isset($options['landing']) && is_callable($options['landing']) ? $options['landing'] : null;
        if (isset($options['routes']) && is_array($options['routes'])) {
            $this->routes = array_replace($this->routes, $options['routes']);
        }
        $this->commonArgs = isset($options['common_args']) && $options['common_args'] !== '' ? rtrim($options['common_args'], '&') . '&' : '';
    }

    /** @return string */
    public function prefix()
    {
        return $this->prefix;
    }

    /** @return string адрес главной (корень для посадочных url_mode 1) */
    public function home()
    {
        return $this->home;
    }

    /**
     * Адрес состояния.
     *
     * @param Context $ctx
     * @param State   $state
     * @param array   $query    sort, order, limit, page (page=1 и пустые отбрасываются)
     * @param array   $override facet_id => сырая строка значения (шаблоны для JS)
     * @return string
     */
    public function url(Context $ctx, State $state, array $query = [], array $override = [])
    {
        $query = $this->cleanQuery($query);
        $tokens = $this->codec->tokens($state, $override);
        list($route, $args) = $this->routeArgs($ctx);

        if (!$tokens) {
            // Без фильтра — штатная ссылка, байт-в-байт как у темы (SeoPro решает про «/» и порядок).
            return $this->callLink($route, $args . ($query ? '&' . $this->buildQuery($query) : ''));
        }

        $base = $this->base($ctx);
        // Для состояния есть посадочная — ее адрес и есть канонический (кроме шаблонов JS с плейсхолдерами).
        if (!$override && $this->landing && $this->pathMode && strpos($base, '?') === false) {
            $page = call_user_func($this->landing, $ctx, $state);
            if ($page && $page['keyword'] !== '') {
                return $this->landingUrl($ctx, $page['keyword'], $page['url_mode'], $query);
            }
        }
        if ($this->pathMode && strpos($base, '?') === false) {
            $url = rtrim($base, '/') . '/' . $this->prefix . '/' . implode('/', array_map([$this, 'encodeToken'], $tokens)) . '/';
            return $query ? $url . '?' . $this->buildQuery($query) : $url;
        }

        return $this->callLink($route, $args . '&f=' . rawurlencode(implode(';', $tokens)) . ($query ? '&' . $this->buildQuery($query) : ''));
    }

    /**
     * Адрес посадочной: /раздел/ключ (url_mode 0) или /ключ (url_mode 1).
     * Без «/» перед query: первая страница пагинации (Pagination вырезает «?page={page}»)
     * совпадает с каноническим адресом.
     *
     * @return string
     */
    public function landingUrl(Context $ctx, $keyword, $urlMode, array $query = [])
    {
        $query = $this->cleanQuery($query);
        $root = (int)$urlMode === 1 ? rtrim($this->home !== '' ? $this->home : $this->base($ctx), '/') : rtrim($this->base($ctx), '/');
        $url = $root . '/' . rawurlencode($keyword) . ($this->addslash ? '/' : '');
        return $query ? $url . '?' . $this->buildQuery($query) : $url;
    }

    /**
     * Работает ли path-режим для этого контекста (ЧПУ есть у страницы).
     *
     * @return bool
     */
    public function isPath(Context $ctx)
    {
        return $this->pathMode && strpos($this->base($ctx), '?') === false;
    }

    /**
     * Базовый адрес страницы без фильтра и без постфикса (.html у брендов).
     *
     * @return string
     */
    public function base(Context $ctx)
    {
        $key = $ctx->scope();
        if (!isset($this->base[$key])) {
            list($route, $args) = $this->routeArgs($ctx);
            $url = $this->callLink($route, $args);
            if ($this->postfix !== '' && substr($url, -strlen($this->postfix)) === $this->postfix) {
                $url = substr($url, 0, -strlen($this->postfix));
            }
            $this->base[$key] = $url;
        }
        return $this->base[$key];
    }

    /** @return array [route, args] */
    private function routeArgs(Context $ctx)
    {
        if ($ctx->type === Context::MANUFACTURER) {
            return [$this->routes['manufacturer'], $this->commonArgs . 'manufacturer_id=' . (int)$ctx->id];
        }
        if ($ctx->type === Context::SEARCH) {
            // Как штатный search.php: urlencode(html_entity_decode(...)) для каждого переданного параметра.
            $args = [];
            foreach (['search', 'tag', 'description', 'category_id', 'sub_category'] as $k) {
                if (isset($ctx->search[$k])) {
                    $args[] = $k . '=' . urlencode(html_entity_decode((string)$ctx->search[$k], ENT_QUOTES, 'UTF-8'));
                }
            }
            return [$this->routes['search'], $this->commonArgs . implode('&', $args)];
        }
        return [$this->routes['category'], $this->commonArgs . 'path=' . ($ctx->path !== '' ? $ctx->path : (int)$ctx->id)];
    }

    /** @return string */
    private function callLink($route, $args)
    {
        return str_replace('&amp;', '&', call_user_func($this->link, $route, $args));
    }

    /**
     * Токен для сегмента пути: запятую и «=» оставляем читаемыми, остальное кодируем.
     *
     * @return string
     */
    private function encodeToken($token)
    {
        return str_replace(['%3D', '%2C'], ['=', ','], rawurlencode($token));
    }

    /** @return array */
    private function cleanQuery(array $query)
    {
        $out = [];
        foreach (self::LISTING_PARAMS as $k) {
            if (!isset($query[$k]) || $query[$k] === '' || $query[$k] === null) {
                continue;
            }
            $v = (string)$query[$k];
            // Мусор в параметрах листинга в адрес не переносим: штатный контроллер его все равно не поймет.
            if (($k === 'sort' && !preg_match('/^[a-z_]+(\.[a-z_]+)?$/i', $v))
                || ($k === 'order' && !in_array(strtoupper($v), ['ASC', 'DESC'], true))
                || ($k === 'limit' && (!ctype_digit($v) || (int)$v < 1 || (int)$v > 1000))
                || ($k === 'page' && $v !== '{page}' && (!ctype_digit($v) || (int)$v < 2))) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** @return string */
    private function buildQuery(array $query)
    {
        $parts = [];
        foreach ($query as $k => $v) {
            // {page} — плейсхолдер штатной Pagination, кодировать нельзя.
            $parts[] = $k . '=' . ($v === '{page}' ? $v : rawurlencode($v));
        }
        return implode('&', $parts);
    }
}
