<?php
namespace PsFilter;

/**
 * Схема БД, события и права модуля. Единственное место, где описана «установка»:
 * ее вызывают и админка (Расширения → Модули → Установить), и CLI (cli.php install).
 *
 * Все операции идемпотентны: повторный install() досоздает недостающее и обновляет
 * события, ничего не теряя; uninstall() удаляет только свое.
 */
class Schema
{
    /** Версия схемы. Меняется вместе с миграцией в migrate(). */
    const DB_VERSION = 14;

    /** Код событий в oc_event и префикс прав. */
    const CODE = 'ps_filter';

    /** @var Kernel */
    private $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Описание таблиц: имя без префикса → тело CREATE TABLE.
     *
     * Почему так устроено, кратко:
     *  - index / index_range — плоский индекс «фасет-значение-товар»; покрывающий ключ
     *    idx_product (product_id, facet_id, value_id) обслуживает и EXISTS-фильтры, и GROUP BY счетчиков;
     *  - product / price / category_product — денормализация всего «живого», что конкуренты считают
     *    на каждом клике из oc_product/oc_product_special/oc_category_path;
     *  - generation — метка полной переиндексации: старые строки живут до cleanup, витрина не пустеет;
     *  - code у фасета UNIQUE вместо UNIQUE(source, ref_id), иначе возможен только один custom-фасет.
     *
     * @return array
     */
    public function tables()
    {
        return [
            'facet' => "
                `facet_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(64) NOT NULL,
                `source` VARCHAR(16) NOT NULL,
                `ref_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `kind` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `widget` VARCHAR(32) NOT NULL DEFAULT 'checkbox',
                `settings` TEXT NOT NULL,
                `category_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `seo_index` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`facet_id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `idx_source_ref` (`source`, `ref_id`),
                KEY `idx_status_sort` (`status`, `sort_order`)",

            'facet_description' => "
                `facet_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `hint` VARCHAR(255) NOT NULL DEFAULT '',
                `prefix` VARCHAR(32) NOT NULL DEFAULT '',
                `suffix` VARCHAR(32) NOT NULL DEFAULT '',
                PRIMARY KEY (`facet_id`, `language_id`)",

            'facet_to_category' => "
                `facet_id` INT UNSIGNED NOT NULL,
                `category_id` INT UNSIGNED NOT NULL,
                `is_auto` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `sort_order` INT NULL,
                `collapsed` TINYINT NULL,
                `widget` VARCHAR(32) NULL,
                PRIMARY KEY (`facet_id`, `category_id`),
                KEY `idx_category` (`category_id`, `status`)",

            'facet_to_store' => "
                `facet_id` INT UNSIGNED NOT NULL,
                `store_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`facet_id`, `store_id`)",

            'value' => "
                `value_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `facet_id` INT UNSIGNED NOT NULL,
                `source_key` VARCHAR(191) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `numeric_value` DECIMAL(15,4) NULL,
                `alias_of` INT UNSIGNED NOT NULL DEFAULT 0,
                `color` VARCHAR(16) NOT NULL DEFAULT '',
                `image` VARCHAR(255) NOT NULL DEFAULT '',
                `sort_order` INT NOT NULL DEFAULT 0,
                `pinned` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `hidden_manual` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `hidden` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `seo_index` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `products` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_seen` DATETIME NULL,
                PRIMARY KEY (`value_id`),
                UNIQUE KEY `uk_key` (`facet_id`, `source_key`),
                UNIQUE KEY `uk_slug` (`facet_id`, `slug`)",

            'value_description' => "
                `value_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`value_id`, `language_id`)",

            'index' => "
                `facet_id` INT UNSIGNED NOT NULL,
                `value_id` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`facet_id`, `value_id`, `product_id`),
                KEY `idx_product` (`product_id`, `facet_id`, `value_id`)",

            'index_range' => "
                `facet_id` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `value` DECIMAL(15,4) NOT NULL,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`facet_id`, `product_id`, `value`),
                KEY `idx_facet_value` (`facet_id`, `value`),
                KEY `idx_product` (`product_id`, `facet_id`, `value`)",

            'product' => "
                `product_id` INT UNSIGNED NOT NULL,
                `active` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `store_mask` INT UNSIGNED NOT NULL DEFAULT 0,
                `quantity` INT NOT NULL DEFAULT 0,
                `stock_status_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `manufacturer_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `tax_class_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `name_sort` VARCHAR(64) NOT NULL DEFAULT '',
                `grp` VARCHAR(64) NOT NULL DEFAULT '',
                `viewed` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_added` DATE NOT NULL,
                `date_available` DATE NOT NULL,
                `weight` DECIMAL(15,8) NOT NULL DEFAULT 0,
                `length` DECIMAL(15,8) NOT NULL DEFAULT 0,
                `width` DECIMAL(15,8) NOT NULL DEFAULT 0,
                `height` DECIMAL(15,8) NOT NULL DEFAULT 0,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_indexed` DATETIME NOT NULL,
                `src_modified` DATETIME NULL,
                PRIMARY KEY (`product_id`),
                KEY `idx_manufacturer` (`manufacturer_id`, `product_id`),
                KEY `idx_generation` (`generation`)",

            'category_product' => "
                `category_id` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `is_direct` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`category_id`, `product_id`),
                KEY `idx_product` (`product_id`)",

