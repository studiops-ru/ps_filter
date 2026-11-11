<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * Настройки модуля: форма по вкладкам + JSON всех настроек, состояние установки,
 * установка/удаление/восстановление.
 *
 * Сохранение — AJAX одним JSON: у хостингов max_input_vars обычно 1000.
 */
class Settings extends Base
{
    /**
     * Поля формы: вкладка → [ключ => тип]. Тип: bool, int, text, lines (ключ: значение по строкам),
     * chars (через пробел), select:a|b|c. Ключ с точкой — вложенный (selectors.content).
     *
     * @return array
     */
    private function fields()
    {
        return [
            'tab_general' => [
                'ajax' => 'bool', 'ajax_pagination' => 'bool', 'autosubmit' => 'bool', 'apply_popover' => 'bool', 'hide_empty' => 'bool', 'hide_single' => 'bool', 'hide_useless' => 'bool',
                'only_instock' => 'bool', 'only_instock_facets' => 'bool', 'value_capitalize' => 'bool', 'scope_subtree' => 'bool', 'default_sort' => 'select:default|popular|new|cheap|name',
                'popular_by' => 'select:sales|views', 'popular_days' => 'int', 'popular_statuses' => 'statuses', 'values_per_facet' => 'int',
                'auto_facets' => 'int', 'auto_max_values' => 'int', 'auto_min_coverage' => 'int', 'auto_unbound' => 'bool',
                'manufacturer_exclude_categories' => 'categories',
                'category_auto_facets' => 'int', 'category_auto_min_coverage' => 'int', 'auto_rest' => 'select:drop|panel|layer|modal',
                'group_by' => 'select:off|model|sku|mpn|upc|ean|jan|isbn',
            ],
            'tab_url' => [
                'url_mode' => 'select:path|query', 'url_prefix' => 'text', 'seo_min_products' => 'int', 'seo_skip_unnarrowed' => 'bool',
                'canonical_target' => 'select:category|self', 'canonical_paginated' => 'select:self|first',
            ],
            'tab_speed' => [
                'cache_driver' => 'select:db|none', 'cache_ttl_counters' => 'int',
                'max_selected_facets' => 'int', 'big_scope_threshold' => 'int', 'count_collapsed' => 'bool', 'fast_category_counts' => 'bool', 'batch_size' => 'int',
                'uninstall_keep_data' => 'bool',
                'backend' => 'select:mysql|manticore', 'manticore_host' => 'text', 'manticore_port' => 'int', 'manticore_table' => 'text',
            ],
            'tab_theme' => [
                'mount_mode' => 'select:layout|template', 'selectors.content' => 'text', 'fallback_reload' => 'bool',
                'mobile_breakpoint' => 'int', 'drawer_side' => 'select:left|right|full', 'selectors.open_button' => 'text', 'css_vars' => 'lines',
            ],
            'tab_index' => [
                'separators' => 'chars',
            ],
        ];
    }

