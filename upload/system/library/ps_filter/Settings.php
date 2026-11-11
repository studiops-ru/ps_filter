<?php
namespace PsFilter;

/**
 * Настройки модуля.
 *
 * Хранятся одним JSON в oc_setting (code=module_ps_filter, key=module_ps_filter_settings,
 * serialized=1): форма настроек большая, а у хостингов max_input_vars обычно 1000 —
 * поэтому админка сохраняет JSON через AJAX, а не сотни полей формы.
 * Отдельно лежит module_ps_filter_status: его штатно проверяют позиции макета (column_left и т.д.).
 */
class Settings
{
    const CODE = 'module_ps_filter';
    /** Код строк модуля в oc_setting под OpenCart 4 (см. code()). */
    const CODE_OC4 = 'ps_filter';
    const KEY = 'module_ps_filter_settings';

    /** @var Contract\Db */
    private $db;
    /** @var string */
    private $prefix;
    /** @var array|null */
    private $values;

    /**
     * @param Contract\Db $db
     * @param string      $prefix
     * @param array|null  $preloaded значения из $config, если OpenCart их уже прочитал
     */
    public function __construct(Contract\Db $db, $prefix, $preloaded = null)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->values = $preloaded;
    }

    /**
     * Код строк модуля в oc_setting. OC3 — module_ps_filter. OC4 — ps_filter: ядро OC4 при удалении модуля
     * (Расширения → Модули) стирает настройки с кодом module_<код> раньше, чем вызывает uninstall() модуля,
     * а обновить пакет в OC4 можно только удалением и новой установкой. Со своим кодом настройки и статус
     * переживают обновление (uninstall_keep_data). Ключи (module_ps_filter_status и др.) одинаковы: OpenCart
     * читает в $config все настройки магазина, код строки ему не важен.
     *
     * @return string
     */
    public static function code()
    {
        return Platform::oc4() ? self::CODE_OC4 : self::CODE;
    }

    /**
     * Значения по умолчанию. Все, что модуль читает, обязано иметь здесь дефолт:
     * так обновление модуля не ломается на старых сохраненных настройках.
     *
     * @return array
     */
    public static function defaults()
    {
        return [
            // Общие
            'mount_mode'          => 'layout',   // layout — модуль в позиции макета; template — {{ ps_filter }} в шаблоне темы
            'ajax'                => 1,
            'ajax_pagination'     => 1,
            'autosubmit'          => 1,
            'apply_popover'       => 0,          // компьютер: кнопка «Показать товары» у выбранного пункта вместо применения сразу
            'hide_empty'          => 0,
            'hide_single'         => 0,
            'hide_useless'        => 1,          // прятать фасет, ни одно значение которого не сужает выдачу раздела
            'only_instock'        => 0,
            // 0.7.6: фасеты, числа, цена и выдача с фильтром — только товары в наличии, а раздел без фильтра — все товары
            // При only_instock = 1 не действует.
            'only_instock_facets' => 0,
            // 0.7.6: первая буква названия значения — заглавная при выводе («для волос» → «Для волос»; «iPhone» — как есть)
            'value_capitalize'    => 0,
            'scope_subtree'       => 0,          // 0 — как штатный список раздела (прямые привязки), 1 — с подкатегориями
            'default_sort'        => 'default',    // что значит сортировка «По умолчанию» (p.sort_order) в разделе и у производителя: default | popular | new | cheap | name
            'popular_by'          => 'sales',      // «Популярные»: sales — проданные штуки за popular_days дней (Sales), views — просмотры
            'popular_days'        => 90,
            'popular_statuses'    => [],         // статусы заказов-продаж; пусто — config_complete_status + config_processing_status
            // Лимиты
            'values_per_facet'    => 50,
            // Страница производителя и поиска: фасеты «только в отмеченных категориях» подбираются сами
            // (0 — показать все, у которых есть значения; иначе не больше N, см. ViewPresenter::autoPick).
            'auto_facets'         => 0,
            'auto_max_values'     => 12,
            'auto_min_coverage'   => 25,
            // 0.7.5: 1 — в автоподбор у производителя и в поиске идут и характеристики, опции, поля товара «во всех
            // категориях» (без отметки разделов). Закрепленные строкой «Показывать» во всех корневых разделах — всегда.
            'auto_unbound'        => 0,
            // 0.7.5: разделы (с подразделами), товары которых не показываются на странице производителя:
            // выдача, «Найдено», фасеты и счетчики — без них. Пусто — как штатно.
            'manufacturer_exclude_categories' => [],
            // Раздел: автоподбор характеристик (0 — выключен, все фасеты с значениями). Не больше N характеристик,
            // которые есть хотя бы у M % товаров раздела; цена, бренд, наличие и отмеченные вручную — всегда.
            'category_auto_facets'       => 0,
            'category_auto_min_coverage' => 20,
            // Не попавшие в автоподбор (оба): drop — убрать; panel — под кнопку «Все фильтры», раскрываются в панели;
            // layer — под кнопку, на компьютере открываются слоем (шторкой) со всеми фильтрами; modal — окном по центру,
            // фильтры в колонки. В шторке телефона (layer и modal) — раскрываются в ней.
            'auto_rest'                  => 'drop',
            // Группировка выдачи (0.7.0): одна карточка на группу товаров с одинаковым полем — off | model | sku | mpn |
            // upc | ean | jan | isbn. «Найдено», счетчики и страницы — по группам; карточка — представитель группы.
            'group_by'                   => 'off',
            'group_applied'              => 'off',   // служебное: по какому полю сейчас посчитан ps_filter_product.grp (Kernel::syncGroups)
            'max_selected_facets' => 10,
            'big_scope_threshold' => 100000,
            'count_collapsed'     => 1,          // 0 — свернутые фасеты не пересчитываются при клике (быстрее)
            'fast_category_counts' => 1,         // число товаров в меню и подкатегориях — из индекса одним запросом
            // OpenCart 4: удаление модуля сохраняет фасеты, посадочные, индекс, настройки и место в макетах — обновление
            // в OC4 идет только удалением и новой установкой. 0 — удалить все (как в OC3). В OC3 не действует.
            'uninstall_keep_data' => 1,
            // URL / SEO
            'url_mode'            => 'path',     // path | query (query включается сам при выключенном ЧПУ)
            'url_prefix'          => 'filter',
            'canonical_target'    => 'category', // category | self
            'canonical_paginated' => 'self',     // self | first
            'seo_min_products'    => 1,
            // 0.7.7: SEO-шаблон не индексирует выбор, у которого товаров столько же, сколько в разделе без фильтра
            // (дубль раздела: все товары одного бренда). Ручные посадочные не затрагивает.
            'seo_skip_unnarrowed' => 1,
            // Движок выборки: mysql — индекс в MySQL (по умолчанию), manticore — Manticore Search
            // для больших каталогов (MySQL-индекс остается источником, Manticore — его копия).
            'backend'             => 'mysql',
            'manticore_host'      => '127.0.0.1',
            'manticore_port'      => 9306,
            'manticore_table'     => '',         // пусто — psf_<база>_<префикс>products
            // Кеш
            'cache_driver'        => 'db',       // db | none
            'cache_ttl_counters'  => 600,
            // Интеграция с темой
            // Блоки страницы, которые JS заменяет после AJAX-ответа. По умолчанию — весь #content:
            // в нем заголовок, описание, товары, сортировка и пагинация любой темы на базе default.
            'selectors'           => [
                'content'     => '#content',
                'open_button' => '',
            ],
            'mobile_breakpoint'   => 768,
            'drawer_side'         => 'left',     // left | right | full — на весь экран, снизу
            'css_vars'            => [],
            'fallback_reload'     => 1,
            // Индексация
            'batch_size'          => 500,
            'separators'          => [',', '/', ';'],
            'cron_key'            => '',
            // Служебное (заполняет install())
            'set_strategy'        => 'temp',     // temp | derived
        ];
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array сохраненные настройки поверх дефолтов */
    public function all()
    {
        if ($this->values === null) {
            $this->values = $this->load();
        }
        return array_replace(self::defaults(), $this->values);
    }

    /**
     * Подменяет настройки только в памяти (замеры, тесты), в БД ничего не пишет.
     *
     * @param array $values
     */
    public function override(array $values)
    {
        $this->values = array_replace($this->values === null ? $this->load() : $this->values, $values);
    }

    /**
     * Сохраняет настройки целиком (неизвестные ключи отбрасываются).
     *
     * @param array $values
     */
    public function save(array $values)
    {
        $clean = array_intersect_key($values, self::defaults());
        $merged = array_replace($this->values === null ? $this->load() : $this->values, $clean);
        $json = $this->db->escape(json_encode($merged, JSON_UNESCAPED_UNICODE));

        $this->db->query("DELETE FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `code` = '" . self::code() . "' AND `key` = '" . self::KEY . "'");
        $this->db->query("INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = '" . self::code() . "', `key` = '" . self::KEY . "', `value` = '" . $json . "', serialized = 1");
        $this->values = $merged;
    }

    /**
     * Флаг module_ps_filter_status (его читают позиции макета OpenCart).
     *
     * @param int $status
     */
    public function setStatus($status)
    {
        $this->setRaw('module_ps_filter_status', (string)(int)$status);
    }

    /** @return int */
    public function status()
    {
        return (int)$this->getRaw('module_ps_filter_status', 0);
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function setRaw($key, $value)
    {
        $key = $this->db->escape($key);
        $this->db->query("DELETE FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `code` = '" . self::code() . "' AND `key` = '" . $key . "'");
        $this->db->query("INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = '" . self::code() . "', `key` = '" . $key . "', `value` = '" . $this->db->escape($value) . "', serialized = 0");
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getRaw($key, $default = null)
    {
        $q = $this->db->query("SELECT `value` FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `code` = '" . self::code() . "' AND `key` = '" . $this->db->escape($key) . "'");
        return $q->num_rows ? $q->row['value'] : $default;
    }

    /** Удаляет все настройки модуля. */
    public function purge()
    {
        $this->db->query("DELETE FROM `" . $this->prefix . "setting` WHERE `code` = '" . self::code() . "'");
        $this->values = [];
    }

    /** @return array */
    private function load()
    {
        $q = $this->db->query("SELECT `value` FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `code` = '" . self::code() . "' AND `key` = '" . self::KEY . "'");
        if (!$q->num_rows) {
            return [];
        }
        $data = json_decode($q->row['value'], true);
        return is_array($data) ? $data : [];
    }
}
