<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * PS Filter — посадочные страницы: список, форма с конструктором фильтра, проверка
 * ключевых слов, удаление. Сохранение — JSON через AJAX.
 *
 * Конструктор берет фасеты и значения прямо из движка для выбранного раздела: видно только то,
 * что в разделе реально есть, с числом товаров.
 */
class Pages extends Base
{

    public function index() {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_pages'));

        $filter = isset($this->request->get['filter']) ? trim((string)$this->request->get['filter']) : '';
        $page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
        $limit = 25;
        $lang = (int)$this->config->get('config_language_id');

        $where = "p.is_auto = 0";
        if ($filter !== '') {
            $f = $this->db->escape($filter);
            $where .= " AND (pd.name LIKE '%" . $f . "%' OR u.keyword LIKE '%" . $f . "%')";
        }
        $from = "FROM `" . $kernel->table('page') . "` p
            LEFT JOIN `" . $kernel->table('page_description') . "` pd ON pd.page_id = p.page_id AND pd.language_id = " . $lang . "
            LEFT JOIN `" . $kernel->table('page_url') . "` u ON u.page_id = p.page_id AND u.store_id = 0 AND u.language_id = " . $lang;
        $total = (int)$this->db->query("SELECT COUNT(DISTINCT p.page_id) AS c " . $from . " WHERE " . $where)->row['c'];
        $rows = $this->db->query("SELECT p.*, pd.name, u.keyword " . $from . " WHERE " . $where . " GROUP BY p.page_id ORDER BY p.sort_order, pd.name LIMIT " . (($page - 1) * $limit) . ", " . $limit)->rows;

        $data['pages'] = [];
        foreach ($rows as $r) {
            $data['pages'][] = [
                'page_id'  => (int)$r['page_id'],
                'name'     => $r['name'],
                'keyword'  => $r['keyword'],
                'url_mode' => (int)$r['url_mode'],
                'scope'    => $this->scopeName((int)$r['scope_type'], (int)$r['scope_id']),
                'count'    => (int)$r['product_count'],
                'status'   => (int)$r['status'],
                'edit'     => $this->adminLink('page', 'form', 'page_id=' . (int)$r['page_id']),
                'view'     => $this->previewUrl($r),
            ];
        }
        $data['auto_count'] = (int)$this->db->query("SELECT COUNT(*) AS c FROM `" . $kernel->table('page') . "` WHERE is_auto = 1")->row['c'];

        $data['pagination'] = $this->pagination($total, $page, $limit, $this->adminLink('page', '', 'filter=' . urlencode($filter) . '&page={page}'));
        $data['filter'] = $filter;

        $data['breadcrumbs'] = $this->breadcrumbs();
        $data['add'] = $this->adminLink('page', 'form');
        $data['url_delete'] = $this->adminLink('page', 'delete');
        $data['url_list'] = $this->adminLink('page');
        $data['route_list'] = $this->route('page');
        $data['url_templates'] = $this->adminLink('template');
        $data['url_export'] = $this->adminLink('page', 'export');
        $data['url_import'] = $this->adminLink('page', 'import');
        $data['can_modify'] = $this->can('modify', 'page');

        $this->response->setOutput($this->view('page_list', $this->adminLayout($data)));
    }

