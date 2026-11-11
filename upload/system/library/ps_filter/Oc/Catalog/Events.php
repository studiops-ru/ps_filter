<?php
namespace PsFilter\Oc\Catalog;

use PsFilter\Context;
use PsFilter\Oc\Base;
use PsFilter\Platform;
use PsFilter\Seo\PageRepository;

/**
 * Обработчики событий витрины.
 *
 * Поток запроса страницы с фильтром:
 *   1. ЧПУ (SeoPro в OC3, startup/seo_url в OC4) не знает сегменты /filter/…, ставит error/not_found,
 *      но path категории или manufacturer_id уже разобраны;
 *   2. notFound перероучивает запрос на страницу раздела (через &$route) и разбирает состояние;
 *   3. routeBefore строит контекст, проверяет канонический адрес (301), вешает перехват выборки;
 *   4. getProducts/getTotal отдают товары из движка вместо штатного SQL;
 *   5. viewBefore пересобирает ссылки сортировки, лимита и пагинации с состоянием фильтра;
 *   6. header ставит canonical и robots.
 * AJAX-запрос (заголовок X-PSF: fragment) идет тем же путем, но обвязка страницы подавляется,
 * а ответ — JSON с отрендеренным контентом страницы и панелью.
 *
 * Подмена результата: OC3 берет непустой ответ обработчика «before» вместо вызова;
 * OC4 ответы обработчиков игнорирует, зато дает сменить &$route — вызов уходит в метод модуля
 * (модель extension/ps_filter/module/ps_filter или пустой метод контроллера событий).
 */
class Events extends Base
{
    /* ===================== Маршрутизация ===================== */

    /**
     * controller/error/not_found/before — разбор ЧПУ фильтра и посадочных.
     *
     *   /категория/filter/brand=titan/  — состояние фильтра после префикса;
     *   /категория/ключ                 — посадочная под разделом (url_mode 0);
     *   /ключ                           — посадочная в корне сайта (url_mode 1);
     *   старое ключевое слово           — 301 на текущий адрес посадочной.
     * В OC4 перед этим идут сегменты языка и маршрута (/ru-ru/catalog/…).
     */
    public function notFound(&$route, &$data)
    {
        if (!$this->enabled()) {
            return null;
        }
        $this->kernel()->runtime()->routed = true;
        $kernel = $this->kernel();
        $prefix = strtolower((string)$kernel->settings()->get('url_prefix', 'filter'));
        $routeStr = isset($this->request->get['_route_']) ? (string)$this->request->get['_route_'] : '';
        if ($routeStr === '' && !Platform::oc4()) {
            // SEO Pro в некоторых русских сборках OpenCart 3 стирает _route_, а при незнакомом сегменте не оставляет и path — разбираем сами.
            $routeStr = $this->requestPath();
            if ($routeStr !== '' && empty($this->request->get['path']) && empty($this->request->get['manufacturer_id'])) {
                $this->resolveScope($routeStr, $prefix);
            }
        }
        if ($routeStr === '') {
            return null;
        }
        $front = $kernel->storefront();
        $parts = array_values(array_filter(explode('/', trim($routeStr, '/')), 'strlen'));
        $lower = array_map('strtolower', $parts);

        $target = null;
        $expected = 0;
        $scopeType = 0;
        $scopeId = 0;
        if (!empty($this->request->get['path'])) {
            $target = Platform::route('category');
            $ids = explode('_', (string)$this->request->get['path']);
            $expected = count($ids);
            $scopeType = PageRepository::SCOPE_CATEGORY;
            $scopeId = (int)end($ids);
        } elseif (!empty($this->request->get['manufacturer_id'])) {
            $target = Platform::route('manufacturer');
            $expected = 1;
            $scopeType = PageRepository::SCOPE_MANUFACTURER;
            $scopeId = (int)$this->request->get['manufacturer_id'];
        }

        $runtime = $kernel->runtime();
        $idx = array_search($prefix, $lower, true);
        if ($idx !== false) {
            // Между страницей и префиксом не должно быть посторонних сегментов.
            if (!$target || !$this->pageMatches(array_slice($parts, 0, $idx), $scopeType, $scopeId, $expected)) {
                return null;
            }
            $dirty = false;
            $tokens = array_slice($parts, $idx + 1);
            $runtime->state = $front->codec()->decode($tokens, $dirty);
            // «/filter/» без выбора — неканоническая форма адреса раздела.
            $runtime->dirty = $dirty || !$tokens;
        } else {
            // Посадочная: ровно один неизвестный сегмент после раздела (или после главной — в корне).
            if (!$this->pageMatches(array_slice($parts, 0, -1), $scopeType, $scopeId, $expected)) {
                return null;
            }
            $keyword = mb_strtolower(rawurldecode(end($parts)), 'UTF-8');
            $storeId = (int)$this->config->get('config_store_id');
            $languageId = (int)$this->config->get('config_language_id');
            $urlMode = $target ? 0 : 1;
            $pages = $kernel->pages();
            $page = $pages->findByKeyword($storeId, $languageId, $urlMode, $scopeType, $scopeId, $keyword);
            if (!$page) {
                $redirect = $pages->findRedirect($storeId, $languageId, $urlMode, $scopeType, $scopeId, $keyword);
                $to = $redirect ? $this->landingHref($redirect['page_id']) : null;
                if ($to) {
                    $this->response->redirect($to, 301);
                }
                return null;
            }
            if (!$target) {
                // Посадочная в корне: раздел берется из самой страницы.
                if ($page['scope_type'] === PageRepository::SCOPE_MANUFACTURER) {
                    $target = Platform::route('manufacturer');
                    $this->request->get['manufacturer_id'] = $page['scope_id'];
                } else {
                    $target = Platform::route('category');
                    $this->request->get['path'] = $front->categoryPath($page['scope_id']);
                }
            }
            $runtime->state = $page['state_obj'];
            $runtime->landing = $page;
        }

        $route = $target;
        $this->request->get['route'] = $target;
        // Событие controller/<новый маршрут>/before уже не сработает: маршрутизатор его пропустил.
        return $this->routeBefore($route, $data);
    }