            // Продажи за период (Sales): только товары с продажами; читают ps_search и темы.
            'sold' => "
                `product_id` INT UNSIGNED NOT NULL,
                `sold` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`product_id`),
                KEY `idx_sold` (`sold`)",

            'price' => "
                `product_id` INT UNSIGNED NOT NULL,
                `customer_group_id` INT UNSIGNED NOT NULL,
                `price` DECIMAL(15,4) NOT NULL,
                `base_price` DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (`product_id`, `customer_group_id`),
                KEY `idx_group_price` (`customer_group_id`, `price`)",

            'page' => "
                `page_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `scope_type` TINYINT UNSIGNED NOT NULL,
                `scope_id` INT UNSIGNED NOT NULL,
                `state` TEXT NOT NULL,
                `state_hash` CHAR(32) NOT NULL,
                `facet_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `is_auto` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `url_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `in_panel` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `in_category` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `in_sitemap` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `layout_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `product_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`page_id`),
                UNIQUE KEY `uk_state` (`store_id`, `scope_type`, `scope_id`, `state_hash`),
                KEY `idx_scope` (`scope_type`, `scope_id`, `status`)",

            'page_description' => "
                `page_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `h1` VARCHAR(255) NOT NULL DEFAULT '',
                `meta_title` VARCHAR(255) NOT NULL DEFAULT '',
                `meta_description` VARCHAR(500) NOT NULL DEFAULT '',
                `meta_keyword` VARCHAR(255) NOT NULL DEFAULT '',
                `description` MEDIUMTEXT NOT NULL,
                `text_position` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`page_id`, `language_id`)",

            'page_url' => "
                `page_id` INT UNSIGNED NOT NULL,
                `store_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `url_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `scope_type` TINYINT UNSIGNED NOT NULL,
                `scope_id` INT UNSIGNED NOT NULL,
                `keyword` VARCHAR(191) NOT NULL,
                PRIMARY KEY (`page_id`, `store_id`, `language_id`),
                UNIQUE KEY `uk_keyword` (`store_id`, `language_id`, `url_mode`, `scope_type`, `scope_id`, `keyword`)",

            'redirect' => "
                `redirect_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `from_hash` CHAR(32) NOT NULL,
                `from_path` VARCHAR(500) NOT NULL,
                `to_page_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `to_path` VARCHAR(500) NOT NULL DEFAULT '',
                `date_added` DATETIME NOT NULL,
                PRIMARY KEY (`redirect_id`),
                UNIQUE KEY `uk_from` (`store_id`, `from_hash`)",

            'seo_template' => "
                `template_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `language_id` INT UNSIGNED NOT NULL,
                `scope_type` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `scope_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `facet_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_facets` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `max_values` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `min_products` INT UNSIGNED NOT NULL DEFAULT 1,
                `index_allowed` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `title` VARCHAR(255) NOT NULL DEFAULT '',
                `h1` VARCHAR(255) NOT NULL DEFAULT '',
                `meta_description` VARCHAR(500) NOT NULL DEFAULT '',
                `text` TEXT NOT NULL,
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`template_id`),
                KEY `idx_scope` (`store_id`, `language_id`, `scope_type`, `scope_id`)",

            'job' => "
                `job_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(16) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                `cursor_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `stage` VARCHAR(32) NOT NULL DEFAULT '',
                `total` INT UNSIGNED NOT NULL DEFAULT 0,
                `done` INT UNSIGNED NOT NULL DEFAULT 0,
                `log` TEXT NOT NULL,
                `date_started` DATETIME NOT NULL,
                `date_updated` DATETIME NOT NULL,
                PRIMARY KEY (`job_id`),
                KEY `idx_status` (`status`)",

            'queue' => "
                `product_id` INT UNSIGNED NOT NULL,
                `reason` VARCHAR(16) NOT NULL DEFAULT '',
                `date_added` DATETIME NOT NULL,
                PRIMARY KEY (`product_id`)",

            'cache' => "
                `cache_key` CHAR(32) NOT NULL,
                `scope` VARCHAR(48) NOT NULL,
                `expire` INT UNSIGNED NOT NULL,
                `data` MEDIUMBLOB NOT NULL,
                PRIMARY KEY (`cache_key`),
                KEY `idx_scope` (`scope`),
                KEY `idx_expire` (`expire`)",
        ];
    }

    /**
     * События модуля: [триггер, action, sort_order].
     * sort_order 0 — раньше штатного event/theme (500): так view-события успевают
     * подменить маршрут шаблона, а обработчик 404 — перероутить запрос до чужих модулей.
     *
     * @return array
     */
    public static function events()
    {
        return Platform::oc4() ? self::toOc4(self::eventsOc3()) : self::eventsOc3();
    }

    /**
     * События в нотации OpenCart 3 — единственный список, который правится руками.
     * Для OC4 он переводится правилами (toOc4), так что новое событие достаточно добавить сюда.
     *
     * @return array
     */
    private static function eventsOc3()
    {
        $c = 'extension/ps_filter/event/';
        $events = [
            // Витрина: маршрутизация, выборка, SEO
            ['catalog/controller/error/not_found/before',         $c . 'notFound'],
            ['catalog/controller/product/category/before',        $c . 'routeBefore'],
            ['catalog/controller/product/manufacturer/info/before', $c . 'routeBefore'],
            ['catalog/controller/product/search/before',          $c . 'routeBefore'],
            // Запасной вход (схема 9): SEO Pro в некоторых русских сборках OpenCart 3 запускает страницу в обход startup/router —
            // controller/<страница>/before не срабатывает, а язык страницы контроллер грузит первой строкой.
            ['catalog/language/product/category/before',          $c . 'languageBefore'],
            ['catalog/language/product/manufacturer/before',      $c . 'languageBefore'],
            ['catalog/language/product/search/before',            $c . 'languageBefore'],
            ['catalog/language/error/not_found/before',           $c . 'languageBefore'],
            ['catalog/view/product/category/before',              $c . 'viewBefore'],
            ['catalog/view/product/manufacturer_info/before',     $c . 'viewBefore'],
            ['catalog/view/product/search/before',                $c . 'viewBefore'],
            ['catalog/view/common/header/before',                 $c . 'header'],
            ['catalog/view/common/header/after',                  $c . 'headerAfter'],
            ['catalog/controller/extension/feed/google_sitemap/after', $c . 'sitemapAfter'],
            ['catalog/model/checkout/order/addOrderHistory/after', $c . 'orderHistory'],
            ['catalog/model/catalog/product/getTotalProducts/before', $c . 'categoryCount'],
            // Витрина: подавление обвязки страницы в AJAX-ответе fragment
            ['catalog/controller/common/header/before',           $c . 'suppress'],
            ['catalog/controller/common/footer/before',           $c . 'suppress'],
            ['catalog/controller/common/column_left/before',      $c . 'suppress'],
            ['catalog/controller/common/column_right/before',     $c . 'suppress'],
            ['catalog/controller/common/content_top/before',      $c . 'suppress'],
            ['catalog/controller/common/content_bottom/before',   $c . 'suppress'],
            // Админка: меню и вкладка товара
            ['admin/view/common/column_left/before',              $c . 'menu'],
            ['admin/view/catalog/product_form/after',             $c . 'productTab'],
            // Админка: инкрементальная индексация. Полное имя метода в триггере обязательно:
            // Event::trigger якорит регулярку только в начале строки.
            ['admin/model/catalog/product/addProduct/after',      $c . 'productAdd'],
            ['admin/model/catalog/product/editProduct/after',     $c . 'productEdit'],
            // Есть в части сборок OpenCart 3 (быстрое включение/выключение из списка); в чистом OC3 событие просто не сработает.
            ['admin/model/catalog/product/editProductStatus/after', $c . 'productEdit'],
            ['admin/model/catalog/product/deleteProduct/after',   $c . 'productDelete'],
            // Модуль «Массовая смена цен» (ps_batch, схема 11): пачка цен записана прямым SQL — переиндексировать сразу.
            ['admin/ps_batch/changed/after',                      $c . 'batchChanged'],
            // Модуль карты сайта ps_sitemap (схема 12, в OC4 — со схемы 14): посадочные — поставщиком его карты, в админке и на витрине.
            ['admin/ps_sitemap/providers',                        $c . 'sitemapProviders'],
            ['catalog/ps_sitemap/providers',                      $c . 'sitemapProviders'],
            ['admin/model/catalog/category/editCategory/after',   $c . 'categoryChanged'],
            ['admin/model/catalog/category/deleteCategory/after', $c . 'categoryChanged'],
            ['admin/model/catalog/category/repairCategories/after', $c . 'categoryChanged'],
            ['admin/model/catalog/attribute/editAttribute/after', $c . 'referenceChanged'],
            ['admin/model/catalog/attribute/deleteAttribute/after', $c . 'referenceChanged'],
            ['admin/model/catalog/option/editOption/after',       $c . 'referenceChanged'],
            ['admin/model/catalog/option/deleteOption/after',     $c . 'referenceChanged'],
            ['admin/model/catalog/manufacturer/editManufacturer/after', $c . 'referenceChanged'],
            ['admin/model/catalog/manufacturer/deleteManufacturer/after', $c . 'referenceChanged'],
            ['admin/model/catalog/filter/editFilter/after',       $c . 'referenceChanged'],
            ['admin/model/catalog/filter/deleteFilter/after',     $c . 'referenceChanged'],
            ['admin/model/customer/customer_group/addCustomerGroup/after', $c . 'customerGroupChanged'],
            ['admin/model/customer/customer_group/deleteCustomerGroup/after', $c . 'customerGroupChanged'],
            // Админка: права служебных экранов вслед за правом на модуль (см. syncPermissions).
            ['admin/model/user/user_group/addUserGroup/after',    $c . 'userGroupChanged'],
            ['admin/model/user/user_group/editUserGroup/after',   $c . 'userGroupChanged'],
        ];
        return array_map(function ($e) {
            return ['trigger' => $e[0], 'action' => $e[1], 'sort_order' => 0];
        }, $events);
    }

    /**
     * Перевод событий OC3 в нотацию OpenCart 4 (проверено на 4.1.0.4).
     *
     *  - метод модели — через «.»: model/catalog/product/addProduct → model/catalog/product.addProduct;
     *  - метод контроллера и действие события — тоже через «.»; модуль живет в extension/ps_filter/module/;
     *  - переименования ядра OC4 — RENAMES_OC4, события, которых в OC4 нет, — DROP_OC4,
     *    события только OC4 — EXTRA_OC4.
     * Ответы обработчиков OC4 игнорирует — это учтено в коде обработчиков, не здесь.
     *
     * @param array $events
     * @return array
     */
    public static function toOc4(array $events)
    {
        $renames = [
            'catalog/controller/product/manufacturer/info/before'  => ['catalog/controller/product/manufacturer.info/before'],
            'catalog/model/checkout/order.addOrderHistory/after'   => ['catalog/model/checkout/order.addHistory/after'],
            // В OC3 модель catalog/filter правит группу фильтров; в OC4 группа — filter_group, filter — одно значение.
            'admin/model/catalog/filter.editFilter/after'          => ['admin/model/catalog/filter_group.editFilterGroup/after', 'admin/model/catalog/filter.editFilter/after'],
            'admin/model/catalog/filter.deleteFilter/after'        => ['admin/model/catalog/filter_group.deleteFilterGroup/after'],
        ];
        $drop = [
            'admin/model/catalog/product.editProductStatus/after',     // только в части сборок OpenCart 3
            'catalog/controller/extension/feed/google_sitemap/after',  // в OC4 нет штатного фида
            // Запасной вход для SEO Pro русских сборок OpenCart 3: в OC4 события страницы срабатывают всегда
            'catalog/language/product/category/before',
            'catalog/language/product/manufacturer/before',
            'catalog/language/product/search/before',
            'catalog/language/error/not_found/before',
            'admin/ps_batch/changed/after',                           // ps_batch — только OC3
            // OC4 проверяет права на каждый контроллер, и форма группы их показывает
            'admin/model/user/user_group.addUserGroup/after',
            'admin/model/user/user_group.editUserGroup/after',
        ];
        $extra = [
            ['admin/model/catalog/product.copyProduct/after', 'productAdd'],
            ['admin/model/catalog/product.editVariants/after', 'variantsEdit'],
        ];
        $c = Platform::moduleRoute('event') . '.';
        $out = [];
        foreach ($events as $e) {
            $trigger = preg_replace('~^(admin|catalog)/model/(.+)/(\w+)/(before|after)$~', '$1/model/$2.$3/$4', $e['trigger']);
            if (in_array($trigger, $drop, true)) {
                continue;
            }
            $method = substr($e['action'], strrpos($e['action'], '/') + 1);
            foreach (isset($renames[$trigger]) ? $renames[$trigger] : [$trigger] as $t) {
                $out[] = ['trigger' => $t, 'action' => $c . $method, 'sort_order' => $e['sort_order']];
            }
        }
        foreach ($extra as $e) {
            $out[] = ['trigger' => $e[0], 'action' => $c . $e[1], 'sort_order' => 0];
        }
        return $out;
    }

    /**
     * Записи прав модуля. Штатный установщик выдает права только на основной маршрут;
     * служебные экраны OC3 покрывает одна запись extension/ps_filter (Platform::permissionRoute),
     * в OC4 — по записи на контроллер.
     *
     * @return array
     */
    public static function adminRoutes()
    {
        $out = [];
        foreach (['base', 'facet', 'page', 'template', 'index'] as $name) {
            $out[] = Platform::permissionRoute($name);
        }
        return array_values(array_unique($out));
    }

    /**
     * Маршруты служебных контроллеров OC3 до схемы v6, когда они лежали в extension/module/.
     *
     * @return array
     */
    private static function legacyRoutes()
    {
        if (Platform::oc4()) {
            return [];
        }
        return array_map(function ($name) {
            return 'extension/module/ps_filter_' . $name;
        }, ['event', 'facet', 'page', 'template', 'index']);
    }

    /** @return string код модуля в oc_layout_module (OC4: расширение.модуль) */
    public static function layoutCode()
    {
        return Platform::oc4() ? self::CODE . '.' . self::CODE : self::CODE;
    }

    /**
     * Полная установка.
     *
     * @param array $userGroupIds группы пользователей админки, которым выдать права
     * @param bool  $registerExtension записать модуль в oc_extension (для CLI; админка делает это сама)
     * @return array отчет для вывода
     */
    public function install(array $userGroupIds = [], $registerExtension = false)
    {
        $report = [];
        $db = $this->k->db();
        $options = $this->tableOptions();
        $report[] = 'charset: ' . $options;

        foreach ($this->tables() as $name => $body) {
            $db->query("CREATE TABLE IF NOT EXISTS `" . $this->k->table($name) . "` (" . $body . ") ENGINE=InnoDB " . $options);
        }
        $report[] = 'таблиц: ' . count($this->existingTables()) . ' из ' . count($this->tables());
        $report = array_merge($report, $this->migrate());

        $this->registerEvents();
        $report[] = 'событий: ' . count(self::events());

        foreach ($userGroupIds as $groupId) {
            $this->grantPermissions((int)$groupId);
        }
        if ($userGroupIds) {
            $report[] = 'права выданы группам: ' . implode(', ', $userGroupIds);
        }

        if ($registerExtension) {
            $q = $db->query("SELECT extension_id FROM `" . $this->k->prefix() . "extension` WHERE `type` = 'module' AND `code` = '" . self::CODE . "'");
            if (!$q->num_rows) {
                $db->query("INSERT INTO `" . $this->k->prefix() . "extension` SET `type` = 'module', `code` = '" . self::CODE . "'" . (Platform::oc4() ? ", `extension` = '" . self::CODE . "'" : ""));
            }
        }

        $settings = $this->k->settings();
        $strategy = $this->probeSetStrategy();
        $settings->save(['set_strategy' => $strategy]);
        $report[] = 'стратегия множеств: ' . $strategy;
        if ($settings->getRaw('module_ps_filter_status') === null) {
            $settings->setStatus(1);
        }
        $settings->setRaw('module_ps_filter_db_version', (string)self::DB_VERSION);
        $settings->setRaw('ps_filter_version', Kernel::VERSION); // для вкладки «Поддержка» других модулей студии
        if ($restored = $this->restoreLayouts()) {
            $report[] = 'макеты восстановлены: ' . $restored;
        }

        $maxStore = (int)$db->query("SELECT COALESCE(MAX(store_id), 0) AS m FROM `" . $this->k->prefix() . "store`")->row['m'];
        if ($maxStore > 31) {
            // store_mask — битовая маска INT UNSIGNED; при store_id > 31 нужна другая схема.
            $report[] = 'ВНИМАНИЕ: store_id ' . $maxStore . ' > 31, фильтр по магазину будет неточным';
        }
        if ($legacy = $this->legacyFiles()) {
            $report[] = 'удалите вручную файлы прежней версии: ' . implode(', ', $legacy);
        }

        return $report;
    }

    /**
     * Миграции схемы для магазинов, где модуль уже стоит. Каждая проверяет состояние сама,
     * поэтому повторный запуск безопасен (CREATE TABLE IF NOT EXISTS новые колонки не добавляет).
     *
     * @return array отчет
     */
    public function migrate()
    {
        $report = [];
        // v2: ключ сортировки по названию — листинг без JOIN product_description.
        if ($this->addColumn('product', 'name_sort', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `sort_order`")) {
            $report[] = 'миграция v2: ps_filter_product.name_sort (нужна переиндексация)';
        }
        // v3: date_modified товара на момент индексации — reconcile сравнивает на равенство,
        // поэтому не зависит от часового пояса того, кто менял товар.
        if ($this->addColumn('product', 'src_modified', "DATETIME NULL AFTER `date_indexed`")) {
            // Считаем текущий индекс актуальным, иначе первый reconcile переиндексировал бы весь каталог.
            $this->k->db()->query("UPDATE `" . $this->k->table('product') . "` fp JOIN `" . $this->k->prefix() . "product` p ON p.product_id = fp.product_id
                SET fp.src_modified = " . Index\Indexer::SRC_MODIFIED);
            $report[] = 'миграция v3: ps_filter_product.src_modified';
        }
        // v6: служебные контроллеры OC3 переехали из extension/module/ps_filter_* в extension/ps_filter/*.
        // События перерегистрируют install()/upgrade(); здесь — права групп: у кого было право на старый
        // служебный экран, получает extension/ps_filter, старые записи снимаются.
        $legacy = self::legacyRoutes();
        $tools = Platform::permissionRoute('facet');
        $groups = 0;
        foreach ($legacy ? $this->k->db()->query("SELECT user_group_id FROM `" . $this->k->prefix() . "user_group`")->rows : [] as $row) {
            $changed = $this->updatePermissions((int)$row['user_group_id'], function (array $perm) use ($legacy, $tools) {
                foreach (['access', 'modify'] as $type) {
                    if (!isset($perm[$type]) || !is_array($perm[$type]) || !array_intersect($perm[$type], $legacy)) {
                        continue;
                    }
                    $list = array_values(array_diff($perm[$type], $legacy));
                    if (!in_array($tools, $list, true)) {
                        $list[] = $tools;
                    }
                    $perm[$type] = $list;
                }
                return $perm;
            });
            $groups += $changed ? 1 : 0;
        }
        if ($groups) {
            $report[] = 'миграция v6: права групп (' . $groups . ') перенесены на extension/ps_filter';
        }
        // v7: до 0.6.10 названия производителей, значений опций и фильтров OpenCart копировались в индекс
        // экранированными («Проммаш &quot;Galley&quot;»), а шаблон экранирует еще раз — покупатель видел &quot;.
        // Переиндексация имена не переписывает (значение находится по ключу), поэтому раскодируем здесь.
        $db = $this->k->db();
        $rows = $db->query("SELECT vd.value_id, vd.language_id, vd.name FROM `" . $this->k->table('value_description') . "` vd
            JOIN `" . $this->k->table('value') . "` v ON v.value_id = vd.value_id
            JOIN `" . $this->k->table('facet') . "` f ON f.facet_id = v.facet_id
            WHERE f.source IN ('manufacturer', 'option', 'oc_filter') AND vd.name LIKE '%&%;%'")->rows;
        $fixed = 0;
        foreach ($rows as $r) {
            $name = Index\Normalizer::clean($r['name']);
            if ($name !== $r['name'] && $name !== '') {
                $db->query("UPDATE `" . $this->k->table('value_description') . "` SET name = '" . $db->escape(mb_substr($name, 0, 255, 'UTF-8')) . "'
                    WHERE value_id = " . (int)$r['value_id'] . " AND language_id = " . (int)$r['language_id']);
                $fixed++;
            }
        }
        if ($fixed) {
            $this->k->facets()->invalidate();
            $report[] = 'миграция v7: названия значений без HTML-сущностей (' . $fixed . ')';
        }
        // v8: «Популярные» по продажам (popular_by = sales — значение по умолчанию). Магазин, где «Популярные»
        // уже стоят сортировкой по умолчанию, остается на просмотрах — порядок выдачи у него не меняется сам.
        $stored = json_decode((string)$this->k->settings()->getRaw(Settings::KEY, ''), true);
        if (is_array($stored) && !isset($stored['popular_by']) && isset($stored['default_sort']) && $stored['default_sort'] === 'popular') {
            $this->k->settings()->save(['popular_by' => 'views']);
            $report[] = 'миграция v8: «Популярные» остаются по просмотрам (popular_by = views)';
        }
        // v9: запасной вход по событиям языка страницы (Events::languageBefore) — таблиц не меняет, новые события
        // регистрирует upgrade() вслед за миграцией.
        // v10: ключ группы выдачи (group_by) — по нему считаются «Найдено», счетчики и страницы при группировке.
        if ($this->addColumn('product', 'grp', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `name_sort`")) {
            $this->k->syncGroups(true);
            $report[] = 'миграция v10: ps_filter_product.grp (ключ группы выдачи)';
        }
        // v11: событие ps_batch (batchChanged) — таблиц не меняет, событие регистрирует upgrade().
        return $report;
    }

    /**
     * Догоняет схему после обновления файлов модуля (без кнопки «Проверить установку»).
     * Дешево: одна настройка, миграции — только если версия отстала.
     *
     * @return array отчет (пусто — схема актуальна)
     */
    public function upgrade()
    {
        $settings = $this->k->settings();
        if ((int)$settings->getRaw('module_ps_filter_db_version', 0) >= self::DB_VERSION || !$this->existingTables()) {
            return [];
        }
        // Две одновременные первые загрузки после обновления файлов зарегистрировали бы события дважды.
        $db = $this->k->db();
        $lock = 'psf_upgrade_' . md5($db->query("SELECT DATABASE() AS d")->row['d'] . '|' . $this->k->prefix());
        if (!(int)$db->query("SELECT GET_LOCK('" . $lock . "', 10) AS l")->row['l']) {
            return [];
        }
        try {
            if ((int)$settings->getRaw('module_ps_filter_db_version', 0) >= self::DB_VERSION) {
                return []; // пока ждали блокировку, схему догнал другой процесс
            }
            $options = $this->tableOptions();
            foreach ($this->tables() as $name => $body) {
                $db->query("CREATE TABLE IF NOT EXISTS `" . $this->k->table($name) . "` (" . $body . ") ENGINE=InnoDB " . $options);
            }
            $report = $this->migrate();
            $this->registerEvents();
            $settings->setRaw('module_ps_filter_db_version', (string)self::DB_VERSION);
            $settings->setRaw('ps_filter_version', Kernel::VERSION);
            return $report;
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    /**
     * @return bool колонка была добавлена
     */
    private function addColumn($table, $column, $definition)
    {
        $db = $this->k->db();
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . $db->escape($this->k->table($table)) . "' AND COLUMN_NAME = '" . $db->escape($column) . "'")->num_rows;
        if ($exists) {
            return false;
        }
        $db->query("ALTER TABLE `" . $this->k->table($table) . "` ADD `" . $column . "` " . $definition);
        return true;
    }

    /**
     * Полное удаление: таблицы, события, права, настройки.
     *
     * @return array
     */
    public function uninstall()
    {
        $db = $this->k->db();
        // OpenCart 4: обновление пакета — только удаление и новая установка (Установщик не ставит поверх). Поэтому по
        // умолчанию (uninstall_keep_data) снимаются только события — файлы модуля Установщик удалит, — а таблицы,
        // настройки (свой код Settings::code(), ядро их не стирает) и место в макетах остаются до новой установки.
        // Модуль OC4 без события не работает, а пункт макета ядро не выводит, пока модуль не установлен.
        if (Platform::oc4() && $this->k->settings()->get('uninstall_keep_data', 1)) {
            $db->query("DELETE FROM `" . $this->k->prefix() . "event` WHERE `code` = '" . self::CODE . "'");
            return ['события ps_filter удалены; таблицы, настройки и макеты сохранены (uninstall_keep_data)'];
        }
        // До purge(): имя таблицы и адрес сервера Manticore — в настройках модуля.
        $manticore = $this->k->manticore()->drop();
        foreach (array_keys($this->tables()) as $name) {
            $db->query("DROP TABLE IF EXISTS `" . $this->k->table($name) . "`");
        }
        $db->query("DELETE FROM `" . $this->k->prefix() . "event` WHERE `code` = '" . self::CODE . "'");
        $db->query("DELETE FROM `" . $this->k->prefix() . "layout_module` WHERE `code` = '" . $db->escape(self::layoutCode()) . "'");
        $this->revokePermissions();
        $this->k->settings()->purge();

        return array_values(array_filter(['таблицы, события, права и настройки ps_filter удалены', $manticore]));
    }

    /**
     * Ставит панель фильтра в позицию макетов страниц категории и производителя.
     * Макеты ищутся по маршруту, а не по id: в разных магазинах id разные.
     *
     * @param string $position column_left | column_right | content_top
     * @return array
     */
    /**
     * OpenCart 4: запоминает, в каких макетах и позициях стоит панель (ps_filter_layouts). Ядро OC4 при удалении
     * модуля стирает его строки oc_layout_module раньше, чем вызывает uninstall() модуля, а обновить пакет можно
     * только удалением и новой установкой — по этой записи install() ставит панель на прежние места. Вызывается
     * со страниц админки (Admin\Events::menu): пишет, только если расстановка изменилась.
     */
    public function rememberLayouts()
    {
        if (!Platform::oc4()) {
            return;
        }
        $db = $this->k->db();
        $rows = $db->query("SELECT layout_id, position, sort_order FROM `" . $this->k->prefix() . "layout_module` WHERE `code` = '"
            . $db->escape(self::layoutCode()) . "' ORDER BY layout_id, position, sort_order")->rows;
        $json = json_encode(array_map(function ($r) {
            return [(int)$r['layout_id'], (string)$r['position'], (int)$r['sort_order']];
        }, $rows));
        if ($rows && $json !== $this->k->settings()->getRaw('ps_filter_layouts')) {
            $this->k->settings()->setRaw('ps_filter_layouts', $json);
        }
    }

    /**
     * Ставит панель на места из ps_filter_layouts, если сейчас ее нет ни в одном макете (новая установка после
     * удаления с сохранением данных, OC4). Макеты, которых уже нет, пропускаются.
     *
     * @return int сколько мест восстановлено
     */
    private function restoreLayouts()
    {
        if (!Platform::oc4()) {
            return 0;
        }
        $db = $this->k->db();
        $P = $this->k->prefix();
        $code = $db->escape(self::layoutCode());
        $saved = json_decode((string)$this->k->settings()->getRaw('ps_filter_layouts', ''), true);
        if (!is_array($saved) || !$saved || $db->query("SELECT 1 FROM `{$P}layout_module` WHERE `code` = '" . $code . "' LIMIT 1")->num_rows) {
            return 0;
        }
        $n = 0;
        foreach ($saved as $r) {
            if (!is_array($r) || count($r) !== 3 || !$db->query("SELECT 1 FROM `{$P}layout` WHERE layout_id = " . (int)$r[0])->num_rows) {
                continue;
            }
            $db->query("INSERT INTO `{$P}layout_module` SET layout_id = " . (int)$r[0] . ", `code` = '" . $code . "', position = '"
                . $db->escape((string)$r[1]) . "', sort_order = " . (int)$r[2]);
            $n++;
        }
        return $n;
    }

    public function addToLayouts($position = 'column_left')
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        $report = [];
        foreach (['product/category', 'product/manufacturer', 'product/search'] as $route) {
            $q = $db->query("SELECT DISTINCT layout_id FROM `{$P}layout_route` WHERE route LIKE '" . $db->escape($route) . "%'");
            foreach ($q->rows as $row) {
                $layoutId = (int)$row['layout_id'];
                $exists = $db->query("SELECT 1 FROM `{$P}layout_module` WHERE layout_id = " . $layoutId . " AND code = '" . $db->escape(self::layoutCode()) . "' AND position = '" . $db->escape($position) . "'")->num_rows;
                if (!$exists) {
                    $db->query("INSERT INTO `{$P}layout_module` SET layout_id = " . $layoutId . ", code = '" . $db->escape(self::layoutCode()) . "', position = '" . $db->escape($position) . "', sort_order = 0");
                    $report[] = "макет $layoutId ($route): добавлено в $position";
                } else {
                    $report[] = "макет $layoutId ($route): уже есть";
                }
            }
        }
        return $report ?: ['макеты категорий и производителей не найдены'];
    }

    /** Перерегистрирует события (удаляет старые записи модуля и пишет актуальный список). */
    public function registerEvents()
    {
        $db = $this->k->db();
        $table = $this->k->prefix() . 'event';
        $db->query("DELETE FROM `" . $table . "` WHERE `code` = '" . self::CODE . "'");
        foreach (self::events() as $e) {
            $db->query("INSERT INTO `" . $table . "` SET `code` = '" . self::CODE . "', `trigger` = '" . $db->escape($e['trigger'])
                . "', `action` = '" . $db->escape($e['action']) . "', `status` = 1, `sort_order` = " . (int)$e['sort_order']
                . (Platform::oc4() ? ", `description` = 'PS Filter'" : ""));
        }
    }

    /** @param int $userGroupId */
    public function grantPermissions($userGroupId)
    {
        $this->updatePermissions($userGroupId, function (array $perm) {
            foreach (['access', 'modify'] as $type) {
                $list = isset($perm[$type]) && is_array($perm[$type]) ? $perm[$type] : [];
                foreach (self::adminRoutes() as $route) {
                    if (!in_array($route, $list, true)) {
                        $list[] = $route;
                    }
                }
                $perm[$type] = $list;
            }
            return $perm;
        });
    }

    /** Снимает права модуля со всех групп. */
    public function revokePermissions()
    {
        $q = $this->k->db()->query("SELECT user_group_id FROM `" . $this->k->prefix() . "user_group`");
        foreach ($q->rows as $row) {
            $this->updatePermissions((int)$row['user_group_id'], function (array $perm) {
                foreach (['access', 'modify'] as $type) {
                    if (isset($perm[$type]) && is_array($perm[$type])) {
                        $perm[$type] = array_values(array_diff($perm[$type], self::adminRoutes(), self::legacyRoutes()));
                    }
                }
                return $perm;
            });
        }
    }

    /**
     * Права служебных экранов (OC3: одна запись extension/ps_filter) — вслед за правом на основной
     * маршрут модуля, отдельно для access и modify.
     *
     * Форма группы пользователей OC3 строит галочки по файлам контроллеров, записи extension/ps_filter
     * в ней нет: без этой синхронизации первое же сохранение группы отняло бы экраны фильтра, а снять
     * их через форму было бы нельзя. В OC4 галочка есть у каждого контроллера — ничего не делаем.
     *
     * @param int $userGroupId
     * @return bool права группы изменились
     */
    public function syncPermissions($userGroupId)
    {
        if (Platform::oc4()) {
            return false;
        }
        $base = Platform::permissionRoute('base');
        $tools = array_diff(self::adminRoutes(), [$base]);
        return $this->updatePermissions($userGroupId, function (array $perm) use ($base, $tools) {
            foreach (['access', 'modify'] as $type) {
                $list = isset($perm[$type]) && is_array($perm[$type]) ? $perm[$type] : [];
                $new = in_array($base, $list, true)
                    ? array_values(array_unique(array_merge($list, $tools)))
                    : array_values(array_diff($list, $tools));
                if ($new !== $list) {
                    $perm[$type] = $new;
                }
            }
            return $perm;
        });
    }

    /**
     * Файлы служебных контроллеров OC3 из раскладки до схемы v6, оставшиеся в магазине: установщик
     * и sync-to-store только добавляют файлы. Из-за них в Расширения → Модули строки «heading_title».
     * Удаляются вручную (docs/ps_filter.md, «Обновление»).
     *
     * @return string[] пути от корня магазина
     */
    public function legacyFiles()
    {
        if (Platform::oc4() || !defined('DIR_APPLICATION') || !defined('DIR_CATALOG')) {
            return [];
        }
        $files = [];
        foreach (['event', 'facet', 'index', 'page', 'template'] as $name) {
            $files[] = DIR_APPLICATION . 'controller/extension/module/ps_filter_' . $name . '.php';
        }
        $files[] = DIR_CATALOG . 'controller/extension/module/ps_filter_event.php';
        $root = rtrim(str_replace('\\', '/', dirname(DIR_SYSTEM)), '/') . '/';
        $found = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $file = str_replace('\\', '/', realpath($file));
                $found[] = strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
            }
        }
        return $found;
    }

    /**
     * Состояние установки для экрана настроек и CLI.
     *
     * @return array
     */
    public function status()
    {
        $db = $this->k->db();
        $events = (int)$db->query("SELECT COUNT(*) AS c FROM `" . $this->k->prefix() . "event` WHERE `code` = '" . self::CODE . "'")->row['c'];
        $rows = [];
        foreach ($this->existingTables() as $name) {
            $q = $db->query("SELECT TABLE_ROWS AS r, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 2) AS mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->escape($this->k->table($name)) . "'");
            $rows[$name] = ['rows' => (int)$q->row['r'], 'mb' => (float)$q->row['mb']];
        }
        return [
            'version'        => Kernel::VERSION,
            'db_version'     => (int)$this->k->settings()->getRaw('module_ps_filter_db_version', 0),
            'tables_total'   => count($this->tables()),
            'tables_present' => count($rows),
            'tables'         => $rows,
            'events_total'   => count(self::events()),
            'events_present' => $events,
            'set_strategy'   => $this->k->settings()->get('set_strategy'),
            'status'         => $this->k->settings()->status(),
            'legacy_files'   => $this->legacyFiles(),
        ];
    }

    /** @return string[] имена (без префикса) реально существующих таблиц модуля */
    public function existingTables()
    {
        $q = $this->k->db()->query("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . $this->k->db()->escape(str_replace('_', '\\_', $this->k->table(''))) . "%'");
        $present = [];
        $len = strlen($this->k->table(''));
        foreach ($q->rows as $row) {
            $present[] = substr($row['t'], $len);
        }
        return array_values(array_intersect(array_keys($this->tables()), $present));
    }

    /**
     * Кодировка таблиц берется у oc_product. Драйвер OpenCart открывает соединение в utf8
     * (system/library/db/mysqli.php), и таблицы в другой кодировке дали бы «Illegal mix of collations»
     * в сравнениях с таблицами ядра.
     *
     * @return string
     */
    private function tableOptions()
    {
        $db = $this->k->db();
        $q = $db->query("SELECT c.CHARACTER_SET_NAME AS cs, t.TABLE_COLLATION AS co
            FROM information_schema.TABLES t
            JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY c ON c.COLLATION_NAME = t.TABLE_COLLATION
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '" . $db->escape($this->k->prefix() . 'product') . "'");
        if ($q->num_rows && preg_match('/^\w+$/', $q->row['cs']) && preg_match('/^\w+$/', $q->row['co'])) {
            return 'DEFAULT CHARSET=' . $q->row['cs'] . ' COLLATE=' . $q->row['co'];
        }
        return 'DEFAULT CHARSET=utf8';
    }

    /**
     * Можно ли на этом хостинге создавать временные MEMORY-таблицы.
     * Если нет — движок подставляет множества товаров derived-подзапросами.
     *
     * @return string temp|derived
     */
    private function probeSetStrategy()
    {
        $db = $this->k->db();
        try {
            $db->query("CREATE TEMPORARY TABLE `psf_probe` (`product_id` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY");
            $db->query("DROP TEMPORARY TABLE IF EXISTS `psf_probe`");
            return 'temp';
        } catch (\Exception $e) {
            return 'derived';
        }
    }

    /**
     * @param int      $userGroupId
     * @param callable $change function(array $permission): array
     * @return bool права изменились и записаны
     */
    private function updatePermissions($userGroupId, callable $change)
    {
        $db = $this->k->db();
        $table = $this->k->prefix() . 'user_group';
        $q = $db->query("SELECT permission FROM `" . $table . "` WHERE user_group_id = " . (int)$userGroupId);
        if (!$q->num_rows) {
            return false;
        }
        $perm = json_decode($q->row['permission'], true);
        $perm = is_array($perm) ? $perm : [];
        $new = $change($perm);
        if ($new === $perm) {
            return false;
        }
        $db->query("UPDATE `" . $table . "` SET permission = '" . $db->escape(json_encode($new)) . "' WHERE user_group_id = " . (int)$userGroupId);
        return true;
    }
}
