<?php
namespace PsFilter;

/**
 * Ленивый контейнер сервисов модуля.
 *
 * Вся бизнес-логика живет в system/library/ps_filter и зависит только от контрактов
 * (Contract\Db и т.д.), а не от классов OpenCart. Контроллеры — тонкие адаптеры.
 * Это главное условие дешевого порта на OpenCart 4: меняются адаптеры, ядро остается.
 */
class Kernel
{
    /** Версия кода модуля. Версия схемы БД — Schema::DB_VERSION. */
    const VERSION = '1.1.2';

    /**
     * Стык с ps_search (Storefront::searchSeam): поиск отдает найденное и порядок релевантности.
     * 1 — только сохраненный набор (rank: table, set); 2 — еще и временный (rank: ids) для роботов и превысивших лимит.
     */
    const SEARCH_SEAM = 2;

    /** @var Contract\Db */
    private $db;
    /** @var string */
    private $prefix;
    /** @var Settings */
    private $settings;
    /** @var \Registry|null */
    private $registry;
    /** @var array */
    private $services = [];

    /**
     * @param Contract\Db    $db
     * @param string         $prefix     префикс таблиц OpenCart (DB_PREFIX)
     * @param array|null     $settings   уже загруженные настройки модуля (из $config), если есть
     * @param \Registry|null $registry   реестр OpenCart; в CLI отсутствует
     */
    public function __construct(Contract\Db $db, $prefix, $settings = null, $registry = null)
    {
        $this->db = $db;
        $this->prefix = (string)$prefix;
        $this->registry = $registry;
        $this->settings = new Settings($db, $this->prefix, is_array($settings) ? $settings : null);
    }

    /**
     * Ядро поверх работающего OpenCart (витрина или админка).
     *
     * @param \Registry $registry
     * @return self
     */
    public static function fromRegistry($registry)
    {
        $config = $registry->get('config');
        // OpenCart при старте уже прочитал oc_setting в $config и раскодировал JSON —
        // берем оттуда, чтобы не делать лишний запрос на каждой странице.
        $settings = $config ? $config->get('module_ps_filter_settings') : null;

        return new self(new Adapter\Oc3\Db($registry->get('db')), DB_PREFIX, $settings, $registry);
    }

    /** @return Contract\Db */
    public function db()
    {
        return $this->db;
    }

    /** @return string */
    public function prefix()
    {
        return $this->prefix;
    }

    /**
     * Полное имя таблицы модуля: table('index') → oc_ps_filter_index.
     *
     * @param string $name
     * @return string
     */
    public function table($name)
    {
        return $this->prefix . 'ps_filter_' . $name;
    }

    /** @return Settings */
    public function settings()
    {
        return $this->settings;
    }

    /** @return \Registry|null */
    public function registry()
    {
        return $this->registry;
    }

    /** @return Schema */
    public function schema()
    {
        return $this->service('schema', function () { return new Schema($this); });
    }

    /** @return Cache */
    public function cache()
    {
        return $this->service('cache', function () { return new Cache($this); });
    }

    /** @return FacetRepository */
    public function facets()
    {
        return $this->service('facets', function () { return new FacetRepository($this); });
    }

    /** @return Index\Indexer */
    public function indexer()
    {
        return $this->service('indexer', function () { return new Index\Indexer($this); });
    }

    /** @return Seo\PageRepository */
    public function pages()
    {
        return $this->service('pages', function () { return new Seo\PageRepository($this); });
    }

    /** @return Seo\Templates */
    public function templates()
    {
        return $this->service('templates', function () { return new Seo\Templates($this); });
    }

    /** @return Seo\AutoPages */
    public function autoPages()
    {
        return $this->service('autopages', function () { return new Seo\AutoPages($this); });
    }

    /** @return Seo\Sitemap */
    public function sitemap()
    {
        return $this->service('sitemap', function () { return new Seo\Sitemap($this); });
    }

