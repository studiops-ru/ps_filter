<?php
namespace PsFilter\Adapter\Oc3;

use PsFilter\Context;
use PsFilter\Kernel;
use PsFilter\Platform;
use PsFilter\Seo\PageRepository;
use PsFilter\State;
use PsFilter\Url\Builder;
use PsFilter\Url\Codec;
use PsFilter\View\Presenter;

/**
 * Связка ядра с витриной OpenCart 3: контекст из запроса, ссылки через штатный url->link,
 * параметры листинга. Все OpenCart-специфичное на витрине — здесь; при порте на OC4
 * меняется этот класс, а не движок.
 */
class Storefront
{
    /** @var Kernel */
    protected $k;
    /** @var \Registry */
    protected $r;
    /** @var Builder|null */
    protected $builder;
    /** @var Codec|null */
    protected $codec;

    public function __construct(Kernel $kernel, $registry)
    {
        $this->k = $kernel;
        $this->r = $registry;
    }

    /**
     * Контекст для маршрута витрины или null, если фильтр здесь не работает.
     *
     * @param string $route
     * @return Context|null
     */
    public function context($route)
    {
        $get = $this->r->get('request')->get;
        $config = $this->r->get('config');
        $ctx = new Context();

        if ($route === 'product/category' && !empty($get['path'])) {
            $parts = explode('_', (string)$get['path']);
            $ctx->type = Context::CATEGORY;
            $ctx->id = (int)end($parts);
            $ctx->path = implode('_', array_map('intval', $parts));
        } elseif ($route === Platform::route('manufacturer') && !empty($get['manufacturer_id'])) {
            $ctx->type = Context::MANUFACTURER;
            $ctx->id = (int)$get['manufacturer_id'];
        } elseif ($route === 'product/search' && (isset($get['search']) || isset($get['tag']))) {
            $ctx->type = Context::SEARCH;
            foreach (['search', 'tag', 'description', 'category_id', 'sub_category'] as $k) {
                if (isset($get[$k])) {
                    $ctx->search[$k] = (string)$get[$k];
                }
            }
            // Модуль ps_search, если стоит и готов, отдает найденное своим индексом (словоформы, опечатки)
            // вместе с порядком релевантности; иначе — условия штатного поиска.
            $seam = $this->searchSeam($ctx->search, (int)$config->get('config_language_id'), (int)$config->get('config_store_id'));
            if ($seam) {
                $ctx->searchSql = $seam['sql'];
                $ctx->searchRank = $seam['rank'];
            } else {
                $ctx->searchSql = $this->searchSql($ctx->search, (int)$config->get('config_language_id'));
            }
        } else {
            return null;
        }
        if ($ctx->id <= 0 && $ctx->type !== Context::SEARCH) {
            return null;
        }

        $customer = $this->r->get('customer');
        $session = $this->r->get('session');
        $ctx->storeId = (int)$config->get('config_store_id');
        $ctx->languageId = (int)$config->get('config_language_id');
        $ctx->customerGroupId = $customer && $customer->isLogged() ? (int)$customer->getGroupId() : (int)$config->get('config_customer_group_id');
        $this->k->applyStock($ctx);
        $ctx->subtree = (bool)$this->k->settings()->get('scope_subtree');
        $ctx->currency = isset($session->data['currency']) ? $session->data['currency'] : (string)$config->get('config_currency');
        $rate = (float)$this->r->get('currency')->getValue($ctx->currency);
        $ctx->currencyRate = $rate > 0 ? $rate : 1.0;
        $ctx->taxRates = $this->taxRates();
        return $ctx;
    }

    /**
     * Найденное модулем ps_search: детерминированный SELECT product_id по его сохраненному набору
     * (тот же set_id на время жизни набора — ключ кеша фильтра стабилен) и таблица позиций для сортировки.
     * Для робота или превысившего лимит ps_search набор не сохраняет: тогда SELECT по списку первых id
     * и rank['ids'] — порядок релевантности (SEARCH_SEAM = 2).
     * null — ps_search не установлен, выключен, индекс не готов или страница поиска отдана штатному поиску.
     *
     * @param array $s search, tag, description, category_id, sub_category
     * @return array|null ['sql' => string, 'rank' => array, 'total' => int]
     */
    protected function searchSeam(array $s, $languageId, $storeId)
    {
        // OC4: библиотека ps_search — в его расширении (extension/ps_search/system/library/ps_search).
        $file = \PsFilter\Platform::oc4() ? DIR_EXTENSION . 'ps_search/system/library/ps_search/bootstrap.php' : DIR_SYSTEM . 'library/ps_search/bootstrap.php';
        if (!$this->r->get('config')->get('module_ps_search_status') || !is_file($file)) {
            return null;
        }
        require_once $file;
        if (!function_exists('ps_search_seam')) {
            return null;
        }
        try {
            $seam = ps_search_seam($this->r, $s, (int)$languageId, (int)$storeId);
        } catch (\Exception $e) {
            $this->k->log('PS Filter: ps_search не отдал результаты поиска — ' . $e->getMessage());
            return null;
        }
        return is_array($seam) && !empty($seam['sql']) && !empty($seam['rank']) ? $seam : null;
    }

