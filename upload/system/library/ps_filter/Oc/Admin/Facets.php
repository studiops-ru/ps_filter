<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * PS Filter — фасеты.
 *
 * Список: правка прямо в таблице (название, код, виджет, порядок, свернутость, статус).
 * Форма фасета: общие настройки и названия по языкам, значения (цвет, картинка, порядок,
 * закрепление, скрытие, объединение синонимов, адрес) и переопределения по категориям.
 * Свои фасеты (source = custom): значения заводятся вручную и отмечаются в карточке товара.
 *
 * Код фасета и адрес значения — сегменты URL (brand=titan): после смены старые ссылки
 * перестанут работать, поэтому интерфейс об этом предупреждает.
 */
class Facets extends Base
{

    /** Виджеты, которые есть в шаблонах по умолчанию. Тема может добавить свои (widget/<имя>.twig). */
    const WIDGETS_LIST = ['checkbox', 'radio', 'select', 'color', 'image', 'toggle'];
    const WIDGETS_RANGE = ['range'];
    const VALUES_PER_PAGE = 50;

    public function index() {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_facets'));

        $data['breadcrumbs'] = $this->breadcrumbs();
        $data['url_save'] = $this->link('save');
        $data['url_bulk'] = $this->link('bulk');
        $data['url_delete'] = $this->link('delete');
        $data['url_add'] = $this->link('add');
        $data['url_index'] = $this->adminLink('index');
        $data['can_modify'] = $this->can('modify', 'facet');

        $lang = (int)$this->config->get('config_language_id');
        $q = $this->db->query("SELECT f.*, COALESCE(fd.name, '') AS name,
                (SELECT COUNT(*) FROM `" . $kernel->table('value') . "` v WHERE v.facet_id = f.facet_id AND v.products > 0 AND v.alias_of = 0) AS `values`
            FROM `" . $kernel->table('facet') . "` f
            LEFT JOIN `" . $kernel->table('facet_description') . "` fd ON fd.facet_id = f.facet_id AND fd.language_id = " . $lang . "
            ORDER BY f.sort_order, f.facet_id");
        // Три блока для удобства: основные (цена, бренд, наличие, свои, группы фильтров), характеристики, опции.
        // Порядок на витрине по-прежнему общий — по sort_order, блоки только в списке админки.
        $blocks = ['main' => [], 'attribute' => [], 'option' => []];
        $data['facets'] = [];
        foreach ($q->rows as $row) {
            $settings = json_decode($row['settings'], true);
            $range = (int)$row['kind'] === 1;
            $block = isset($blocks[$row['source']]) ? $row['source'] : 'main';
            $blocks[$block][] = [
                'facet_id'   => (int)$row['facet_id'],
                'code'       => $row['code'],
                'name'       => $row['name'],
                'source'     => $this->language->get('source_' . $row['source']),
                'kind'       => (int)$row['kind'],
                'widget'     => $row['widget'],
                'widgets'    => $range ? self::WIDGETS_RANGE : self::WIDGETS_LIST,
                'collapsed'  => !empty($settings['collapsed']),
                'seo_index'  => (int)$row['seo_index'],
                'status'     => (int)$row['status'],
                'sort_order' => (int)$row['sort_order'],
                'values'     => $range ? '—' : (int)$row['values'],
                'edit'       => $this->adminLink('facet', 'form', 'facet_id=' . (int)$row['facet_id']),
            ];
        }
        $data['blocks'] = [];
        foreach ($blocks as $key => $facets) {
            if ($facets) {
                $on = count(array_filter($facets, function ($f) { return $f['status']; }));
                $data['blocks'][] = ['key' => $key, 'title' => $this->language->get('text_block_' . $key), 'facets' => $facets,
                    'count' => count($facets), 'on' => $on];
                $data['facets'] = array_merge($data['facets'], $facets);
            }
        }

        $this->response->setOutput($this->view('facet_list', $this->adminLayout($data)));
    }

    /** Форма фасета: вкладки «Общие», «Значения», «Категории». */
    public function form() {
        $kernel = $this->kernel();
        $this->lang();
        $id = isset($this->request->get['facet_id']) ? (int)$this->request->get['facet_id'] : 0;
        $row = $this->db->query("SELECT * FROM `" . $kernel->table('facet') . "` WHERE facet_id = " . $id)->row;
        if (!$row) {
            $this->response->redirect($this->adminLink('facet'));
            return;
        }
        $this->document->setTitle($this->language->get('heading_facet'));

        $descriptions = [];
        foreach ($this->db->query("SELECT * FROM `" . $kernel->table('facet_description') . "` WHERE facet_id = " . $id)->rows as $d) {
            $descriptions[(int)$d['language_id']] = ['name' => $d['name'], 'hint' => $d['hint'], 'prefix' => $d['prefix'], 'suffix' => $d['suffix']];
        }
        $settings = json_decode($row['settings'], true);
        $kind = (int)$row['kind'];

        $lang = (int)$this->config->get('config_language_id');
        $categories = [];
        foreach ($this->db->query("SELECT fc.*, (SELECT GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ')
                    FROM `" . DB_PREFIX . "category_path` cp JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = cp.path_id AND cd.language_id = " . $lang . "
                    WHERE cp.category_id = fc.category_id) AS name
                FROM `" . $kernel->table('facet_to_category') . "` fc WHERE fc.facet_id = " . $id . " AND fc.is_auto = 0 ORDER BY name")->rows as $c) {
            $categories[] = [
                'category_id' => (int)$c['category_id'],
                'name'        => (string)$c['name'],
                'status'      => (int)$c['status'],
                'sort_order'  => $c['sort_order'] === null ? '' : (int)$c['sort_order'],
                'collapsed'   => $c['collapsed'] === null ? '' : (int)$c['collapsed'],
                'widget'      => (string)$c['widget'],
            ];
        }

        $facet = [
            'facet_id'      => $id,
            'code'          => $row['code'],
            'source'        => $row['source'],
            'kind'          => $kind,
            'widget'        => $row['widget'],
            'status'        => (int)$row['status'],
            'seo_index'     => (int)$row['seo_index'],
            'sort_order'    => (int)$row['sort_order'],
            'category_mode' => (int)$row['category_mode'],
            'settings'      => is_array($settings) ? (object)$settings : new \stdClass(),
            'description'   => (object)$descriptions,
            'categories'    => $categories,
        ];

        $this->load->model('localisation/language');
        $languages = [];
        foreach ($this->model_localisation_language->getLanguages() as $l) {
            $languages[] = ['language_id' => (int)$l['language_id'], 'name' => $l['name'], 'code' => $l['code']];
        }
        $this->load->model('tool/image');

        $data['breadcrumbs'] = $this->breadcrumbs();
        $data['breadcrumbs'][] = ['text' => $row['code'], 'href' => $this->adminLink('facet', 'form', 'facet_id=' . $id)];
        $data['facet_json'] = json_encode($facet, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $data['languages_json'] = json_encode($languages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $data['widgets_json'] = json_encode($kind === 1 ? self::WIDGETS_RANGE : self::WIDGETS_LIST);
        $data['placeholder'] = $this->model_tool_image->resize('no_image.png', 40, 40);
        $data['config_language_id'] = $lang;
        $data['source_name'] = $this->language->get('source_' . $row['source']);
        $data['is_custom'] = $row['source'] === 'custom';
        $data['has_values'] = $kind !== 1 && !in_array($row['source'], ['stock', 'price'], true);
        $data['stock_statuses'] = [];
        if ($row['source'] === 'stock') {
            foreach ($this->kernel()->facets()->stockStatuses($lang) as $sid => $name) {
                $data['stock_statuses'][] = ['id' => $sid, 'name' => $name];
            }
        }
        $data['can_modify'] = $this->can('modify', 'facet');
        $data['url_list'] = $this->adminLink('facet');
        $data['url_index'] = $this->adminLink('index');
        foreach (['save_facet', 'values', 'save_values', 'merge', 'unmerge', 'add_value', 'delete_value', 'save_categories'] as $action) {
            $data['url_' . $action] = $this->link(lcfirst(str_replace('_', '', ucwords($action, '_'))));
        }
        $data['url_category_ac'] = html_entity_decode($this->coreLink(Platform::oc4() ? 'catalog/category.autocomplete' : 'catalog/category/autocomplete'), ENT_QUOTES, 'UTF-8');

        $this->response->setOutput($this->view('facet_form', $this->adminLayout($data)));
    }

    /** AJAX: сохранение строки списка. Тело — JSON {facet_id, name, code, widget, sort_order, collapsed, seo_index, status}. */
    public function save() {
        $this->guard(function ($kernel, $in, $row) {
            $code = $this->checkCode($kernel, $in, $row);
            $settings = $this->settingsOf($row);
            $settings['collapsed'] = !empty($in['collapsed']) ? 1 : 0;
            $this->db->query("UPDATE `" . $kernel->table('facet') . "` SET code = '" . $this->db->escape($code) . "', widget = '" . $this->db->escape($this->widget($in, $row))
                . "', sort_order = " . (int)$in['sort_order'] . ", status = " . (!empty($in['status']) ? 1 : 0) . ", seo_index = " . (!empty($in['seo_index']) ? 1 : 0)
                . ", settings = '" . $this->db->escape(json_encode((object)$settings, JSON_UNESCAPED_UNICODE)) . "' WHERE facet_id = " . (int)$row['facet_id']);
            if (isset($in['name']) && trim($in['name']) !== '') {
                $this->db->query("INSERT INTO `" . $kernel->table('facet_description') . "` SET facet_id = " . (int)$row['facet_id'] . ", language_id = " . (int)$this->config->get('config_language_id')
                    . ", name = '" . $this->db->escape(trim($in['name'])) . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");
            }
            $this->changed($kernel);
            return ['reindex' => (int)$row['status'] === 0 && !empty($in['status']) && $row['source'] !== 'custom'];
        });
    }

    /**
     * AJAX: один флажок у многих фасетов сразу — галочка в заголовке столбца списка.
     * Тело — JSON {field: status|seo_index|collapsed, value: 0|1, ids: [facet_id…]}.
     * Индексируемость (seo_index) бывает только у дискретных фасетов — у остальных не трогаем.
     */
    public function bulk() {
        $this->lang();
        $in = $this->input();
        $field = isset($in['field']) ? (string)$in['field'] : '';
        $ids = array_values(array_filter(array_map('intval', isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [])));
        if (!$this->can('modify', 'facet')) {
            $this->json(['error' => $this->language->get('error_permission')]);
            return;
        }
        if (!in_array($field, ['status', 'seo_index', 'collapsed'], true) || !$ids) {
            $this->json(['error' => $this->language->get('error_payload')]);
            return;
        }
        $kernel = $this->kernel();
        $T = '`' . $kernel->table('facet') . '`';
        $value = !empty($in['value']) ? 1 : 0;
        $list = implode(',', $ids);
        $reindex = false;
        if ($field === 'collapsed') {
            // Свернутость лежит в JSON настроек фасета.
            foreach ($this->db->query("SELECT facet_id, settings FROM " . $T . " WHERE facet_id IN (" . $list . ")")->rows as $r) {
                $settings = $this->settingsOf($r);
                $settings['collapsed'] = $value;
                $this->db->query("UPDATE " . $T . " SET settings = '" . $this->db->escape(json_encode((object)$settings, JSON_UNESCAPED_UNICODE)) . "' WHERE facet_id = " . (int)$r['facet_id']);
            }
        } else {
            if ($field === 'status' && $value) {
                // Включенные сейчас фасеты из выключенных: их значений в индексе нет — нужна переиндексация.
                $reindex = (bool)$this->db->query("SELECT 1 FROM " . $T . " WHERE facet_id IN (" . $list . ") AND status = 0 AND source <> 'custom' LIMIT 1")->num_rows;
            }
            $this->db->query("UPDATE " . $T . " SET `" . $field . "` = " . $value . " WHERE facet_id IN (" . $list . ")"
                . ($field === 'seo_index' ? " AND kind = 0" : ""));
        }
        $this->changed($kernel);
        $this->json(['success' => $this->language->get('text_success'), 'reindex' => $reindex]);
    }

    /**
     * AJAX: сохранение формы фасета.
     * Тело — JSON {facet_id, code, widget, sort_order, status, seo_index, category_mode, settings{…}, description{lang: {name, hint, prefix, suffix}}}.
     */
    public function saveFacet() {
        $this->guard(function ($kernel, $in, $row) {
            $code = $this->checkCode($kernel, $in, $row);
            $old = $this->settingsOf($row);
            $s = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
            $settings = $old;
            $settings['collapsed'] = !empty($s['collapsed']) ? 1 : 0;
            $settings['value_sort'] = isset($s['value_sort']) && $s['value_sort'] === 'name' ? 'name' : 'auto';
            // Автоподбор: только под «Все фильтры» (View\Presenter::restOnly).
            if (!empty($s['rest_only'])) {
                $settings['rest_only'] = 1;
            } else {
                unset($settings['rest_only']);
            }
            if ($row['source'] === 'attribute') {
                $seps = isset($s['separators']) ? array_values(array_filter(preg_split('/\s+/u', trim((string)$s['separators'])), 'strlen')) : [];
                if ($seps) {
                    $settings['separators'] = $seps;
                } else {
                    unset($settings['separators']); // общие разделители из настроек модуля
                }
            }
            if ((int)$row['kind'] === 1) {
                $settings['decimals'] = max(0, min(4, (int)(isset($s['decimals']) ? $s['decimals'] : 0)));
                if ($row['source'] === 'attribute') {
                    $settings['unit_multiplier'] = isset($s['unit_multiplier']) && (float)$s['unit_multiplier'] > 0 ? (float)$s['unit_multiplier'] : 1;
                }
            }
            if ($row['source'] === 'option') {
                $settings['in_stock_only'] = !empty($s['in_stock_only']) ? 1 : 0;
            }
            if ($row['source'] === 'stock') {
                foreach (['text_instock', 'text_outofstock'] as $k) {
                    if (isset($s[$k]) && trim($s[$k]) !== '') {
                        $settings[$k] = trim($s[$k]);
                    }
                }
                // По остатку или по статусам наличия (\PsFilter\Stock).
                $settings['mode'] = isset($s['mode']) && $s['mode'] === 'status' ? 'status' : 'quantity';
                $settings['instock_status'] = isset($s['instock_status']) ? max(0, (int)$s['instock_status']) : 0;
            }
            // Значение «Наличие» Manticore хранит в документе — при смене режима переписать все товары.
            $stockChanged = $row['source'] === 'stock'
                && ((isset($old['mode']) ? $old['mode'] : 'quantity') !== $settings['mode']
                    || (isset($old['instock_status']) ? (int)$old['instock_status'] : 0) !== $settings['instock_status']);

            $id = (int)$row['facet_id'];
            $this->db->query("UPDATE `" . $kernel->table('facet') . "` SET code = '" . $this->db->escape($code) . "', widget = '" . $this->db->escape($this->widget($in, $row))
                . "', sort_order = " . (int)$in['sort_order'] . ", status = " . (!empty($in['status']) ? 1 : 0)
                . ", seo_index = " . (!empty($in['seo_index']) && (int)$row['kind'] === 0 ? 1 : 0)
                . ", category_mode = " . (!empty($in['category_mode']) ? 1 : 0)
                . ", settings = '" . $this->db->escape(json_encode((object)$settings, JSON_UNESCAPED_UNICODE)) . "' WHERE facet_id = " . $id);

            foreach (isset($in['description']) && is_array($in['description']) ? $in['description'] : [] as $lang => $d) {
                $name = trim(isset($d['name']) ? (string)$d['name'] : '');
                if ($name === '') {
                    continue;
                }
                $this->db->query("INSERT INTO `" . $kernel->table('facet_description') . "` SET facet_id = " . $id . ", language_id = " . (int)$lang
                    . ", name = '" . $this->db->escape(mb_substr($name, 0, 255, 'UTF-8')) . "'"
                    . ", hint = '" . $this->db->escape(mb_substr(trim(isset($d['hint']) ? (string)$d['hint'] : ''), 0, 255, 'UTF-8')) . "'"
                    . ", prefix = '" . $this->db->escape(mb_substr(trim(isset($d['prefix']) ? (string)$d['prefix'] : ''), 0, 32, 'UTF-8')) . "'"
                    . ", suffix = '" . $this->db->escape(mb_substr(trim(isset($d['suffix']) ? (string)$d['suffix'] : ''), 0, 32, 'UTF-8')) . "'"
                    . " ON DUPLICATE KEY UPDATE name = VALUES(name), hint = VALUES(hint), prefix = VALUES(prefix), suffix = VALUES(suffix)");
            }
            $this->changed($kernel);
            if ($stockChanged) {
                $kernel->manticore()->touchedAll();
            }

            // Разделители и множитель влияют на то, какие значения попадут в индекс.
            $indexing = ['separators', 'unit_multiplier', 'in_stock_only'];
            $reindex = array_intersect_key($old, array_flip($indexing)) != array_intersect_key($settings, array_flip($indexing))
                || ((int)$row['status'] === 0 && !empty($in['status']));
            return ['reindex' => $reindex && $row['source'] !== 'custom'];
        });
    }

    /**
     * AJAX: значения фасета страницей. GET facet_id, filter_name, show (all|hidden|alias|empty), page.
     * Синонимы (alias_of) показываются с названием основного значения.
     */
    public function values() {
        $this->lang();
        $kernel = $this->kernel();
        $id = isset($this->request->get['facet_id']) ? (int)$this->request->get['facet_id'] : 0;
        $page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
        $show = isset($this->request->get['show']) ? (string)$this->request->get['show'] : 'all';
        $lang = (int)$this->config->get('config_language_id');
        $V = '`' . $kernel->table('value') . '`';
        $VD = '`' . $kernel->table('value_description') . '`';

        $where = ["v.facet_id = " . $id];
        if (!empty($this->request->get['filter_name'])) {
            $f = $this->db->escape((string)$this->request->get['filter_name']);
            $where[] = "(vd.name LIKE '%" . $f . "%' OR v.slug LIKE '%" . $f . "%')";
        }
        if ($show === 'hidden') {
            $where[] = "v.hidden_manual = 1";
        } elseif ($show === 'alias') {
            $where[] = "v.alias_of > 0";
        } elseif ($show === 'empty') {
            $where[] = "v.products = 0 AND v.alias_of = 0";
        }
        $from = " FROM " . $V . " v LEFT JOIN " . $VD . " vd ON vd.value_id = v.value_id AND vd.language_id = " . $lang . " WHERE " . implode(' AND ', $where);
        $total = (int)$this->db->query("SELECT COUNT(*) AS c" . $from)->row['c'];
        $rows = $this->db->query("SELECT v.*, COALESCE(vd.name, v.slug) AS name,
                (SELECT COALESCE(ad.name, a.slug) FROM " . $V . " a LEFT JOIN " . $VD . " ad ON ad.value_id = a.value_id AND ad.language_id = " . $lang . " WHERE a.value_id = v.alias_of) AS alias_name
            " . $from . " ORDER BY v.alias_of > 0, v.pinned DESC, v.sort_order, name LIMIT " . (($page - 1) * self::VALUES_PER_PAGE) . ", " . self::VALUES_PER_PAGE)->rows;

        $this->load->model('tool/image');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'value_id'      => (int)$r['value_id'],
                'name'          => $r['name'],
                'slug'          => $r['slug'],
                'color'         => $r['color'],
                'image'         => $r['image'],
                'thumb'         => $r['image'] && is_file(DIR_IMAGE . $r['image']) ? $this->model_tool_image->resize($r['image'], 40, 40) : '',
                'sort_order'    => (int)$r['sort_order'],
                'pinned'        => (int)$r['pinned'],
                'hidden_manual' => (int)$r['hidden_manual'],
                'alias_of'      => (int)$r['alias_of'],
                'alias_name'    => (string)$r['alias_name'],
                'products'      => (int)$r['products'],
            ];
        }
        $this->json(['values' => $out, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / self::VALUES_PER_PAGE))]);
    }

    /** AJAX: правка значений. Тело — JSON {facet_id, values: [{value_id, name, slug, color, image, sort_order, pinned, hidden_manual}]}. */
    public function saveValues() {
        $this->guard(function ($kernel, $in, $row) {
            $id = (int)$row['facet_id'];
            $lang = (int)$this->config->get('config_language_id');
            $V = '`' . $kernel->table('value') . '`';
            $errors = [];
            $slugChanged = false;
            foreach (isset($in['values']) && is_array($in['values']) ? $in['values'] : [] as $v) {
                $vid = (int)(isset($v['value_id']) ? $v['value_id'] : 0);
                $cur = $this->db->query("SELECT * FROM " . $V . " WHERE value_id = " . $vid . " AND facet_id = " . $id)->row;
                if (!$cur) {
                    continue;
                }
                $slug = strtolower(trim(isset($v['slug']) ? (string)$v['slug'] : $cur['slug']));
                if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug)) {
                    $errors[$vid] = $this->language->get('error_value_slug');
                    continue;
                }
                if ($slug !== $cur['slug'] && $this->db->query("SELECT 1 FROM " . $V . " WHERE facet_id = " . $id . " AND slug = '" . $this->db->escape($slug) . "'")->num_rows) {
                    $errors[$vid] = $this->language->get('error_value_slug_exists');
                    continue;
                }
                $slugChanged = $slugChanged || $slug !== $cur['slug'];
                $color = isset($v['color']) ? strtolower(trim((string)$v['color'])) : '';
                if ($color !== '' && !preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/', $color)) {
                    $color = $cur['color'];
                }
                $image = isset($v['image']) ? trim(str_replace('\\', '/', (string)$v['image'])) : '';
                if (strpos($image, '..') !== false) {
                    $image = $cur['image'];
                }
                $hidden = !empty($v['hidden_manual']) ? 1 : 0;
                $this->db->query("UPDATE " . $V . " SET slug = '" . $this->db->escape($slug) . "', color = '" . $this->db->escape($color)
                    . "', image = '" . $this->db->escape(mb_substr($image, 0, 255, 'UTF-8')) . "', sort_order = " . (int)(isset($v['sort_order']) ? $v['sort_order'] : 0)
                    . ", pinned = " . (!empty($v['pinned']) ? 1 : 0) . ", hidden_manual = " . $hidden
                    . ", hidden = IF(" . $hidden . " = 1 OR products = 0, 1, 0) WHERE value_id = " . $vid);
                $name = trim(isset($v['name']) ? (string)$v['name'] : '');
                if ($name !== '') {
                    $this->db->query("INSERT INTO `" . $kernel->table('value_description') . "` SET value_id = " . $vid . ", language_id = " . $lang
                        . ", name = '" . $this->db->escape(mb_substr($name, 0, 255, 'UTF-8')) . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");
                }
            }
            $this->changed($kernel);
            if ($slugChanged) {
                $kernel->autoPages()->runFor(8.0); // адреса посадочных и автостраниц строятся из slug
            }
            return $errors ? ['errors' => $errors] : [];
        });
    }