    /** Путь запроса без адреса магазина и параметров: «men/obuv/filter/brand=nike». '' — главная или index.php. */
    private function requestPath()
    {
        $uri = isset($this->request->server['REQUEST_URI']) ? (string)$this->request->server['REQUEST_URI'] : '';
        $path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
        $base = (string)parse_url((string)$this->config->get('config_url'), PHP_URL_PATH);
        if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        $path = trim($path, '/');
        return $path === 'index.php' ? '' : $path;
    }

    /**
     * Раздел или бренд по ключевым словам ЧПУ до префикса фильтра (до ключа посадочной, если префикса нет):
     * каждый сегмент — ключ категории (path по порядку), либо ключи маршрутов и последним — ключ производителя.
     * Посторонний сегмент — не наш адрес, ничего не ставим.
     */
    private function resolveScope($routeStr, $prefix)
    {
        $parts = array_values(array_filter(explode('/', trim($routeStr, '/')), 'strlen'));
        $idx = array_search($prefix, array_map('strtolower', $parts), true);
        $head = $idx === false ? array_slice($parts, 0, -1) : array_slice($parts, 0, $idx);
        if (!$head) {
            return;
        }
        $keys = array_map(function ($k) { return mb_strtolower($k, 'UTF-8'); }, $head);
        $map = [];
        $q = $this->db->query("SELECT keyword, query FROM `" . DB_PREFIX . "seo_url` WHERE store_id = " . (int)$this->config->get('config_store_id')
            . " AND language_id = " . (int)$this->config->get('config_language_id')
            . " AND keyword IN ('" . implode("','", array_map([$this->db, 'escape'], $keys)) . "')");
        foreach ($q->rows as $r) {
            $map[mb_strtolower($r['keyword'], 'UTF-8')] = (string)$r['query'];
        }
        $ids = [];
        $last = count($keys) - 1;
        foreach ($keys as $i => $k) {
            $query = isset($map[$k]) ? $map[$k] : '';
            if (strpos($query, 'category_id=') === 0) {
                $ids[] = (int)substr($query, 12);
            } elseif (strpos($query, 'manufacturer_id=') === 0 && $i === $last && !$ids) {
                $this->request->get['manufacturer_id'] = (int)substr($query, 16);
                return;
            } elseif ($query === '' || strpos($query, '=') !== false || $ids) {
                return;
            }
        }
        if ($ids) {
            $this->request->get['path'] = implode('_', $ids);
        }
    }

    /**
     * language/{product/category,product/manufacturer,product/search,error/not_found}/before — запасной вход.
     *
     * SEO Pro в некоторых русских сборках OpenCart 3 (startup/seo_pro возвращает new Action(маршрут) из pre_action) запускает страницу
     * в обход startup/router, и событие controller/<страница>/before не срабатывает: ни разбора /filter/, ни
     * контекста, ни панели. Первая строка контроллера страницы грузит ее язык — разбор идет отсюда. Только для
     * главного маршрута запроса и только если обычный вход не сработал (routed). Что страница уже не отдаст
     * сама — ответ счетчиков «Показать N» и раздел вместо 404 по адресу фильтра, — отдается здесь и запрос
     * заканчивается.
     */
    public function languageBefore(&$route, &$key)
    {
        if (!$this->enabled() || $this->kernel()->runtime()->routed) {
            return null;
        }
        $pages = ['product/category' => 'product/category', 'product/manufacturer/info' => 'product/manufacturer',
            'product/search' => 'product/search', 'error/not_found' => 'error/not_found'];
        $main = isset($this->request->get['route']) ? (string)$this->request->get['route'] : '';
        if (!isset($pages[$main]) || $pages[$main] !== $route) {
            return null;
        }
        $page = $main;
        $data = [];
        if ($main === 'error/not_found') {
            $result = $this->notFound($page, $data);
            if ($page === 'error/not_found') {
                return null; // не адрес фильтра и не посадочная — обычная 404
            }
            if ($result === null) {
                // Контроллер ошибки уже идет — страницу раздела с выбором отрисовываем сами.
                $this->load->controller($page);
            }
        } elseif ($this->routeBefore($page, $data) === null) {
            return null;
        }
        $this->response->output();
        exit;
    }