    public function index()
    {
        $kernel = $this->kernel();
        // Обычно схему догоняет событие меню; если старые файлы событий удалили раньше,
        // чем зашли в админку, события указывают в пустоту — догоняем здесь.
        $kernel->schema()->upgrade();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->coreLink('common/dashboard')],
            ['text' => $this->language->get('text_extension'), 'href' => $this->coreLink('marketplace/extension', 'type=module')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->adminLink('base')],
        ];

        $data['save'] = $this->adminLink('base', 'save');
        $data['repair'] = $this->adminLink('base', 'repair');
        $data['back'] = $this->coreLink('marketplace/extension', 'type=module');
        $data['url_facets'] = $this->adminLink('facet');
        $data['url_index'] = $this->adminLink('index');

        $settings = $kernel->settings()->all();
        $data['status'] = $kernel->settings()->status();
        $data['settings_json'] = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $data['schema'] = $kernel->schema()->status();
        $data['can_modify'] = $this->can('modify', 'base');

        // Готовые к выводу группы полей.
        $data['groups'] = [];
        foreach ($this->fields() as $tab => $fields) {
            $items = [];
            foreach ($fields as $key => $type) {
                if ($key === 'uninstall_keep_data' && !Platform::oc4()) {
                    continue; // в OC3 обновление идет поверх файлов, удаление модуля стирает все
                }
                $value = $this->read($settings, $key);
                $item = [
                    'key'     => $key,
                    'id'      => 'psf-' . str_replace(['.', '_'], '-', $key),
                    'type'    => strpos($type, 'select:') === 0 ? 'select' : $type,
                    'label'   => $this->language->get('entry_' . str_replace('.', '_', $key)),
                    'help'    => $this->language->get('help_' . str_replace('.', '_', $key)),
                    'value'   => $value,
                    'options' => [],
                ];
                if ($item['help'] === 'help_' . str_replace('.', '_', $key)) {
                    $item['help'] = '';
                }
                if ($item['type'] === 'select') {
                    foreach (explode('|', substr($type, 7)) as $opt) {
                        $item['options'][] = ['value' => $opt, 'text' => $this->language->get('option_' . str_replace('.', '_', $key) . '_' . $opt)];
                    }
                } elseif ($type === 'lines') {
                    $lines = [];
                    foreach ((array)$value as $k => $v) {
                        $lines[] = $k . ': ' . $v;
                    }
                    $item['value'] = implode("\n", $lines);
                } elseif ($type === 'chars') {
                    $item['value'] = implode(' ', (array)$value);
                } elseif ($type === 'statuses') {
                    // Галочки статусов заказов магазина (язык админки).
                    $checked = array_map('intval', (array)$value);
                    foreach ($this->db->query("SELECT order_status_id, name FROM `" . DB_PREFIX . "order_status`
                            WHERE language_id = " . (int)$this->config->get('config_language_id') . " ORDER BY name")->rows as $row) {
                        $item['options'][] = ['value' => (int)$row['order_status_id'], 'text' => $row['name'],
                            'checked' => in_array((int)$row['order_status_id'], $checked, true)];
                    }
                } elseif ($type === 'categories') {
                    // Галочки корневых разделов; отмеченные раньше вложенные (настройка из скрипта) — тоже, в конце.
                    $checked = array_map('intval', (array)$value);
                    $rows = $this->db->query("SELECT c.category_id, c.parent_id, cd.name FROM `" . DB_PREFIX . "category` c
                        LEFT JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = c.category_id AND cd.language_id = " . (int)$this->config->get('config_language_id') . "
                        WHERE c.parent_id = 0" . ($checked ? " OR c.category_id IN (" . implode(',', $checked) . ")" : "") . "
                        ORDER BY c.parent_id > 0, c.sort_order, cd.name")->rows;
                    foreach ($rows as $row) {
                        $item['options'][] = ['value' => (int)$row['category_id'],
                            'text' => ($row['name'] !== null && $row['name'] !== '' ? $row['name'] : '#' . $row['category_id']) . ' (id ' . (int)$row['category_id'] . ')',
                            'checked' => in_array((int)$row['category_id'], $checked, true)];
                    }
                }
                $items[] = $item;
            }
            $data['groups'][] = ['id' => $tab, 'title' => $this->language->get($tab), 'fields' => $items];
        }

        $data = $this->adminLayout($data);
        // Вкладка «Поддержка» — последней (стандарт модулей студии). Язык набора загружен в adminLayout().
        $s = $data['schema'];
        $full = $this->support()->data($this->adminLanguage(), [
            'state' => sprintf('таблиц %d из %d, событий %d из %d, схема %d', $s['tables_present'], $s['tables_total'], $s['events_present'], $s['events_total'], $s['db_version']),
        ]);
        $data['support_pane'] = $this->load->view('extension/ps_filter/support', ['support' => $full]);
        $this->response->setOutput($this->view('setting', $data));
    }

    /** AJAX: сохранение настроек. Тело запроса — JSON {status, settings}. */
    public function save()
    {
        $this->lang();
        $json = [];

        if (!$this->can('modify', 'base')) {
            $json['error'] = $this->language->get('error_permission');
        } elseif ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            $json['error'] = 'POST only';
        } else {
            $input = $this->input();
            if (!$input) {
                $json['error'] = $this->language->get('error_payload');
            } else {
                $kernel = $this->kernel();
                $settings = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];
                $error = isset($input['settings']) && !is_array($input['settings']) ? $this->language->get('error_payload') : $this->validate($settings);
                if ($error) {
                    $json['error'] = $error;
                } else {
                    $popular = function () use ($kernel) {
                        return [$kernel->settings()->get('popular_by'), (int)$kernel->settings()->get('popular_days'), $kernel->sales()->statuses()];
                    };
                    $popularBefore = $popular();
                    @set_time_limit(300); // пересчет продаж и групп на большом каталоге
                    if ($settings) {
                        $kernel->settings()->save($settings);
                    }
                    // Период, статусы или источник «Популярных» сменили — продажи пересчитать сейчас, а не через сутки.
                    if ($popularBefore !== $popular()) {
                        $kernel->sales()->refresh(true);
                    }
                    // Поле группировки выдачи сменили — ключи групп пересчитать сразу (без полной переиндексации).
                    $kernel->syncGroups();
                    if (isset($input['status'])) {
                        $kernel->settings()->setStatus((int)filter_var($input['status'], FILTER_VALIDATE_BOOLEAN));
                    }
                    // Настройки влияют на ссылки и выборку — старые счетчики и панели больше не верны.
                    $kernel->cache()->flush();
                    $json['success'] = $this->language->get('text_success');
                    // Manticore выбран: проверить сервер и готовность таблицы — витрина до тех пор на MySQL.
                    $kernel->resetEngine();
                    $m = $kernel->manticore();
                    if ($m->enabled()) {
                        $status = $m->status();
                        if ($status['error'] !== '') {
                            $json['success'] .= ' ' . sprintf($this->language->get('text_mt_unreachable'), $status['error']);
                        } elseif ($status['state'] !== 'ready') {
                            $json['success'] .= ' ' . $this->language->get('text_mt_need_rebuild');
                        }
                    }
                }
            }
        }
        $this->json($json);
    }

    /**
     * Повторная установка без потери данных — досоздает таблицы, перерегистрирует события и права.
     * Нужна после обновления файлов модуля.
     */
    public function repair()
    {
        $this->lang();
        $json = [];
        if (!$this->can('modify', 'base')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $json['report'] = $this->kernel()->schema()->install([(int)$this->user->getGroupId()]);
            $json['success'] = $this->language->get('text_repaired');
        }
        $this->json($json);
    }

    /**
     * Вызывается штатным установщиком (Расширения → Модули → Установить) после того,
     * как он записал модуль в oc_extension и выдал права на основной маршрут.
     */
    public function install()
    {
        if ($this->canInstall()) {
            $this->kernel()->schema()->install([(int)$this->user->getGroupId()]);
        }
    }

    public function uninstall()
    {
        if ($this->canInstall()) {
            $this->kernel()->schema()->uninstall();
        }
    }

    /**
     * Ядро проверяет у extension/module/<модуль>/* только «доступ», а uninstall сносит все таблицы модуля:
     * требуем право на изменение — установщика расширений (как штатный путь) или самого модуля.
     */
    private function canInstall()
    {
        $installer = Platform::oc4() ? 'marketplace/extension' : 'extension/extension/module';
        return $this->user->hasPermission('modify', $installer) || $this->can('modify', 'base');
    }

    /**
     * Проверка и приведение присланных настроек по описанию полей (fields()): флаги → 0/1, числа → int
     * в допустимых границах, списки — только из вариантов, тексты — строки до 255 символов.
     * Неизвестные ключи отбрасывает Settings::save().
     *
     * @return string|null текст ошибки
     */
    private function validate(array &$settings)
    {
        $spec = [];
        foreach ($this->fields() as $group) {
            $spec += $group;
        }
        $limits = [
            'values_per_facet' => [1, 1000], 'popular_days' => [1, 3650], 'auto_facets' => [0, 50], 'auto_max_values' => [2, 500], 'auto_min_coverage' => [0, 100], 'category_auto_facets' => [0, 100], 'category_auto_min_coverage' => [0, 100],
            'seo_min_products' => [1, 100000], 'cache_ttl_counters' => [0, 86400 * 30], 'max_selected_facets' => [1, 100],
            'big_scope_threshold' => [0, 100000000], 'batch_size' => [50, 10000], 'manticore_port' => [1, 65535], 'mobile_breakpoint' => [0, 4000],
        ];
        foreach ($settings as $key => $value) {
            if ($key === 'selectors') {
                if (!is_array($value)) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                $content = trim((string)$this->read($value, 'content'));
                $settings[$key] = ['content' => $content !== '' ? $content : '#content', 'open_button' => trim((string)$this->read($value, 'open_button'))];
                continue;
            }
            if ($key === 'popular_statuses' || $key === 'manufacturer_exclude_categories') {
                if (!is_array($value)) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', $value), function ($id) { return $id > 0; })));
                sort($ids);
                $settings[$key] = $ids;
                continue;
            }
            if ($key === 'css_vars' || $key === 'separators') {
                // css_vars фильтрует Panel при выводе; separators — по одному-два символа.
                if (!is_array($value)) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                if ($key === 'separators') {
                    $settings[$key] = array_values(array_unique(array_filter(array_map(function ($s) {
                        return mb_substr(trim((string)$s), 0, 2, 'UTF-8');
                    }, $value), 'strlen')));
                }
                continue;
            }
            if (!isset($spec[$key]) || is_array($value)) {
                if (isset($spec[$key])) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                continue;
            }
            $type = $spec[$key];
            if ($type === 'bool') {
                $settings[$key] = (int)filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'int') {
                if (!is_numeric($value)) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                $v = (int)$value;
                $settings[$key] = isset($limits[$key]) ? max($limits[$key][0], min($limits[$key][1], $v)) : max(0, $v);
            } elseif (strpos($type, 'select:') === 0) {
                if (!in_array((string)$value, explode('|', substr($type, 7)), true)) {
                    return sprintf($this->language->get('error_setting'), $key);
                }
                $settings[$key] = (string)$value;
            } else {
                $settings[$key] = mb_substr(trim((string)$value), 0, 255, 'UTF-8');
            }
        }
        if (isset($settings['url_prefix'])) {
            $prefix = (string)$settings['url_prefix'];
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $prefix)) {
                return $this->language->get('error_prefix');
            }
            // Префикс не должен совпадать с ЧПУ-ключом: иначе разбор ЧПУ примет его за категорию или товар.
            // OC4 ловит сегмент еще и как окончание составного ключа («desktops/mac»).
            $suffix = Platform::oc4() ? " OR keyword LIKE '" . $this->db->escape('%/' . $prefix) . "'" : '';
            if ($this->db->query("SELECT 1 FROM `" . DB_PREFIX . "seo_url` WHERE keyword = '" . $this->db->escape($prefix) . "'" . $suffix . " LIMIT 1")->num_rows) {
                return sprintf($this->language->get('error_prefix_taken'), $prefix);
            }
            // И с ключом посадочной под разделом (/раздел/ключ против /раздел/префикс/…).
            if ($this->db->query("SELECT 1 FROM `" . $this->kernel()->table('page_url') . "` WHERE url_mode = 0 AND keyword = '" . $this->db->escape($prefix) . "' LIMIT 1")->num_rows) {
                return sprintf($this->language->get('error_prefix_landing'), $prefix);
            }
        }
        return null;
    }

    /** Значение по ключу с точкой (selectors.content). */
    private function read(array $settings, $key)
    {
        $value = $settings;
        foreach (explode('.', $key) as $part) {
            $value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : '';
        }
        return $value;
    }
}