    /** @return Seo\Resolver */
    public function seo()
    {
        return $this->service('seo', function () { return new Seo\Resolver($this); });
    }

    /** @return Index\FacetWizard */
    public function facetWizard()
    {
        return $this->service('wizard', function () { return new Index\FacetWizard($this); });
    }

    /** @return Index\Job */
    public function job()
    {
        return $this->service('job', function () { return new Index\Job($this); });
    }

    /**
     * Движок выборки: Manticore, если он выбран в настройках и его таблица готова,
     * иначе MySQL. MySQL-движок остается запасным и для поиска.
     *
     * @return Engine\Backend
     */
    public function engine()
    {
        return $this->service('engine', function () {
            $this->syncGroups();
            $mysql = $this->mysqlEngine();
            $m = $this->manticore();
            // Группировку выдачи пока умеет только MySQL-движок.
            return $m->usable() && $this->groupField() === '' ? new Engine\Manticore\Engine($this, $m, $mysql) : $mysql;
        });
    }

    /** Поля товара, по которым можно группировать выдачу (настройка group_by). */
    const GROUP_FIELDS = ['model', 'sku', 'mpn', 'upc', 'ean', 'jan', 'isbn'];

    /**
     * Ключи групп (ps_filter_product.grp) посчитаны по текущему полю group_by? Нет — пересчитать один раз: поле могли
     * сменить не в админке (скрипт настройки сайта, SQL, перенос настроек). Вызывается при создании движка и счетчиков
     * меню, в админке после сохранения и командой cli.php regroup.
     *
     * @param bool $force пересчитать, даже если поле не менялось
     * @return bool был пересчет
     */
    public function syncGroups($force = false)
    {
        $field = $this->groupField();
        $want = $field === '' ? 'off' : $field;
        if (!$force && (string)$this->settings()->get('group_applied', 'off') === $want) {
            return false;
        }
        $db = $this->db();
        $lock = 'psf_regroup_' . md5($db->query("SELECT DATABASE() AS d")->row['d'] . '|' . $this->prefix);
        if (!(int)$db->query("SELECT GET_LOCK('" . $lock . "', 0) AS l")->row['l']) {
            return false; // пересчитывает другой процесс
        }
        try {
            $this->indexer()->regroup();
            $this->settings()->save(['group_applied' => $want]);
            $this->cache()->flush();
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
        return true;
    }

    /**
     * Правила раздела из настроек, которые не задаются при создании контекста: у производителя — разделы, исключенные
     * из выдачи (manufacturer_exclude_categories). Повторный вызов ничего не меняет; движки вызывают его сами.
     *
     * @return Context тот же контекст
     */
    public function applyScope(Context $ctx)
    {
        if ($ctx->type === Context::MANUFACTURER) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$this->settings()->get('manufacturer_exclude_categories', [])), function ($id) {
                return $id > 0;
            })));
            sort($ids);
            $ctx->excludeCategories = $ids;
        }
        return $ctx;
    }

    /**
     * Наличие из настроек: only_instock — все только в наличии; only_instock_facets (0.7.6) — фасеты, числа и выдача
     * с фильтром по товарам в наличии, а без фильтра — все товары. Вызывается при создании
     * контекста страницы, посадочной и автостраницы.
     *
     * @return Context тот же контекст
     */
    public function applyStock(Context $ctx)
    {
        $only = (bool)$this->settings()->get('only_instock', 0);
        $facets = !$only && (bool)$this->settings()->get('only_instock_facets', 0);
        $ctx->onlyInstock = $only || $facets;
        $ctx->listAll = $facets;
        return $ctx;
    }

    /** @return string поле oc_product для группировки выдачи; '' — группировка выключена */
    public function groupField()
    {
        $f = (string)$this->settings()->get('group_by', 'off');
        return in_array($f, self::GROUP_FIELDS, true) ? $f : '';
    }

    /** @return Engine\Mysql\Engine */
    public function mysqlEngine()
    {
        return $this->service('engine_mysql', function () { return new Engine\Mysql\Engine($this); });
    }

    /** @return Sales продажи за период: сортировка «Популярные» и таблица ps_filter_sold */
    public function sales()
    {
        return $this->service('sales', function () { return new Sales($this); });
    }

    /** @return CategoryCounts */
    public function categoryCounts()
    {
        return $this->service('catcounts', function () {
            $this->syncGroups();
            return new CategoryCounts($this);
        });
    }

    /** @return Engine\Manticore\Manager */
    public function manticore()
    {
        return $this->service('manticore', function () { return new Engine\Manticore\Manager($this); });
    }

    /**
     * Изменились товары индекса (MySQL уже записан) — сообщить внешнему движку.
     *
     * @param int[]|null $productIds null — все товары
     */
    public function touched($productIds)
    {
        if ($productIds === null) {
            $this->manticore()->touchedAll();
        } else {
            $this->manticore()->touched($productIds);
        }
    }

    /** Запись в журнал OpenCart (в CLI — в stderr). */
    public function log($message)
    {
        if ($this->registry && $this->registry->has('log')) {
            $this->registry->get('log')->write($message);
        } else {
            error_log($message);
        }
    }

    /** Сбрасывает выбранный движок (после смены настроек в том же процессе: CLI, тесты). */
    public function resetEngine()
    {
        unset($this->services['engine'], $this->services['manticore']);
    }

    /**
     * Связка с витриной OpenCart (только при работе внутри OpenCart).
     * Adapter\Oc4\Storefront наследует OC3-версию и переопределяет различия.
     *
     * @return Adapter\Oc3\Storefront
     */
    public function storefront()
    {
        return $this->service('storefront', function () {
            return Platform::oc4() ? new Adapter\Oc4\Storefront($this, $this->registry) : new Adapter\Oc3\Storefront($this, $this->registry);
        });
    }

    /**
     * Данные текущего запроса витрины: контекст, состояние фильтра, результат.
     * Живут в ядре, потому что их делят обработчики событий, модуль панели и шаблоны.
     *
     * @return \stdClass
     */
    public function runtime()
    {
        return $this->service('runtime', function () {
            $r = new \stdClass();
            $r->context = null;   // Context
            $r->state = null;     // State
            $r->result = null;    // Engine\Result
            $r->dirty = false;    // адрес фильтра был не каноническим (нужен 301)
            $r->fragment = false; // запрос пришел от JS за фрагментами
            $r->landing = null;   // посадочная страница (PageRepository)
            $r->seo = null;       // SEO-решение страницы (Seo\Resolver), считается один раз
            $r->products = [];    // OC4: товары страницы для модели модуля (подмена getProducts)
            $r->total = 0;        // OC4: число товаров для модели модуля (подмена getTotalProducts)
            $r->sort = null;      // [ключ, направление] — сортировка, которой отдана выдача (psf_sort/psf_order в шаблоне)
            $r->routed = false;  // страницу уже разбирали (событие контроллера или запасное — языка, Events::languageBefore)
            return $r;
        });
    }

    /**
     * language_id языка магазина по умолчанию: по нему строятся ключи и slug значений.
     *
     * @return int
     */
    public function defaultLanguageId()
    {
        return $this->service('default_language', function () {
            $q = $this->db->query("SELECT l.language_id FROM `" . $this->prefix . "language` l
                JOIN `" . $this->prefix . "setting` s ON s.`key` = 'config_language' AND s.store_id = 0 AND s.`value` = l.code LIMIT 1");
            if ($q->num_rows) {
                return (int)$q->row['language_id'];
            }
            $q = $this->db->query("SELECT MIN(language_id) AS id FROM `" . $this->prefix . "language`");
            return max(1, (int)$q->row['id']);
        });
    }

    /**
     * @param string   $name
     * @param callable $factory
     * @return mixed
     */
    private function service($name, callable $factory)
    {
        if (!array_key_exists($name, $this->services)) {
            $this->services[$name] = $factory();
        }
        return $this->services[$name];
    }
}