    /**
     * Сегменты адреса до фильтра (или до ключа посадочной) — ровно адрес страницы раздела.
     * OC3 (SeoPro): по сегменту на уровень категории. OC4: сверяем с путем штатной ссылки —
     * в нем еще язык и ключ маршрута, а ключ категории может быть составным.
     */
    private function pageMatches(array $segments, $scopeType, $scopeId, $expected)
    {
        // OC3: по сегменту на уровень категории; иначе (бренд под ключом раздела — /brands/nike/) — как в OC4.
        if (!Platform::oc4() && count($segments) === $expected) {
            return true;
        }
        $builder = $this->kernel()->storefront()->builder();
        $url = $scopeType ? $builder->base($this->kernel()->storefront()->scopeContext($scopeType, $scopeId)) : $builder->home();
        $path = mb_strtolower(trim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/'), 'UTF-8');
        $want = mb_strtolower(trim(rawurldecode(implode('/', $segments)), '/'), 'UTF-8');
        if ($want === '') {
            return !$scopeType;
        }
        return $path === $want || substr($path, -strlen($want) - 1) === '/' . $want;
    }

    /** controller/product/{category,manufacturer/info,search}/before — контекст, состояние, канонизация. */
    public function routeBefore(&$route, &$data)
    {
        if (!$this->enabled()) {
            return null;
        }
        $kernel = $this->kernel();
        $runtime = $kernel->runtime();
        $runtime->routed = true;
        if ($runtime->context !== null) {
            return null; // уже настроено (вложенный вызов)
        }
        $front = $kernel->storefront();
        $ctx = $front->context($route);
        if ($ctx === null) {
            return null;
        }

        $state = $runtime->state;
        $dirty = !empty($runtime->dirty);
        if ($state === null) {
            if (isset($this->request->get['f']) && $this->request->get['f'] !== '') {
                $state = $front->codec()->decodeQuery(html_entity_decode((string)$this->request->get['f'], ENT_QUOTES, 'UTF-8'), $dirty);
            } else {
                $state = $front->emptyState();
            }
        }
        $runtime->context = $ctx;
        $runtime->state = $state;
        // Продажи за период («Популярные», ps_filter_sold) — раз в сутки при первом запросе выдачи, без cron.
        // Сбой пересчета не должен ломать витрину: старые числа остаются, причина — в журнале.
        try {
            $kernel->sales()->refreshIfDue();
        } catch (\Exception $e) {
            $kernel->log('ps_filter: пересчет продаж не удался: ' . $e->getMessage());
        }
        // X-PSF: fragment — JSON с контентом страницы и панелью (клик с автоприменением);
        // X-PSF: counters — только панель и число товаров (режим «Показать N», страница не рендерится).
        $psf = isset($this->request->server['HTTP_X_PSF']) ? (string)$this->request->server['HTTP_X_PSF'] : '';
        $runtime->fragment = $psf === 'fragment';
        $isSearch = $ctx->type === Context::SEARCH;

        if ($psf === 'counters') {
            $result = $kernel->engine()->search($ctx, $state);
            $runtime->result = $result;
            $this->response->addHeader('Content-Type: application/json; charset=utf-8');
            $this->response->addHeader('Cache-Control: no-store');
            $this->response->addHeader('Vary: X-PSF, X-PSF-Rest');
            $this->response->setOutput(json_encode([
                'ok'    => true,
                'url'   => $front->builder()->url($ctx, $result->state ?: $state, $front->listingQuery()),
                // only_instock_facets без фильтра — число всей выдачи раздела, а не товаров в наличии.
                'total' => $ctx->listAll && $state->isEmpty()
                    ? (int)$kernel->engine()->search($this->listContext($ctx, $state), $state)->total : (int)$result->total,
                'panel' => $this->load->controller($this->route('base')),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // Страницу не выполняем: OC3 — ненулевой ответ обработчика, OC4 — пустой метод вместо страницы.
            if (Platform::oc4()) {
                $route = $this->route('event', 'output');
                return null;
            }
            return true;
        }

        // Выбор по фасетам, которых нет в разделе, выборка отбросит; адрес с ним — мусор, канонический строится без него.
        if (!$isSearch && !$state->isEmpty() && empty($runtime->landing)) {
            $restricted = $kernel->engine()->restrict($ctx, $state);
            if ($restricted->count() !== $state->count()) {
                $state = $restricted;
                $runtime->state = $state;
                $dirty = true;
            }
        }

        // Канонический адрес: один выбор — один URL. Иначе 301 (кроме AJAX — его пусть отрисует).
        // Поиск всегда noindex и живет на параметрах — его не канонизируем.
        $hasFilterInUrl = !$state->isEmpty() || $dirty || isset($this->request->get['f']);
        if ($hasFilterInUrl && !$isSearch && !$runtime->fragment && $this->request->server['REQUEST_METHOD'] === 'GET') {
            $query = $front->listingQuery();
            if (isset($this->request->get['page']) && (int)$this->request->get['page'] > 1) {
                $query['page'] = (int)$this->request->get['page'];
            }
            $canonical = $front->builder()->url($ctx, $state, $query);
            if ($dirty || !$front->isCanonical($canonical)) {
                $this->response->redirect($canonical, 301);
            }
        }

        // Перехват выборки нужен, только если штатный SQL дал бы другой результат
        // (на поиске с ps_search — всегда: найденное и порядок берутся из его набора;
        // пресет «По умолчанию» из настроек — и на странице раздела без фильтра; свои ключи сортировки
        // popular и p.viewed — штатный getProducts их не знает и молча сортировал бы по sort_order).
        $preset = $ctx->type !== Context::SEARCH && (string)$kernel->settings()->get('default_sort', 'default') !== 'default';
        $ownSort = isset($this->request->get['sort']) && in_array((string)$this->request->get['sort'], ['popular', 'p.viewed'], true);
        // При группировке выдачи (group_by) — всегда: штатный getProducts отдал бы страницу по товарам, а не по группам.
        // У производителя с исключенными разделами (manufacturer_exclude_categories) — тоже: штатный SQL их не знает.
        $excluded = (bool)$kernel->applyScope($ctx)->excludeCategories;
        // only_instock_facets: без фильтра выдача — все товары, как штатно; наличие держит только фасеты.
        $instock = $ctx->onlyInstock && !($ctx->listAll && $state->isEmpty());
        if (!$state->isEmpty() || $ctx->subtree || $instock || $ctx->searchRank || $preset || $ownSort || $kernel->groupField() !== '' || $excluded) {
            $model = Platform::oc4() ? 'model/catalog/product.' : 'model/catalog/product/';
            $this->listen($model . 'getProducts/before', 'getProducts');
            $this->listen($model . 'getTotalProducts/before', 'getTotal');
        }
        if ($runtime->fragment) {
            $views = [Context::MANUFACTURER => 'product/manufacturer_info', Context::SEARCH => 'product/search'];
            $view = isset($views[$ctx->type]) ? $views[$ctx->type] : 'product/category';
            $this->listen('view/' . $view . '/after', 'fragmentAfter');
        } else {
            $assets = Platform::oc4() ? 'extension/ps_filter/catalog/view/javascript/ps_filter/' : 'catalog/view/javascript/ps_filter/';
            $this->document->addStyle($assets . 'ps_filter.css?v=' . \PsFilter\Kernel::VERSION);
            $this->document->addScript($assets . 'ps_filter.js?v=' . \PsFilter\Kernel::VERSION, 'footer');
        }
        return null;
    }

    /* ===================== Выборка товаров ===================== */

    /** model/catalog/product/getProducts/before (регистрируется только на страницах с фильтром). */
    public function getProducts(&$route, &$args)
    {
        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
        $kernel = $this->kernel();
        $ctx = $kernel->runtime()->context;
        if (!$ctx || !$this->ours($data, $ctx)) {
            return null;
        }
        $sort = isset($data['sort']) ? $data['sort'] : 'p.sort_order';
        $order = isset($data['order']) ? $data['order'] : 'ASC';
        // «По умолчанию» в разделе и у производителя — как выбрано в настройках; на поиске это релевантность.
        // «Популярные» — по продажам или просмотрам (настройка popular_by), ключ popular понимают оба движка.
        $defaults = ['popular' => ['popular', 'DESC'], 'new' => ['p.date_added', 'DESC'], 'cheap' => ['p.price', 'ASC'], 'name' => ['pd.name', 'ASC']];
        $preset = (string)$kernel->settings()->get('default_sort', 'default');
        if ($sort === 'p.sort_order' && $ctx->type !== Context::SEARCH && isset($defaults[$preset])) {
            list($sort, $order) = $defaults[$preset];
        }
        // Какой сортировкой отдана выдача — шаблону (psf_sort / psf_order), когда sort в адресе нет.
        $kernel->runtime()->sort = $ctx->type === Context::SEARCH && $ctx->searchRank && $sort === 'p.sort_order'
            ? ['relevance', 'DESC'] : [$sort, strtoupper($order) === 'DESC' ? 'DESC' : 'ASC'];
        $result = $kernel->engine()->search($this->listContext($ctx, $kernel->runtime()->state), $kernel->runtime()->state, [
            'sort'  => $sort,
            'order' => $order,
            'start' => isset($data['start']) ? (int)$data['start'] : 0,
            'limit' => isset($data['limit']) ? (int)$data['limit'] : 20,
        ]);
        $kernel->runtime()->result = $result;

        $products = [];
        foreach ((array)$result->productIds as $id) {
            $product = $this->model_catalog_product->getProduct($id);
            if ($product) {
                $products[$id] = $product;
            }
        }
        if (Platform::oc4()) {
            // Вызов уходит в модель модуля, она отдаст подготовленный список.
            $kernel->runtime()->products = $products;
            $route = $this->route('base', 'getProducts');
            return null;
        }
        if (!$products) {
            // Пустой массив модель приняла бы за «не перехвачено» и выполнила штатный SQL.
            $this->emptyResult($args, $ctx);
            return null;
        }
        return $products;
    }

    /**
     * Контекст выдачи: при only_instock_facets без выбранного фильтра — все товары раздела (фасеты и числа панели
     * по-прежнему по товарам в наличии — их считает Panel по контексту страницы).
     *
     * @return Context
     */
    private function listContext(Context $ctx, $state)
    {
        if (!$ctx->listAll || ($state && !$state->isEmpty())) {
            return $ctx;
        }
        $all = clone $ctx;
        $all->onlyInstock = false;
        $all->listAll = false;
        return $all;
    }

    /** model/catalog/product/getTotalProducts/before. */
    public function getTotal(&$route, &$args)
    {
        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
        $kernel = $this->kernel();
        $ctx = $kernel->runtime()->context;
        if (!$ctx || !$this->ours($data, $ctx)) {
            return null;
        }
        $result = $kernel->engine()->search($this->listContext($ctx, $kernel->runtime()->state), $kernel->runtime()->state);
        if (!$kernel->runtime()->result) {
            $kernel->runtime()->result = $result;
        }
        if (Platform::oc4()) {
            $kernel->runtime()->total = (int)$result->total;
            $route = $this->route('base', 'getTotalProducts');
            return null;
        }
        if (!$result->total) {
            $this->emptyResult($args, $ctx);
            return null;
        }
        return (int)$result->total;
    }

    /**
     * model/catalog/product/getTotalProducts/before (всегда) — число товаров категории с подкатегориями
     * для меню и списка подкатегорий из индекса фильтра (CategoryCounts) вместо штатного COUNT.
     */
    public function categoryCount(&$route, &$args)
    {
        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
        if (!\PsFilter\CategoryCounts::applies($data) || !$this->enabled()) {
            return null;
        }
        $kernel = $this->kernel();
        if (!$kernel->settings()->get('fast_category_counts', 1)) {
            return null;
        }
        $n = $kernel->categoryCounts()->get((int)$data['filter_category_id'], (int)$this->config->get('config_store_id'));
        if (Platform::oc4()) {
            $kernel->runtime()->total = $n;
            $route = $this->route('base', 'getTotalProducts');
            return null;
        }
        // OC3: загрузчик берет ответ, только если он «истинный» — для 0 (и «0») выполнится штатный SQL.
        // Пустых категорий мало, и COUNT по ним дешевый, поэтому так и оставляем.
        return $n ?: null;
    }

    /* ===================== Шаблоны и SEO ===================== */

    /**
     * view/product/{category,manufacturer_info,search}/before — ссылки листинга с состоянием фильтра,
     * H1, текст и крошка посадочной, ссылки на посадочные для темы ({{ psf_landings }}).
     */
    public function viewBefore(&$route, &$data)
    {
        $kernel = $this->kernel();
        $runtime = $kernel->runtime();
        if (!$runtime->context || !$runtime->state) {
            return null;
        }
        $ctx = $runtime->context;
        $state = $runtime->state;
        $builder = $kernel->storefront()->builder();

        // Ссылки на посадочные раздела для вывода над/под листингом в теме: {{ psf_landings }}.
        $data['psf_landings'] = $this->landingLinks('in_category');
        // Какой сортировкой отдана выдача — и когда sort в адресе нет (тема отмечает текущую в своем списке):
        // popular, p.price, p.date_added, pd.name, p.sort_order…; на поиске с ps_search по умолчанию relevance.
        // Без перехвата выборки — из адреса, иначе штатная p.sort_order/ASC. Только [a-z_.]: OC3 не экранирует.
        $sort = $runtime->sort;
        if (!$sort) {
            $get = $this->request->get;
            $sort = [isset($get['sort']) ? (string)$get['sort'] : '', isset($get['order']) && strtoupper((string)$get['order']) === 'DESC' ? 'DESC' : 'ASC'];
        }
        $data['psf_sort'] = preg_match('/^[a-z_.]{1,32}$/', $sort[0]) ? $sort[0] : 'p.sort_order';
        $data['psf_order'] = $sort[1];
        // mount_mode = template: панель выводит сама тема ({{ ps_filter }} в category.twig и т.п.),
        // например над товарами на мобильных, где column_left темы скрыт.
        if ($kernel->settings()->get('mount_mode') === 'template') {
            $data['ps_filter'] = $this->load->controller($this->route('base'));
        }
        $this->listingVars($data, $ctx, $state);

        if ($state->isEmpty()) {
            return null;
        }

        $seo = $this->seoDecision();
        $data['psf_seo_text'] = '';
        if ($seo) {
            if ($seo['h1']) {
                $data['heading_title'] = $seo['h1'];
            }
            if ($seo['text'] !== null && $seo['text'] !== '') {
                if ((int)$seo['text_position'] === 0) {
                    $data['description'] = $seo['text'];
                } else {
                    $data['psf_seo_text'] = $seo['text'];
                }
            }
            if ($seo['kind'] === 'landing' && isset($data['breadcrumbs'])) {
                $data['breadcrumbs'][] = ['text' => $seo['h1'] ?: $seo['name'], 'href' => $seo['canonical']];
            }
        }
        $get = $this->request->get;
        $limit = isset($data['limit']) ? (int)$data['limit'] : (isset($get['limit']) ? (int)$get['limit'] : 0);
        if (!$limit && Platform::oc4()) {
            $limit = (int)$this->config->get('config_pagination');
        }

        if (!empty($data['sorts'])) {
            foreach ($data['sorts'] as &$sort) {
                $pos = strrpos($sort['value'], '-');
                $sort['href'] = $builder->url($ctx, $state, [
                    'sort'  => substr($sort['value'], 0, $pos),
                    'order' => substr($sort['value'], $pos + 1),
                    'limit' => isset($get['limit']) ? $get['limit'] : '',
                ]);
            }
            unset($sort);
        }
        if (!empty($data['limits'])) {
            foreach ($data['limits'] as &$item) {
                $item['href'] = $builder->url($ctx, $state, [
                    'sort'  => isset($get['sort']) ? $get['sort'] : '',
                    'order' => isset($get['order']) ? $get['order'] : '',
                    'limit' => $item['value'],
                ]);
            }
            unset($item);
        }
        if (isset($data['pagination']) && $limit > 0) {
            $url = $builder->url($ctx, $state, [
                'sort'  => isset($get['sort']) ? $get['sort'] : '',
                'order' => isset($get['order']) ? $get['order'] : '',
                'limit' => isset($get['limit']) ? $get['limit'] : '',
                'page'  => '{page}',
            ]);
            $data['pagination'] = $this->pagination($runtime->result ? (int)$runtime->result->total : 0, isset($get['page']) ? max(1, (int)$get['page']) : 1, $limit,
                Platform::oc4() ? $url : str_replace('&', '&amp;', $url));
        }
        return null;
    }

    /**
     * Данные выдачи для тем со своей пагинацией («Показать еще», счетчик «Показано 25–36 из 434»):
     * psf_total, psf_selected (сколько значений выбрано), psf_page, psf_pages, psf_limit, psf_from, psf_to и адреса psf_next / psf_prev / psf_first
     * с состоянием фильтра и сортировкой (пусто, если страницы нет). Ставятся всегда, когда фильтр отдал выдачу.
     */
    private function listingVars(array &$data, Context $ctx, $state)
    {
        $result = $this->kernel()->runtime()->result;
        if (!$result) {
            return;
        }
        $get = $this->request->get;
        $limit = isset($data['limit']) ? (int)$data['limit'] : (isset($get['limit']) ? (int)$get['limit'] : 0);
        if ($limit <= 0) {
            $theme = (string)$this->config->get('config_theme');
            $limit = (int)$this->config->get('theme_' . $theme . '_product_limit') ?: (int)$this->config->get('config_pagination');
        }
        $limit = max(1, $limit ?: 20);
        $total = (int)$result->total;
        $page = isset($get['page']) ? max(1, (int)$get['page']) : 1;
        $pages = max(1, (int)ceil($total / $limit));
        $builder = $this->kernel()->storefront()->builder();
        $link = function ($n) use ($builder, $ctx, $state, $get) {
            $query = [
                'sort'  => isset($get['sort']) ? $get['sort'] : '',
                'order' => isset($get['order']) ? $get['order'] : '',
                'limit' => isset($get['limit']) ? $get['limit'] : '',
            ];
            if ($n > 1) {
                $query['page'] = $n;
            }
            return $builder->url($ctx, $state, $query);
        };
        $data['psf_total'] = $total;
        $data['psf_selected'] = $state->count();
        $data['psf_page'] = $page;
        $data['psf_pages'] = $pages;
        $data['psf_limit'] = $limit;
        $data['psf_from'] = $total ? min($total, ($page - 1) * $limit + 1) : 0;
        $data['psf_to'] = min($total, $page * $limit);
        $data['psf_next'] = $page < $pages ? $link($page + 1) : '';
        $data['psf_prev'] = $page > 1 ? $link($page - 1) : '';
        $data['psf_first'] = $link(1);
    }

    /**
     * view/common/header/before — canonical и robots отфильтрованных страниц.
     * Document::addLink не умеет удалять ссылки, поэтому правим уже собранные $data['links'].
     */
    public function header(&$route, &$data)
    {
        $seo = $this->seoDecision();
        if (!$seo) {
            return null;
        }
        $links = [];
        foreach (isset($data['links']) ? $data['links'] : [] as $link) {
            if (!in_array($link['rel'], ['canonical', 'prev', 'next'], true)) {
                $links[] = $link;
            }
        }
        foreach (['canonical', 'prev', 'next'] as $rel) {
            if ($seo[$rel]) {
                $links[] = ['href' => $seo[$rel], 'rel' => $rel];
            }
        }
        $data['links'] = $links;
        $data['robots'] = $seo['robots'];
        if ($seo['title']) {
            $data['title'] = $seo['title'];
        }
        if ($seo['description'] !== null && $seo['description'] !== '') {
            $data['description'] = $seo['description'];
        }
        if ($seo['keywords'] !== null && $seo['keywords'] !== '') {
            $data['keywords'] = $seo['keywords'];
        }
        if (!$seo['indexable']) {
            $this->response->addHeader('X-Robots-Tag: noindex, follow');
        }
        return null;
    }

    /**
     * view/common/header/after — meta robots, если шаблон шапки сам его не выводит
     * (default OC3 и OC4 не выводят; часть сборок OpenCart 3 выводит из $data['robots']).
     */
    public function headerAfter(&$route, &$data, &$output)
    {
        $seo = $this->seoDecision();
        if (!$seo || !$seo['robots'] || stripos($output, 'name="robots"') !== false) {
            return null;
        }
        $meta = '<meta name="robots" content="' . htmlspecialchars($seo['robots'], ENT_QUOTES, 'UTF-8') . '"/>';
        // По ссылке, без ответа: в OC3 ответ остановил бы остальные обработчики шапки.
        $output = preg_replace('~</head>~i', $meta . "\n</head>", $output, 1);
        return null;
    }

    /** controller/common/{header,footer,column_*,content_*}/before — пустая обвязка в AJAX-ответе. */
    public function suppress(&$route, &$data)
    {
        if (!$this->kernel()->runtime()->fragment) {
            return null;
        }
        if (Platform::oc4()) {
            $route = $this->route('event', 'output');
            return null;
        }
        // Непустая строка: '' нестрого равна null, и Loader все равно выполнил бы контроллер.
        return ' ';
    }

    /**
     * view/product/{category,manufacturer_info,search}/after (только для AJAX) — JSON вместо страницы.
     * Контент страницы рендерится штатным шаблоном темы, JS забирает из него нужные блоки:
     * так фильтр работает с любой версткой без копий шаблонов.
     */
    public function fragmentAfter(&$route, &$data, &$output)
    {
        $kernel = $this->kernel();
        $runtime = $kernel->runtime();
        $front = $kernel->storefront();
        $query = $front->listingQuery();
        if (isset($this->request->get['page']) && (int)$this->request->get['page'] > 1) {
            $query['page'] = (int)$this->request->get['page'];
        }
        $seo = $this->seoDecision();
        $json = [
            'ok'    => true,
            'url'   => $front->builder()->url($runtime->context, $runtime->state, $query),
            'total' => $runtime->result ? (int)$runtime->result->total : null,
            // В JSON — текст без HTML-сущностей: скрипт присваивает его document.title как есть.
            'title' => html_entity_decode($seo && $seo['title'] ? $seo['title'] : (string)$this->document->getTitle(), ENT_QUOTES, 'UTF-8'),
            'html'  => $output,
            'panel' => $this->load->controller($this->route('base')),
        ];
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Vary: X-PSF, X-PSF-Rest');
        $output = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $output;
    }

    /**
     * OC3: controller/extension/feed/google_sitemap/after — посадочные в штатной карте сайта.
     * Контроллер фида ничего не возвращает (пишет в response), поэтому правим вывод ответа.
     */
    public function sitemapAfter(&$route, &$data, &$output)
    {
        if (!$this->enabled()) {
            return null;
        }
        $xml = $this->response->getOutput();
        if (!$xml || strpos($xml, '</urlset>') === false) {
            return null;
        }
        $urls = '';
        foreach ($this->kernel()->sitemap()->entries(0, 5000) as $e) {
            $urls .= '<url><loc>' . htmlspecialchars($e['loc'], ENT_XML1, 'UTF-8') . '</loc><lastmod>' . $e['lastmod'] . '</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>';
        }
        $this->response->setOutput(str_replace('</urlset>', $urls . '</urlset>', $xml));
        return null;
    }

    /** model/checkout/order/addOrderHistory/after (OC4: order.addHistory) — остатки товаров заказа меняются сразу. */
    public function orderHistory(&$route, &$args, &$output)
    {
        if (!$this->enabled() || empty($args[0])) {
            return null;
        }
        $kernel = $this->kernel();
        $ids = array_column($this->db->query("SELECT product_id FROM `" . DB_PREFIX . "order_product` WHERE order_id = " . (int)$args[0])->rows, 'product_id');
        if ($ids) {
            // Сбой индекса (например, файлы модуля новее схемы) не должен ломать оформление заказа: товары догонит reconcile.
            try {
                $kernel->indexer()->indexProducts($ids, $kernel->job()->currentGeneration());
                $kernel->cache()->invalidateProducts($ids);
            } catch (\Throwable $e) {
                $kernel->log('ps_filter: индексация товаров заказа ' . (int)$args[0] . ' не удалась: ' . $e->getMessage());
            }
        }
        return null;
    }

    /* ===================== Служебное ===================== */

    /**
     * SEO-решение для текущей страницы (одно на запрос).
     *
     * @return array|null
     */
    private function seoDecision()
    {
        $kernel = $this->kernel();
        $runtime = $kernel->runtime();
        if (!$runtime->context || !$runtime->state) {
            return null;
        }
        if (!property_exists($runtime, 'seo') || $runtime->seo === null) {
            $front = $kernel->storefront();
            $result = $runtime->result ?: $kernel->engine()->search($runtime->context, $runtime->state);
            $runtime->seo = $kernel->seo()->decide(
                $runtime->context,
                $result->state ?: $runtime->state,
                $result,
                $runtime->landing,
                $front->builder(),
                $front->seoRequest(),
                $front->names($runtime->context)
            ) ?: false;
        }
        return $runtime->seo ?: null;
    }

    /**
     * Адрес посадочной по id (для 301 со старых ключевых слов).
     *
     * @return string|null
     */
    private function landingHref($pageId)
    {
        $kernel = $this->kernel();
        $page = $kernel->pages()->get($pageId);
        if (!$page || !(int)$page['status']) {
            return null;
        }
        $url = $kernel->pages()->url($pageId, (int)$this->config->get('config_store_id'), (int)$this->config->get('config_language_id'));
        if (!$url) {
            return null;
        }
        $front = $kernel->storefront();
        return $front->builder()->landingUrl($front->scopeContext($page['scope_type'], $page['scope_id']), $url['keyword'], $url['url_mode']);
    }

    /**
     * Ссылки на посадочные текущего раздела.
     *
     * @param string $flag in_panel | in_category
     * @return array [name, href, count, active]
     */
    private function landingLinks($flag)
    {
        $runtime = $this->kernel()->runtime();
        return $runtime->context ? $this->kernel()->storefront()->landingLinks($runtime->context, $runtime->state, $flag) : [];
    }

    /**
     * Наш ли это вызов модели: тот же контекст, без подкатегорий и поиска.
     * Иначе, например, счетчики подкатегорий (getTotalProducts с filter_sub_category) получили бы
     * total отфильтрованной категории.
     */
    private function ours(array $data, Context $ctx)
    {
        $nameKey = Platform::oc4() ? 'filter_search' : 'filter_name';
        if ($ctx->type === Context::SEARCH) {
            // Тот же поисковый запрос и та же категория поиска, что у страницы.
            $s = $ctx->search;
            $name = isset($s['search']) ? $s['search'] : '';
            $tag = isset($s['tag']) && (Platform::oc4() ? $s['tag'] !== '' : true) ? $s['tag'] : $name;
            return (string)(isset($data[$nameKey]) ? $data[$nameKey] : '') === $name
                && (string)(isset($data['filter_tag']) ? $data['filter_tag'] : '') === $tag
                && (int)(isset($data['filter_category_id']) ? $data['filter_category_id'] : 0) === (int)(isset($s['category_id']) ? $s['category_id'] : 0)
                && empty($data['filter_manufacturer_id']);
        }
        // Штатный параметр filter= (группы фильтров OpenCart) модуль не понимает — такую выборку не трогаем.
        if (!empty($data['filter_sub_category']) || !empty($data[$nameKey]) || !empty($data['filter_tag']) || !empty($data['filter_filter'])) {
            return false;
        }
        if ($ctx->type === Context::MANUFACTURER) {
            return isset($data['filter_manufacturer_id']) && (int)$data['filter_manufacturer_id'] === $ctx->id && empty($data['filter_category_id']);
        }
        return isset($data['filter_category_id']) && (int)$data['filter_category_id'] === $ctx->id && empty($data['filter_manufacturer_id']);
    }

    /** OC3: подмена аргументов так, чтобы штатный SQL вернул пустой результат. */
    private function emptyResult(array &$args, Context $ctx)
    {
        if ($ctx->type === Context::MANUFACTURER) {
            $args[0]['filter_manufacturer_id'] = -1;
        } else {
            $args[0]['filter_category_id'] = -1;
        }
    }
}