    public function form() {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_pages'));
        $pageId = isset($this->request->get['page_id']) ? (int)$this->request->get['page_id'] : 0;

        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();

        $page = $pageId ? $kernel->pages()->get($pageId) : null;
        $descriptions = [];
        $keywords = [];
        if ($page) {
            foreach ($this->db->query("SELECT * FROM `" . $kernel->table('page_description') . "` WHERE page_id = " . $pageId)->rows as $d) {
                $descriptions[(int)$d['language_id']] = $d;
            }
            foreach ($this->db->query("SELECT language_id, keyword FROM `" . $kernel->table('page_url') . "` WHERE page_id = " . $pageId . " AND store_id = 0")->rows as $u) {
                $keywords[(int)$u['language_id']] = $u['keyword'];
            }
        }
        $data['page'] = [
            'page_id'      => $pageId,
            'scope_type'   => $page ? (int)$page['scope_type'] : 1,
            'scope_id'     => $page ? (int)$page['scope_id'] : 0,
            'scope_name'   => $page ? $this->scopeName((int)$page['scope_type'], (int)$page['scope_id'], false) : '',
            'state'        => $page ? json_decode($page['state'], true) : new \stdClass(),
            'url_mode'     => $page ? (int)$page['url_mode'] : 0,
            'status'       => $page ? (int)$page['status'] : 1,
            'in_panel'     => $page ? (int)$page['in_panel'] : 1,
            'in_category'  => $page ? (int)$page['in_category'] : 1,
            'in_sitemap'   => $page ? (int)$page['in_sitemap'] : 1,
            'sort_order'   => $page ? (int)$page['sort_order'] : 0,
            'description'  => $descriptions ?: new \stdClass(),
            'keyword'      => $keywords ?: new \stdClass(),
        ];
        $data['page_json'] = json_encode($data['page'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $data['languages_json'] = json_encode(array_values(array_map(function ($l) {
            return ['language_id' => (int)$l['language_id'], 'name' => $l['name'], 'code' => $l['code']];
        }, $data['languages'])), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        foreach (['save', 'facets', 'count', 'parse'] as $a) {
            $data['url_' . $a] = $this->adminLink('page', $a);
        }
        $data['url_category_ac'] = html_entity_decode($this->coreLink(Platform::oc4() ? 'catalog/category.autocomplete' : 'catalog/category/autocomplete'), ENT_QUOTES, 'UTF-8');
        $data['url_manufacturer_ac'] = html_entity_decode($this->coreLink(Platform::oc4() ? 'catalog/manufacturer.autocomplete' : 'catalog/manufacturer/autocomplete'), ENT_QUOTES, 'UTF-8');
        $data['url_list'] = $this->adminLink('page');
        $data['breadcrumbs'] = $this->breadcrumbs();
        $data['can_modify'] = $this->can('modify', 'page');
        $data['catalog'] = $this->catalogUrl();

        $this->response->setOutput($this->view('page_form', $this->adminLayout($data)));
    }

    /**
     * AJAX: фасеты и значения раздела для конструктора.
     * GET scope_type, scope_id → {facets: [{facet_id, code, name, type, values: [{value_id, name, count}], min, max}], total}
     */
    public function facets() {
        $this->ajax(function ($kernel) {
            $get = $this->request->get;
            $ctx = $this->context((int)(isset($get['scope_type']) ? $get['scope_type'] : 0), (int)(isset($get['scope_id']) ? $get['scope_id'] : 0));
            $result = $kernel->engine()->search($ctx, new \PsFilter\State());
            $defs = $kernel->facets()->snapshot($ctx->languageId)['facets'];
            $facets = [];
            foreach ($defs as $fid => $f) {
                $item = ['facet_id' => $fid, 'code' => $f['code'], 'name' => $f['name'], 'type' => (int)$f['kind'] === 1 ? 'range' : 'list', 'values' => []];
                if ($item['type'] === 'range') {
                    if (!isset($result->baseRanges[$fid])) {
                        continue;
                    }
                    list($item['min'], $item['max']) = $result->baseRanges[$fid];
                } else {
                    if (empty($result->baseCounts[$fid])) {
                        continue;
                    }
                    foreach ($f['values'] as $vid => $v) {
                        if (!empty($result->baseCounts[$fid][$vid])) {
                            $item['values'][] = ['value_id' => $vid, 'name' => $v['name'], 'count' => $result->baseCounts[$fid][$vid]];
                        }
                    }
                }
                $facets[] = $item;
            }
            return ['facets' => $facets, 'total' => $result->totalBase];
        }, false);
    }

    /** AJAX: число товаров для раздела и состояния. POST JSON {scope_type, scope_id, state}. */
    public function count() {
        $this->ajax(function ($kernel) {
            $in = $this->input();
            $ctx = $this->context((int)(isset($in['scope_type']) ? $in['scope_type'] : 0), (int)(isset($in['scope_id']) ? $in['scope_id'] : 0));
            $state = new \PsFilter\State(isset($in['state']) ? (array)$in['state'] : []);
            $result = $kernel->engine()->search($ctx, $state);
            return ['total' => (int)$result->total];
        }, false);
    }

    /**
     * AJAX: состояние из адреса витрины (вставили ссылку отфильтрованной категории).
     * POST JSON {url} → {state} — понимает /filter/код=значения/ и ?f=…
     */
    public function parse() {
        $this->ajax(function ($kernel) {
            $in = $this->input();
            $url = rawurldecode(isset($in['url']) ? (string)$in['url'] : '');
            $tokens = [];
            $prefix = '/' . $kernel->settings()->get('url_prefix', 'filter') . '/';
            if (($pos = strpos($url, $prefix)) !== false) {
                $tail = substr($url, $pos + strlen($prefix));
                $tail = preg_replace('/[?#].*$/', '', $tail);
                $tokens = explode('/', $tail);
            } elseif (preg_match('/[?&]f=([^&#]+)/', $url, $m)) {
                $tokens = explode(';', $m[1]);
            }
            $codec = new \PsFilter\Url\Codec($kernel->facets()->snapshot($kernel->defaultLanguageId()));
            $dirty = false;
            $state = $codec->decode($tokens, $dirty);
            return ['state' => $state->all() ?: new \stdClass(), 'dirty' => $dirty];
        }, false);
    }

    /** AJAX: сохранение. POST JSON — поля страницы как в форме. */
    public function save() {
        $this->ajax(function ($kernel) {
            $in = $this->input();
            $in += ['page_id' => 0, 'scope_type' => 1, 'scope_id' => 0, 'url_mode' => 0, 'sort_order' => 0];
            $pageId = (int)$in['page_id'];
            $scopeType = (int)$in['scope_type'] === 2 ? 2 : 1;
            $scopeId = (int)$in['scope_id'];
            $urlMode = (int)$in['url_mode'] === 1 ? 1 : 0;
            $state = new \PsFilter\State(isset($in['state']) ? (array)$in['state'] : []);
            $errors = [];

            if (!$scopeId) {
                $errors['scope'] = $this->language->get('error_page_scope');
            }
            if ($state->isEmpty()) {
                $errors['state'] = $this->language->get('error_page_state');
            }
            $defaultLang = $kernel->defaultLanguageId();
            $keywords = isset($in['keyword']) ? (array)$in['keyword'] : [];
            $descriptions = isset($in['description']) ? (array)$in['description'] : [];
            // Описание языка — массив полей-строк; все прочее в теле (число, строка, вложенность) — к пустым полям.
            $fieldsDesc = ['name' => '', 'h1' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'description' => '', 'text_position' => 0];
            foreach ($descriptions as $lang => $d) {
                $clean = [];
                foreach ($fieldsDesc as $f => $empty) {
                    $v = is_array($d) && isset($d[$f]) && !is_array($d[$f]) ? $d[$f] : $empty;
                    $clean[$f] = $f === 'text_position' ? (int)$v : (string)$v;
                }
                $descriptions[$lang] = $clean;
            }
            foreach ($keywords as $lang => $kw) {
                $keywords[$lang] = is_array($kw) ? '' : (string)$kw;
            }
            foreach ($descriptions as $lang => $d) {
                if (trim((string)(isset($d['name']) ? $d['name'] : '')) === '' && (int)$lang === $defaultLang) {
                    $errors['name_' . $lang] = $this->language->get('error_page_name');
                }
            }
            foreach ($keywords as $lang => $kw) {
                $kw = mb_strtolower(trim((string)$kw), 'UTF-8');
                if ($kw === '') {
                    if ((int)$lang === $defaultLang) {
                        $errors['keyword_' . $lang] = $this->language->get('error_keyword_required');
                    }
                    continue;
                }
                $conflict = $kernel->pages()->keywordConflict($kw, $lang, $urlMode, $scopeType, $scopeId, $pageId);
                if ($conflict) {
                    $errors['keyword_' . $lang] = $this->language->get('error_keyword_' . $conflict);
                }
            }
            if ($scopeId) {
                $map = $kernel->pages()->scopeMap(0, $defaultLang, $scopeType, $scopeId);
                if (isset($map[$state->hash()]) && $map[$state->hash()]['page_id'] !== $pageId && !$map[$state->hash()]['is_auto']) {
                    $errors['state'] = $this->language->get('error_page_duplicate');
                }
            }
            if ($errors) {
                return ['errors' => $errors, 'error' => $this->language->get('error_form')];
            }

            $ctx = $this->context($scopeType, $scopeId);
            $total = (int)$kernel->engine()->search($ctx, $state)->total;
            $id = $kernel->pages()->save([
                'scope_type' => $scopeType, 'scope_id' => $scopeId, 'state' => $state->all(), 'url_mode' => $urlMode,
                'status' => !empty($in['status']), 'in_panel' => !empty($in['in_panel']), 'in_category' => !empty($in['in_category']),
                'in_sitemap' => !empty($in['in_sitemap']), 'sort_order' => (int)$in['sort_order'], 'product_count' => $total,
                'description' => $descriptions, 'keyword' => $keywords,
            ], $pageId);
            return ['success' => $this->language->get('text_success'), 'page_id' => $id, 'total' => $total];
        });
    }

    /** AJAX: удаление. POST JSON {ids: []}. */
    public function delete() {
        $this->ajax(function ($kernel) {
            $in = $this->input();
            $kernel->pages()->delete(isset($in['ids']) ? (array)$in['ids'] : []);
            return ['success' => $this->language->get('text_success')];
        });
    }

    /**
     * Выгрузка ручных посадочных в JSON (резервная копия, перенос между копиями сайта).
     * Выбор фильтра пишется кодами фасетов и адресами значений, языки — кодами:
     * id значений суррогатные и в другой копии могут отличаться. Раздел — по id.
     */
    public function export() {
        $this->lang();
        if (!$this->can('access', 'page')) {
            $this->response->setOutput($this->language->get('error_permission'));
            return;
        }
        $kernel = $this->kernel();
        $db = $this->db;
        $defs = $kernel->facets()->snapshot($kernel->defaultLanguageId())['facets'];
        $langCodes = array_column($db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language`")->rows, 'code', 'language_id');

        $pages = [];
        foreach ($db->query("SELECT * FROM `" . $kernel->table('page') . "` WHERE is_auto = 0 ORDER BY page_id")->rows as $p) {
            $state = [];
            foreach ((array)json_decode($p['state'], true) as $fid => $sel) {
                if (!isset($defs[$fid])) {
                    continue;
                }
                if (isset($sel['ids'])) {
                    $slugs = [];
                    foreach ((array)$sel['ids'] as $vid) {
                        if (isset($defs[$fid]['values'][(int)$vid])) {
                            $slugs[] = $defs[$fid]['values'][(int)$vid]['slug'];
                        }
                    }
                    $state[$defs[$fid]['code']] = $slugs;
                } else {
                    $state[$defs[$fid]['code']] = ['min' => isset($sel['min']) ? $sel['min'] : null, 'max' => isset($sel['max']) ? $sel['max'] : null];
                }
            }
            $description = [];
            foreach ($db->query("SELECT * FROM `" . $kernel->table('page_description') . "` WHERE page_id = " . (int)$p['page_id'])->rows as $d) {
                if (isset($langCodes[$d['language_id']])) {
                    $description[$langCodes[$d['language_id']]] = [
                        'name' => $d['name'], 'h1' => $d['h1'], 'meta_title' => $d['meta_title'], 'meta_description' => $d['meta_description'],
                        'meta_keyword' => $d['meta_keyword'], 'description' => $d['description'], 'text_position' => (int)$d['text_position'],
                    ];
                }
            }
            $keyword = [];
            foreach ($db->query("SELECT DISTINCT language_id, keyword FROM `" . $kernel->table('page_url') . "` WHERE page_id = " . (int)$p['page_id'] . " AND store_id = 0")->rows as $u) {
                if (isset($langCodes[$u['language_id']])) {
                    $keyword[$langCodes[$u['language_id']]] = $u['keyword'];
                }
            }
            $pages[] = [
                'scope_type' => (int)$p['scope_type'] === 2 ? 'manufacturer' : 'category', 'scope_id' => (int)$p['scope_id'],
                'scope_name' => $this->scopeName((int)$p['scope_type'], (int)$p['scope_id'], false),
                'state' => $state, 'url_mode' => (int)$p['url_mode'], 'status' => (int)$p['status'], 'in_panel' => (int)$p['in_panel'],
                'in_category' => (int)$p['in_category'], 'in_sitemap' => (int)$p['in_sitemap'], 'sort_order' => (int)$p['sort_order'],
                'description' => $description, 'keyword' => $keyword,
            ];
        }

        $json = json_encode(['format' => 'ps_filter.pages', 'version' => 1, 'exported' => date('c'), 'pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="ps_filter_pages_' . date('Y-m-d') . '.json"');
        $this->response->setOutput($json);
    }

    /**
     * Загрузка посадочных из JSON выгрузки. Страница того же раздела с тем же выбором обновляется,
     * иначе создается. Не найден фасет/значение, занят ключ — страница пропускается с причиной.
     */
    public function import() {
        $this->ajax(function ($kernel) {
            $in = $this->input();
            if (!isset($in['format']) || $in['format'] !== 'ps_filter.pages' || !isset($in['pages']) || !is_array($in['pages'])) {
                return ['error' => $this->language->get('error_import_format')];
            }
            $snapshot = $kernel->facets()->snapshot($kernel->defaultLanguageId());
            $defs = $snapshot['facets'];
            $langIds = array_column($this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language`")->rows, 'language_id', 'code');
            $defaultLang = $kernel->defaultLanguageId();
            $report = ['created' => 0, 'updated' => 0, 'skipped' => []];

            foreach ($in['pages'] as $n => $p) {
                $label = '#' . ($n + 1) . (isset($p['keyword']) && is_array($p['keyword']) && $p['keyword'] ? ' «' . reset($p['keyword']) . '»' : '');
                $scopeType = isset($p['scope_type']) && $p['scope_type'] === 'manufacturer' ? 2 : 1;
                $scopeId = (int)(isset($p['scope_id']) ? $p['scope_id'] : 0);
                $exists = $scopeType === 2
                    ? $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "manufacturer` WHERE manufacturer_id = " . $scopeId)->num_rows
                    : $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "category` WHERE category_id = " . $scopeId)->num_rows;
                if (!$exists) {
                    $report['skipped'][] = $label . ': ' . $this->language->get('error_import_scope');
                    continue;
                }
                // Выбор: коды и адреса → id этого магазина.
                $sel = [];
                $missing = [];
                foreach (isset($p['state']) && is_array($p['state']) ? $p['state'] : [] as $code => $v) {
                    $fid = isset($snapshot['codes'][$code]) ? $snapshot['codes'][$code] : 0;
                    if (!$fid) {
                        $missing[] = $code;
                        continue;
                    }
                    if (isset($v['min']) || isset($v['max'])) {
                        $sel[$fid] = ['min' => isset($v['min']) ? $v['min'] : null, 'max' => isset($v['max']) ? $v['max'] : null];
                        continue;
                    }
                    $ids = [];
                    foreach ((array)$v as $slug) {
                        if (isset($defs[$fid]['slugs'][$slug])) {
                            $ids[] = $defs[$fid]['slugs'][$slug];
                        } else {
                            $missing[] = $code . '=' . $slug;
                        }
                    }
                    $sel[$fid] = ['ids' => $ids];
                }
                $state = new \PsFilter\State($sel);
                if ($missing || $state->isEmpty()) {
                    $report['skipped'][] = $label . ': ' . $this->language->get('error_import_state') . ' ' . implode(', ', $missing);
                    continue;
                }
                $map = $kernel->pages()->scopeMap(0, $defaultLang, $scopeType, $scopeId);
                $pageId = isset($map[$state->hash()]) && !$map[$state->hash()]['is_auto'] ? (int)$map[$state->hash()]['page_id'] : 0;
                $urlMode = !empty($p['url_mode']) ? 1 : 0;

                $description = [];
                foreach (isset($p['description']) && is_array($p['description']) ? $p['description'] : [] as $code => $d) {
                    if (isset($langIds[$code]) && is_array($d)) {
                        $description[(int)$langIds[$code]] = array_merge(['name' => '', 'h1' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'description' => '', 'text_position' => 0], $d);
                    }
                }
                $keyword = [];
                $conflict = '';
                foreach (isset($p['keyword']) && is_array($p['keyword']) ? $p['keyword'] : [] as $code => $kw) {
                    if (!isset($langIds[$code]) || trim((string)$kw) === '') {
                        continue;
                    }
                    $kw = mb_strtolower(trim((string)$kw), 'UTF-8');
                    $c = $kernel->pages()->keywordConflict($kw, (int)$langIds[$code], $urlMode, $scopeType, $scopeId, $pageId);
                    if ($c) {
                        $conflict = $kw . ' — ' . $this->language->get('error_keyword_' . $c);
                        break;
                    }
                    $keyword[(int)$langIds[$code]] = $kw;
                }
                if ($conflict || empty($keyword[$defaultLang]) || empty($description[$defaultLang]['name'])) {
                    $report['skipped'][] = $label . ': ' . ($conflict ?: $this->language->get('error_import_texts'));
                    continue;
                }

                $kernel->pages()->save([
                    'scope_type' => $scopeType, 'scope_id' => $scopeId, 'state' => $state->all(), 'url_mode' => $urlMode,
                    'status' => !empty($p['status']), 'in_panel' => !empty($p['in_panel']), 'in_category' => !empty($p['in_category']),
                    'in_sitemap' => !empty($p['in_sitemap']), 'sort_order' => (int)(isset($p['sort_order']) ? $p['sort_order'] : 0),
                    'product_count' => (int)$kernel->engine()->search($this->context($scopeType, $scopeId), $state)->total,
                    'description' => $description, 'keyword' => $keyword,
                ], $pageId);
                $kernel->engine()->release();
                $report[$pageId ? 'updated' : 'created']++;
            }
            $report['success'] = sprintf($this->language->get('text_import_done'), $report['created'], $report['updated'], count($report['skipped']));
            return $report;
        });
    }

