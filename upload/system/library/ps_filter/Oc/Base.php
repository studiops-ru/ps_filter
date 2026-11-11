<?php
namespace PsFilter\Oc;

use PsFilter\Platform;

/**
 * Общая логика контроллеров модуля для OpenCart 3 и 4.
 *
 * Контроллеры OpenCart — тонкие оболочки: создают нужный класс отсюда, передав себя,
 * и вызывают метод с теми же аргументами. Обращения $this->db, $this->load, $this->request
 * и т.п. проксируются в контроллер (оба OpenCart отдают реестр через магический __get),
 * поэтому код читается как обычный контроллер. Различия версий — в методах ниже
 * (маршруты, пути шаблонов, пагинация, события) и в Platform.
 */
abstract class Base
{
    /** @var object контроллер OpenCart (\Controller в OC3, \Opencart\System\Engine\Controller в OC4) */
    protected $c;

    public function __construct($controller)
    {
        $this->c = $controller;
    }

    /** @return mixed объект реестра OpenCart (db, load, request, response, config, …) */
    public function __get($key)
    {
        return $this->c->$key;
    }

    /** @return \PsFilter\Kernel */
    protected function kernel()
    {
        return ps_filter_boot($this->registry());
    }

    /**
     * Реестр контроллера: свойство protected в обеих версиях, читаем замыканием с привязкой к классу.
     *
     * @return object
     */
    protected function registry()
    {
        $c = $this->c;
        return \Closure::bind(function () { return $this->registry; }, $c, get_class($c))();
    }

    /**
     * Маршрут контроллера модуля (см. Platform::moduleRoute).
     *
     * @return string
     */
    protected function route($name = 'base', $method = '')
    {
        return Platform::moduleRoute($name, $method);
    }

    /**
     * Право пользователя админки на контроллер модуля (запись прав — Platform::permissionRoute).
     *
     * @param string $type access | modify
     * @param string $name base | facet | page | template | index
     * @return bool
     */
    protected function can($type, $name = 'base')
    {
        return (bool)$this->user->hasPermission($type, Platform::permissionRoute($name));
    }

    /**
     * Ссылка на маршрут модуля в админке с токеном, без &amp;.
     *
     * @return string
     */
    protected function adminLink($name, $method = '', $args = '')
    {
        $query = 'user_token=' . $this->session->data['user_token'] . ($args !== '' ? '&' . $args : '');
        return html_entity_decode($this->url->link($this->route($name, $method), $query, true), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Ссылка на страницу ядра админки (дашборд, список модулей) — для крошек.
     *
     * @return string
     */
    protected function coreLink($route, $args = '')
    {
        return $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . ($args !== '' ? '&' . $args : ''), true);
    }

    /** Языковой файл модуля. */
    protected function lang()
    {
        $this->load->language(Platform::oc4() ? 'extension/ps_filter/module/ps_filter' : 'extension/module/ps_filter');
    }

    /**
     * Шаблон модуля: имя внутри папки модуля (facet_list, panel, widget/checkbox…).
     *
     * @return string
     */
    protected function view($name, array $data)
    {
        return $this->load->view((Platform::oc4() ? 'extension/ps_filter/module/ps_filter/' : 'extension/module/ps_filter/') . $name, $data);
    }

    /**
     * HTML пагинации штатными средствами версии.
     *
     * @param string $url адрес с плейсхолдером {page}
     * @return string
     */
    protected function pagination($total, $page, $limit, $url)
    {
        if (Platform::oc4()) {
            return (string)$this->load->controller('common/pagination', ['total' => (int)$total, 'page' => (int)$page, 'limit' => (int)$limit, 'url' => $url]);
        }
        $pagination = new \Pagination();
        $pagination->total = (int)$total;
        $pagination->page = (int)$page;
        $pagination->limit = (int)$limit;
        $pagination->url = $url;
        return $pagination->render();
    }

    /**
     * Регистрирует обработчик события на время запроса (метод контроллера событий модуля).
     *
     * @param string $trigger в нотации текущей версии
     * @param string $method
     */
    protected function listen($trigger, $method)
    {
        $route = $this->route('event', $method);
        $action = Platform::oc4() ? new \Opencart\System\Engine\Action($route) : new \Action($route);
        $this->event->register($trigger, $action, 0);
    }

    /** Тело запроса JSON. */
    protected function input()
    {
        $in = json_decode(file_get_contents('php://input'), true);
        return is_array($in) ? $in : [];
    }

    /** Ответ JSON. */
    protected function json(array $data)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** Шапка, меню и подвал админки для полной страницы. */
    protected function adminLayout(array $data)
    {
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        // Строка «PS Filter версия · Поддержка» внизу всех страниц модуля (стандарт модулей студии, набор ps_sites/standard/kit).
        // Язык набора — до load->view(): строки support_* попадают в шаблоны через событие event/language.
        // Маршрут extension/ps_filter/support одинаков в OC3 и OC4 (раскладка tools/assemble-oc4.sh).
        $this->load->language('extension/ps_filter/support');
        $data['support_footer'] = $this->load->view('extension/ps_filter/support_footer', ['support' => $this->support()->brief()]);
        return $data;
    }

    /**
     * Вкладка «Поддержка» и сведения для поддержки: версия, «Что нового» из CHANGELOG.md, другие модули студии.
     *
     * @return \PsFilter\Support
     */
    protected function support()
    {
        return new \PsFilter\Support($this->db, DB_PREFIX, ['code' => 'ps_filter', 'name' => 'PS Filter',
            'version' => \PsFilter\Kernel::VERSION, 'dir' => Platform::libraryDir()]);
    }

    /** @return string код языка админки (OC3 — config_admin_language, OC4 — config_language_admin) */
    protected function adminLanguage()
    {
        return (string)$this->config->get(Platform::oc4() ? 'config_language_admin' : 'config_admin_language');
    }

    /** @return string адрес витрины (со слешем в конце) */
    protected function catalogUrl()
    {
        if (Platform::oc4()) {
            return HTTP_CATALOG;
        }
        return defined('HTTPS_CATALOG') && HTTPS_CATALOG ? HTTPS_CATALOG : HTTP_CATALOG;
    }

    /**
     * Событие ps_sitemap/providers (модуль карты сайта ps_sitemap): посадочные с index — поставщиком его карты.
     * ps_sitemap сам забирает адреса из карты модуля (и все ее части, если это sitemapindex) в свои файлы
     * sitemap-ps_filter-N.xml с нашими lastmod; поставщик с кодом ps_filter заменяет его встроенную ссылку.
     * Модуль выключен — поставщика нет.
     */
    public function sitemapProviders(&$list)
    {
        if ($this->enabled() && is_array($list)) {
            $list[] = ['code' => 'ps_filter', 'title' => 'Посадочные ps_filter', 'url' => 'index.php?route=' . $this->route('base', 'sitemap'), 'big' => true];
        }
        return null;
    }

    /** @return bool */
    protected function enabled()
    {
        return (bool)$this->config->get('module_ps_filter_status');
    }
}