    /**
     * AJAX: объединение синонимов. Тело — JSON {facet_id, target, ids: []}.
     * Строки индекса синонимов переписываются на основное значение сразу; переиндексация тоже
     * пишет основное (ValueStore::resolve), а старые адреса синонимов ведут на основное.
     */
    public function merge() {
        $this->guard(function ($kernel, $in, $row) {
            $id = (int)$row['facet_id'];
            $target = (int)(isset($in['target']) ? $in['target'] : 0);
            $ids = array_values(array_diff(array_map('intval', isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : []), [$target, 0]));
            $V = '`' . $kernel->table('value') . '`';
            $I = '`' . $kernel->table('index') . '`';
            $t = $this->db->query("SELECT value_id, alias_of FROM " . $V . " WHERE value_id = " . $target . " AND facet_id = " . $id)->row;
            if (!$t || !$ids) {
                return ['error' => $this->language->get('error_merge')];
            }
            if ((int)$t['alias_of']) {
                $target = (int)$t['alias_of']; // цепочек синонимов не бывает
            }
            $ids = array_values(array_diff($ids, [$target]));
            if (!$ids) {
                return ['error' => $this->language->get('error_merge')];
            }
            $in_ = implode(',', $ids);
            // Синонимы самих синонимов тоже переезжают на новое основное значение.
            $this->db->query("UPDATE " . $V . " SET alias_of = " . $target . " WHERE facet_id = " . $id . " AND (value_id IN (" . $in_ . ") OR alias_of IN (" . $in_ . "))");
            $this->db->query("INSERT IGNORE INTO " . $I . " (facet_id, value_id, product_id, generation)
                SELECT facet_id, " . $target . ", product_id, generation FROM " . $I . " WHERE facet_id = " . $id . " AND value_id IN (" . $in_ . ")");
            $this->db->query("DELETE FROM " . $I . " WHERE facet_id = " . $id . " AND value_id IN (" . $in_ . ")");
            // Строки индекса переписаны мимо индексатора — товары основного значения во внешний движок.
            $kernel->touched(array_column($this->db->query("SELECT product_id FROM " . $I . " WHERE facet_id = " . $id . " AND value_id = " . $target)->rows, 'product_id'));
            // Посадочные и шаблоны, ссылавшиеся на синоним, — на основное значение.
            $this->repointPages($kernel, $id, $ids, $target);
            $kernel->job()->recountValues($id);
            $this->changed($kernel);
            $kernel->autoPages()->runFor(8.0);
            return [];
        });
    }

    /**
     * AJAX: разъединение. Тело — JSON {facet_id, value_id}.
     * Какие товары были у синонима, после слияния уже не известно — переиндексируются товары
     * основного значения (их немного: у одного значения).
     */
    public function unmerge() {
        $this->guard(function ($kernel, $in, $row) {
            $id = (int)$row['facet_id'];
            $vid = (int)(isset($in['value_id']) ? $in['value_id'] : 0);
            $V = '`' . $kernel->table('value') . '`';
            $v = $this->db->query("SELECT alias_of FROM " . $V . " WHERE value_id = " . $vid . " AND facet_id = " . $id)->row;
            if (!$v || !(int)$v['alias_of']) {
                return ['error' => $this->language->get('error_payload')];
            }
            $this->db->query("UPDATE " . $V . " SET alias_of = 0 WHERE value_id = " . $vid);
            if ($row['source'] !== 'custom') {
                $products = array_column($this->db->query("SELECT product_id FROM `" . $kernel->table('index') . "` WHERE facet_id = " . $id . " AND value_id = " . (int)$v['alias_of'])->rows, 'product_id');
                foreach (array_chunk($products, 500) as $chunk) {
                    $kernel->indexer()->indexProducts($chunk, $kernel->job()->currentGeneration());
                }
            }
            $kernel->job()->recountValues($id);
            $this->changed($kernel);
            $kernel->autoPages()->runFor(8.0); // как и после объединения: состав автостраниц мог измениться
            return [];
        });
    }

    /** AJAX: новое значение своего фасета. Тело — JSON {facet_id, name}. */
    public function addValue() {
        $this->guard(function ($kernel, $in, $row) {
            $name = trim(isset($in['name']) ? (string)$in['name'] : '');
            if ($row['source'] !== 'custom' || $name === '') {
                return ['error' => $this->language->get('error_value_name')];
            }
            $vid = (new \PsFilter\Index\ValueStore($kernel))->custom((int)$row['facet_id'], $name);
            $this->db->query("UPDATE `" . $kernel->table('value') . "` SET hidden = IF(hidden_manual = 1 OR products = 0, 1, 0) WHERE value_id = " . $vid);
            $this->changed($kernel);
            return ['value_id' => $vid];
        });
    }

    /** AJAX: удаление значения своего фасета (у остальных значение вернется при переиндексации). */
    public function deleteValue() {
        $this->guard(function ($kernel, $in, $row) {
            $vid = (int)(isset($in['value_id']) ? $in['value_id'] : 0);
            if ($row['source'] !== 'custom') {
                return ['error' => $this->language->get('error_payload')];
            }
            $id = (int)$row['facet_id'];
            $ids = array_column($this->db->query("SELECT value_id FROM `" . $kernel->table('value') . "` WHERE facet_id = " . $id . " AND (value_id = " . $vid . " OR alias_of = " . $vid . ")")->rows, 'value_id');
            if ($ids) {
                $list = implode(',', array_map('intval', $ids));
                $products = array_column($this->db->query("SELECT DISTINCT product_id FROM `" . $kernel->table('index') . "` WHERE facet_id = " . $id . " AND value_id IN (" . $list . ")")->rows, 'product_id');
                $this->db->query("DELETE FROM `" . $kernel->table('index') . "` WHERE facet_id = " . $id . " AND value_id IN (" . $list . ")");
                $kernel->touched($products);
                $this->db->query("DELETE FROM `" . $kernel->table('value_description') . "` WHERE value_id IN (" . $list . ")");
                $this->db->query("DELETE FROM `" . $kernel->table('value') . "` WHERE value_id IN (" . $list . ")");
            }
            $this->changed($kernel);
            return [];
        });
    }

    /**
     * AJAX: переопределения по категориям. Тело — JSON {facet_id, rows: [{category_id, status, sort_order, collapsed, widget}]}.
     * Пустые sort_order / collapsed / widget — «как у фасета». Действуют на категорию и все ее поддерево.
     */
    public function saveCategories() {
        $this->guard(function ($kernel, $in, $row) {
            $id = (int)$row['facet_id'];
            $T = '`' . $kernel->table('facet_to_category') . '`';
            $allowed = (int)$row['kind'] === 1 ? self::WIDGETS_RANGE : self::WIDGETS_LIST;
            $this->db->query("DELETE FROM " . $T . " WHERE facet_id = " . $id . " AND is_auto = 0");
            $values = [];
            foreach (isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [] as $r) {
                $cid = (int)(isset($r['category_id']) ? $r['category_id'] : 0);
                if (!$cid) {
                    continue;
                }
                $sort = isset($r['sort_order']) && $r['sort_order'] !== '' && $r['sort_order'] !== null ? (int)$r['sort_order'] : 'NULL';
                $collapsed = isset($r['collapsed']) && $r['collapsed'] !== '' && $r['collapsed'] !== null ? ((int)$r['collapsed'] ? 1 : 0) : 'NULL';
                $widget = isset($r['widget']) ? preg_replace('/[^a-z0-9_]/', '', (string)$r['widget']) : '';
                $widget = $widget !== '' && ((int)$row['kind'] !== 1 || in_array($widget, $allowed, true)) ? "'" . $this->db->escape($widget) . "'" : 'NULL';
                $values[$cid] = "(" . $id . ", " . $cid . ", 0, " . (!empty($r['status']) ? 1 : 0) . ", " . $sort . ", " . $collapsed . ", " . $widget . ")";
            }
            if ($values) {
                $this->db->query("INSERT INTO " . $T . " (facet_id, category_id, is_auto, status, sort_order, collapsed, widget) VALUES " . implode(',', $values)
                    . " ON DUPLICATE KEY UPDATE is_auto = 0, status = VALUES(status), sort_order = VALUES(sort_order), collapsed = VALUES(collapsed), widget = VALUES(widget)");
            }
            $this->changed($kernel);
            return [];
        });
    }

    /** AJAX: новый свой фасет. Тело — JSON {name}. Ответ — адрес формы. */
    public function add() {
        $this->lang();
        $in = json_decode(file_get_contents('php://input'), true);
        $name = trim(is_array($in) && isset($in['name']) ? (string)$in['name'] : '');
        if (!$this->can('modify', 'facet')) {
            $this->json(['error' => $this->language->get('error_permission')]);
            return;
        }
        if ($name === '') {
            $this->json(['error' => $this->language->get('error_facet_name')]);
            return;
        }
        $kernel = $this->kernel();
        $T = '`' . $kernel->table('facet') . '`';
        $base = \PsFilter\Index\Normalizer::slug($name, 40) ?: 'custom';
        $code = $base;
        for ($i = 2; $this->db->query("SELECT 1 FROM " . $T . " WHERE code = '" . $this->db->escape($code) . "'")->num_rows; $i++) {
            $code = $base . '-' . $i;
        }
        $sort = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS s FROM " . $T)->row['s'];
        $this->db->query("INSERT INTO " . $T . " SET code = '" . $this->db->escape($code) . "', source = 'custom', ref_id = 0, kind = 0, widget = 'checkbox', settings = '{}', category_mode = 0, seo_index = 0, status = 1, sort_order = " . $sort);
        $id = (int)$this->db->getLastId();
        // На все языки магазина, включая выключенные (как ValueStore::custom): включат язык — имя уже есть.
        foreach ($this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language`")->rows as $l) {
            $this->db->query("INSERT INTO `" . $kernel->table('facet_description') . "` SET facet_id = " . $id . ", language_id = " . (int)$l['language_id'] . ", name = '" . $this->db->escape(mb_substr($name, 0, 255, 'UTF-8')) . "'");
        }
        $this->changed($kernel);
        $this->json(['redirect' => $this->adminLink('facet', 'form', 'facet_id=' . $id)]);
    }

    /** AJAX: удаление фасета со значениями и строками индекса. */
    public function delete() {
        $this->guard(function ($kernel, $in, $row) {
            $id = (int)$row['facet_id'];
            $this->db->query("DELETE vd FROM `" . $kernel->table('value_description') . "` vd JOIN `" . $kernel->table('value') . "` v ON v.value_id = vd.value_id WHERE v.facet_id = " . $id);
            foreach (['value', 'index', 'index_range', 'facet_description', 'facet_to_category', 'facet_to_store', 'facet'] as $t) {
                $this->db->query("DELETE FROM `" . $kernel->table($t) . "` WHERE facet_id = " . $id);
            }
            $this->changed($kernel);
            return [];
        });
    }

    /**
     * Общая обвязка AJAX-правки фасета: права, разбор JSON, поиск фасета, ответ.
     * $fn($kernel, $in, $row) возвращает массив (ключ error — ошибка) или бросает \DomainException с текстом.
     */
    private function guard(callable $fn) {
        $this->lang();
        $in = json_decode(file_get_contents('php://input'), true);
        $json = [];
        if (!$this->can('modify', 'facet')) {
            $json['error'] = $this->language->get('error_permission');
        } elseif (!is_array($in) || empty($in['facet_id'])) {
            $json['error'] = $this->language->get('error_payload');
        } else {
            $kernel = $this->kernel();
            $row = $this->db->query("SELECT * FROM `" . $kernel->table('facet') . "` WHERE facet_id = " . (int)$in['facet_id'])->row;
            if (!$row) {
                $json['error'] = $this->language->get('error_payload');
            } else {
                try {
                    $json = (array)$fn($kernel, $in, $row);
                    if (!isset($json['error'])) {
                        $json['success'] = $this->language->get('text_success');
                    }
                } catch (\DomainException $e) {
                    $json = ['error' => $e->getMessage()];
                }
            }
        }
        $this->json($json);
    }

    /** @return string проверенный код фасета */
    private function checkCode($kernel, array $in, array $row) {
        $code = strtolower(trim(isset($in['code']) ? (string)$in['code'] : $row['code']));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $code)) {
            throw new \DomainException($this->language->get('error_code'));
        }
        if ($this->db->query("SELECT 1 FROM `" . $kernel->table('facet') . "` WHERE code = '" . $this->db->escape($code) . "' AND facet_id <> " . (int)$row['facet_id'])->num_rows) {
            throw new \DomainException($this->language->get('error_code_exists'));
        }
        return $code;
    }

    /** @return string виджет из запроса; у диапазона — только из списка, у списков — любой (тема может добавить свой). */
    private function widget(array $in, array $row) {
        $widget = preg_replace('/[^a-z0-9_]/', '', isset($in['widget']) ? (string)$in['widget'] : '');
        if ($widget === '' || ((int)$row['kind'] === 1 && !in_array($widget, self::WIDGETS_RANGE, true))) {
            return $row['widget'];
        }
        return $widget;
    }

    /** @return array */
    private function settingsOf(array $row) {
        $settings = json_decode($row['settings'], true);
        return is_array($settings) ? $settings : [];
    }

    /** Значения синонимов в выборе посадочных и шаблонов заменяются основным. */
    private function repointPages($kernel, $facetId, array $ids, $target) {
        foreach ($this->db->query("SELECT page_id, state FROM `" . $kernel->table('page') . "` WHERE state LIKE '%\"" . (int)$facetId . "\"%'")->rows as $p) {
            $state = json_decode($p['state'], true);
            if (!is_array($state) || !isset($state[$facetId]['ids'])) {
                continue;
            }
            $before = array_map('intval', (array)$state[$facetId]['ids']);
            $after = array_map(function ($v) use ($ids, $target) { return in_array($v, $ids, true) ? $target : $v; }, $before);
            if ($after !== $before) {
                $state[$facetId]['ids'] = $after;
                $s = new \PsFilter\State($state);
                // IGNORE: если посадочная с таким выбором уже есть, эта остается как была (ее покажет список).
                $this->db->query("UPDATE IGNORE `" . $kernel->table('page') . "` SET state = '" . $this->db->escape(json_encode($s->all())) . "', state_hash = '" . $s->hash()
                    . "' WHERE page_id = " . (int)$p['page_id']);
            }
        }
    }

    /** Определения, тексты шаблонов и счетчики зависят от фасетов — сбросить все. */
    private function changed($kernel) {
        $kernel->facets()->invalidate();
        $kernel->templates()->invalidate();
        $kernel->cache()->flush();
    }

    /** @return array */
    private function breadcrumbs() {
        return [
            ['text' => $this->language->get('text_home'), 'href' => $this->coreLink('common/dashboard')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->adminLink('base')],
            ['text' => $this->language->get('heading_facets'), 'href' => $this->adminLink('facet')],
        ];
    }

    /** @return string адрес AJAX-действия без &amp; */
    private function link($action) {
        return $this->adminLink('facet', $action);
    }
}