    /* ===================== Служебное ===================== */

    /** @return \PsFilter\Context контекст раздела для движка (магазин 0, язык по умолчанию) */
    private function context($scopeType, $scopeId) {
        $ctx = new \PsFilter\Context();
        $ctx->type = (int)$scopeType === 2 ? \PsFilter\Context::MANUFACTURER : \PsFilter\Context::CATEGORY;
        $ctx->id = (int)$scopeId;
        $ctx->languageId = $this->kernel()->defaultLanguageId();
        $ctx->customerGroupId = (int)$this->config->get('config_customer_group_id');
        $ctx->subtree = (bool)$this->kernel()->settings()->get('scope_subtree');
        $this->kernel()->applyStock($ctx);
        return $ctx;
    }

    /** Название раздела: «Электроника > Смартфоны > Флагманы» или «Бренд: Titan». */
    private function scopeName($type, $id, $withPrefix = true) {
        $lang = (int)$this->config->get('config_language_id');
        if ($type === 2) {
            $q = $this->db->query("SELECT name FROM `" . DB_PREFIX . "manufacturer` WHERE manufacturer_id = " . (int)$id);
            return ($withPrefix ? $this->language->get('text_scope_brand') . ': ' : '') . ($q->num_rows ? $q->row['name'] : '#' . $id);
        }
        $q = $this->db->query("SELECT GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ') AS name FROM `" . DB_PREFIX . "category_path` cp
            JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = cp.path_id AND cd.language_id = " . $lang . "
            WHERE cp.category_id = " . (int)$id);
        return $q->num_rows && $q->row['name'] ? $q->row['name'] : '#' . $id;
    }

    /** Ссылка «посмотреть»: адрес фильтра на витрине, витрина сама уведет 301 на посадочную. */
    private function previewUrl(array $row) {
        $catalog = $this->catalogUrl();
        $kernel = $this->kernel();
        $codec = new \PsFilter\Url\Codec($kernel->facets()->snapshot($kernel->defaultLanguageId()));
        $f = $codec->encodeQuery(new \PsFilter\State((array)json_decode($row['state'], true)));
        $lang = Platform::oc4() ? '&language=' . (string)$this->config->get('config_language_catalog') : '';
        if ((int)$row['scope_type'] === 2) {
            return $catalog . 'index.php?route=' . Platform::route('manufacturer') . $lang . '&manufacturer_id=' . (int)$row['scope_id'] . '&f=' . rawurlencode($f);
        }
        return $catalog . 'index.php?route=product/category' . $lang . '&path=' . (int)$row['scope_id'] . '&f=' . rawurlencode($f);
    }


    /** @return array */
    private function breadcrumbs() {
        return [
            ['text' => $this->language->get('text_home'), 'href' => $this->coreLink('common/dashboard')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->adminLink('base')],
            ['text' => $this->language->get('heading_pages'), 'href' => $this->adminLink('page')],
        ];
    }


    /**
     * Общая обертка AJAX: права, исключения, JSON.
     *
     * @param bool $modify требуется право изменения (для чтения достаточно доступа)
     */
    private function ajax(callable $action, $modify = true) {
        $this->lang();
        $json = [];
        if (!$this->can($modify ? 'modify' : 'access', 'page')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            try {
                $json = $action($this->kernel());
            } catch (\Exception $e) {
                $json['error'] = $e->getMessage();
            }
        }
        $this->json($json);
    }

}