    /**
     * Условия штатного поиска (catalog/model/catalog/product.php getProducts) в виде SELECT product_id.
     * Значения берутся как в search.php — прямо из запроса (уже экранированы Request::clean),
     * поэтому выдача совпадает со штатной байт-в-байт. Статус, магазин и дата доступности —
     * общие условия раздела в движке.
     *
     * @param array $s search, tag, description, category_id, sub_category
     * @return string
     */
    public function searchSql(array $s, $languageId)
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        $name = isset($s['search']) ? $s['search'] : '';
        $tag = isset($s['tag']) ? $s['tag'] : $name;
        $sql = "SELECT DISTINCT p.product_id FROM `{$P}product` p
            JOIN `{$P}product_description` pd ON pd.product_id = p.product_id AND pd.language_id = " . (int)$languageId . " WHERE 1";

        if (!empty($s['category_id'])) {
            if (!empty($s['sub_category'])) {
                $sql .= " AND EXISTS (SELECT 1 FROM `{$P}product_to_category` p2c JOIN `{$P}category_path` cp ON cp.category_id = p2c.category_id
                    WHERE p2c.product_id = p.product_id AND cp.path_id = " . (int)$s['category_id'] . ")";
            } else {
                $sql .= " AND EXISTS (SELECT 1 FROM `{$P}product_to_category` p2c WHERE p2c.product_id = p.product_id AND p2c.category_id = " . (int)$s['category_id'] . ")";
            }
        }

        if ($name !== '' || $tag !== '') {
            $or = [];
            if ($name !== '') {
                $and = [];
                foreach (explode(' ', trim(preg_replace('/\s+/', ' ', $name))) as $word) {
                    $and[] = "pd.name LIKE '%" . $db->escape($word) . "%'";
                }
                $or[] = implode(' AND ', $and);
                if (!empty($s['description'])) {
                    $or[] = "pd.description LIKE '%" . $db->escape($name) . "%'";
                }
            }
            if ($tag !== '') {
                $and = [];
                foreach (explode(' ', trim(preg_replace('/\s+/', ' ', $tag))) as $word) {
                    $and[] = "pd.tag LIKE '%" . $db->escape($word) . "%'";
                }
                $or[] = implode(' AND ', $and);
            }
            if ($name !== '') {
                $lower = $db->escape(mb_strtolower($name, 'UTF-8'));
                foreach (['model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'] as $col) {
                    $or[] = "LCASE(p." . $col . ") = '" . $lower . "'";
                }
            }
            $sql .= " AND (" . implode(' OR ', $or) . ")";
        }
        return $sql;
    }

    /**
     * Множители налога по классам, как их применит витрина при показе цены (config_tax).
     * Ставка зависит от адреса покупателя в сессии, поэтому считаем через штатный $tax.
     *
     * @return array tax_class_id => множитель
     */
    protected function taxRates()
    {
        if (!$this->r->get('config')->get('config_tax')) {
            return [];
        }
        $tax = $this->r->get('tax');
        $rates = [];
        $q = $this->k->db()->query("SELECT DISTINCT tax_class_id FROM `" . $this->k->table('product') . "` WHERE tax_class_id > 0");
        foreach ($q->rows as $row) {
            $m = $tax->calculate(1000, (int)$row['tax_class_id'], true) / 1000;
            if (abs($m - 1) > 0.000001) {
                $rates[(int)$row['tax_class_id']] = round($m, 6);
            }
        }
        return $rates;
    }

    /** @return Codec */
    public function codec()
    {
        if ($this->codec === null) {
            $this->codec = new Codec($this->k->facets()->snapshot((int)$this->r->get('config')->get('config_language_id')));
        }
        return $this->codec;
    }

    /** @return Builder */
    public function builder()
    {
        if ($this->builder === null) {
            $config = $this->r->get('config');
            $url = $this->r->get('url');
            $pages = $this->k->pages();
            $this->builder = new Builder($this->codec(), function ($route, $args) use ($url) {
                return $url->link($route, $args);
            }, array_replace([
                'prefix'    => (string)$this->k->settings()->get('url_prefix', 'filter'),
                'postfix'   => (string)$config->get('config_page_postfix'),
                'path_mode' => $config->get('config_seo_url') && $this->k->settings()->get('url_mode') !== 'query',
                'addslash'  => (bool)$config->get('config_seopro_addslash'),
                'home'      => str_replace('&amp;', '&', $url->link('common/home')),
                // Посадочная для состояния: карта раздела грузится один раз на запрос (и кешируется).
                'landing'   => function (Context $ctx, State $state) use ($pages) {
                    $map = $pages->scopeMap($ctx->storeId, $ctx->languageId, self::scopeType($ctx), $ctx->id);
                    $hash = $state->hash();
                    return isset($map[$hash]) && $map[$hash]['keyword'] !== '' ? $map[$hash] : null;
                },
            ], $this->builderOptions()));
        }
        return $this->builder;
    }

    /**
     * Опции построителя ссылок, отличающиеся у версий OpenCart (поверх общих).
     *
     * @return array
     */
    protected function builderOptions()
    {
        return [];
    }

    /**
     * Контекст раздела по типу и id — для ссылок вне страницы раздела (редиректы, карта сайта).
     *
     * @return Context
     */
    public function scopeContext($scopeType, $scopeId)
    {
        $config = $this->r->get('config');
        $ctx = new Context();
        $ctx->storeId = (int)$config->get('config_store_id');
        $ctx->languageId = (int)$config->get('config_language_id');
        $ctx->customerGroupId = (int)$config->get('config_customer_group_id');
        if ((int)$scopeType === PageRepository::SCOPE_MANUFACTURER) {
            $ctx->type = Context::MANUFACTURER;
            $ctx->id = (int)$scopeId;
        } else {
            $ctx->type = Context::CATEGORY;
            $ctx->id = (int)$scopeId;
            $ctx->path = $this->categoryPath($scopeId);
        }
        return $ctx;
    }

    /**
     * Контекст раздела глазами гостя — с тем же ключом кеша, что посчитает витрина
     * для незалогиненного посетителя (налоги по адресу магазина, наличие, поддерево).
     *
     * @return Context
     */
    public function guestContext($scopeType, $scopeId)
    {
        $ctx = $this->scopeContext($scopeType, $scopeId);
        $this->k->applyStock($ctx);
        $ctx->subtree = (bool)$this->k->settings()->get('scope_subtree');
        $ctx->taxRates = $this->taxRates();
        return $ctx;
    }

    /**
     * Ссылки на посадочные раздела.
     *
     * @param string $flag in_panel | in_category
     * @return array [name, href, count, active]
     */
    public function landingLinks(Context $ctx, ?State $current = null, $flag = 'in_panel')
    {
        $hash = $current ? $current->hash() : '';
        $out = [];
        foreach ($this->k->pages()->links($ctx->storeId, $ctx->languageId, self::scopeType($ctx), $ctx->id, $flag) as $p) {
            $out[] = [
                'name'   => $p['name'],
                'href'   => $this->builder()->landingUrl($ctx, $p['keyword'], $p['url_mode']),
                'count'  => $p['count'],
                'active' => $p['state']->hash() === $hash,
            ];
        }
        return $out;
    }

    /**
     * Тип раздела для посадочных.
     *
     * @return int
     */
    public static function scopeType(Context $ctx)
    {
        return $ctx->type === Context::MANUFACTURER ? PageRepository::SCOPE_MANUFACTURER : PageRepository::SCOPE_CATEGORY;
    }

    /**
     * Названия раздела для плейсхолдеров: category (категория или бренд), brand.
     *
     * @return array
     */
    public function names(Context $ctx)
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        if ($ctx->type === Context::MANUFACTURER) {
            $q = $db->query("SELECT name FROM `{$P}manufacturer` WHERE manufacturer_id = " . (int)$ctx->id);
            $name = $q->num_rows ? $q->row['name'] : '';
            return ['category' => $name, 'brand' => $name];
        }
        $q = $db->query("SELECT name FROM `{$P}category_description` WHERE category_id = " . (int)$ctx->id . " AND language_id = " . (int)$ctx->languageId);
        return ['category' => $q->num_rows ? $q->row['name'] : '', 'brand' => ''];
    }

    /**
     * Полный path категории (1000_1001_1002) по ее id — для посадочных в корне сайта.
     *
     * @return string
     */
    public function categoryPath($categoryId)
    {
        $q = $this->k->db()->query("SELECT GROUP_CONCAT(path_id ORDER BY level SEPARATOR '_') AS p FROM `" . $this->k->prefix() . "category_path` WHERE category_id = " . (int)$categoryId);
        return $q->num_rows && $q->row['p'] ? $q->row['p'] : (string)(int)$categoryId;
    }

    /**
     * Параметры запроса для SEO-решения: страница, лимит, сортировка.
     *
     * @return array
     */
    public function seoRequest()
    {
        $get = $this->r->get('request')->get;
        $config = $this->r->get('config');
        return [
            'page'          => isset($get['page']) ? (int)$get['page'] : 1,
            'limit'         => isset($get['limit']) ? (int)$get['limit'] : 0,
            'sort'          => isset($get['sort']) ? (string)$get['sort'] : '',
            'order'         => isset($get['order']) ? (string)$get['order'] : '',
            // Тема без своего лимита (чужая тема, старая настройка) — общий лимит магазина, иначе 20:
            // без лимита не считаются «страница за последней» и rel=next.
            'limit_default' => (int)$config->get('theme_' . $config->get('config_theme') . '_product_limit')
                ?: ((int)$config->get('config_product_limit') ?: ((int)$config->get('config_pagination') ?: 20)),
        ];
    }

    /** @return Presenter */
    public function presenter()
    {
        $k = $this->k;
        $languageId = (int)$this->r->get('config')->get('config_language_id');
        return new Presenter($k->settings(), $this->builder(), function (Context $ctx, State $target, $count, $baseTotal = null) use ($k, $languageId) {
            $map = $k->pages()->scopeMap($ctx->storeId, $ctx->languageId, self::scopeType($ctx), $ctx->id);
            if (isset($map[$target->hash()]) && $map[$target->hash()]['keyword'] !== '') {
                return true;
            }
            return (bool)$k->templates()->match($ctx, $target, $k->facets()->snapshot($languageId)['facets'], $count, $baseTotal);
        }, function () use ($k) {
            return $k->facets()->pinnedEverywhere();
        });
    }

    /**
     * Параметры листинга из запроса, которые переносятся в ссылки фильтра (без page).
     *
     * @return array
     */
    public function listingQuery()
    {
        $get = $this->r->get('request')->get;
        $out = [];
        foreach (['sort', 'order', 'limit'] as $k) {
            if (isset($get[$k]) && $get[$k] !== '') {
                $out[$k] = (string)$get[$k];
            }
        }
        return $out;
    }

    /**
     * Текущий адрес запроса (абсолютный).
     *
     * @return string
     */
    public function currentUrl()
    {
        $server = $this->r->get('request')->server;
        $config = $this->r->get('config');
        $https = isset($server['HTTPS']) && ($server['HTTPS'] === 'on' || $server['HTTPS'] === '1' || $server['HTTPS'] === true);
        $base = $https ? $config->get('config_ssl') : $config->get('config_url');
        if (!$base) {
            $base = ($https ? 'https://' : 'http://') . (isset($server['HTTP_HOST']) ? $server['HTTP_HOST'] : '') . '/';
        }
        $parts = parse_url($base);
        $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'http') . '://' . (isset($parts['host']) ? $parts['host'] : '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        // Request::clean прогоняет и $_SERVER через htmlspecialchars: «&» в REQUEST_URI приходит как «&amp;».
        $uri = isset($server['REQUEST_URI']) ? htmlspecialchars_decode((string)$server['REQUEST_URI'], ENT_COMPAT) : '/';
        return $origin . $uri;
    }

    /**
     * Совпадает ли запрошенный адрес с каноническим: путь и значения известных параметров.
     * Посторонние параметры (utm_*, gclid) не считаются расхождением — их не режем редиректом.
     *
     * @param string $canonical
     * @return bool
     */
    public function isCanonical($canonical)
    {
        $want = parse_url($canonical);
        $have = parse_url($this->currentUrl());
        if (rawurldecode(isset($want['path']) ? $want['path'] : '/') !== rawurldecode(isset($have['path']) ? $have['path'] : '/')) {
            return false;
        }
        parse_str(isset($want['query']) ? $want['query'] : '', $wq);
        parse_str(isset($have['query']) ? $have['query'] : '', $hq);
        foreach ($this->canonicalParams() as $k) {
            $a = isset($wq[$k]) ? (string)$wq[$k] : null;
            $b = isset($hq[$k]) ? (string)$hq[$k] : null;
            if ($a !== $b) {
                return false;
            }
        }
        return true;
    }

    /**
     * Параметры запроса, расхождение в которых делает адрес неканоническим.
     *
     * @return string[]
     */
    protected function canonicalParams()
    {
        return ['f', 'sort', 'order', 'limit', 'page', 'route', 'path', 'manufacturer_id'];
    }

    /**
     * Каталог тем для include в twig: [папка темы, default].
     *
     * @return array
     */
    public function templateDirs()
    {
        $config = $this->r->get('config');
        $theme = $config->get('config_theme') === 'default' ? (string)$config->get('theme_default_directory') : (string)$config->get('config_theme');
        $dir = 'template/extension/module/ps_filter/';
        return [($theme ?: 'default') . '/' . $dir, 'default/' . $dir];
    }

    /** @return State */
    public function emptyState()
    {
        return new State();
    }
}
