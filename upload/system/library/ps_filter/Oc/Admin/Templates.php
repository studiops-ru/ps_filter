<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * PS Filter — SEO-шаблоны: какие комбинации фильтра без ручной посадочной можно индексировать
 * и с какими мета-тегами. После правки шаблонов нажмите «Пересчитать посадочные» на экране
 * индексации (или дождитесь ночного cron): автостраницы для карты сайта пересоберутся.
 */
class Templates extends Base
{

    private $error = [];

    public function index() {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_templates'));

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && isset($this->request->post['delete']) && $this->hasModify()) {
            $ids = array_map('intval', (array)(isset($this->request->post['selected']) ? $this->request->post['selected'] : []));
            if ($ids) {
                $this->db->query("DELETE FROM `" . $kernel->table('seo_template') . "` WHERE template_id IN (" . implode(',', $ids) . ")");
                $kernel->templates()->invalidate();
            }
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->adminLink('template'));
        }

        $rows = $this->db->query("SELECT t.*, l.name AS language FROM `" . $kernel->table('seo_template') . "` t
            LEFT JOIN `" . DB_PREFIX . "language` l ON l.language_id = t.language_id ORDER BY t.sort_order, t.template_id")->rows;
        $defs = $kernel->facets()->snapshot((int)$this->config->get('config_language_id'))['facets'];
        $data['templates'] = [];
        foreach ($rows as $r) {
            $data['templates'][] = [
                'template_id' => (int)$r['template_id'],
                'title'       => $r['title'],
                'language'    => $r['language'],
                'scope'       => (int)$r['scope_id'] ? $this->scopeName((int)$r['scope_type'], (int)$r['scope_id']) : $this->language->get((int)$r['scope_type'] === 2 ? 'text_any_brand' : 'text_any_category'),
                'facet'       => (int)$r['facet_id'] && isset($defs[(int)$r['facet_id']]) ? $defs[(int)$r['facet_id']]['name'] : $this->language->get('text_any_facet'),
                'limits'      => (int)$r['max_facets'] . ' / ' . (int)$r['max_values'] . ' / ' . (int)$r['min_products'],
                'status'      => (int)$r['status'] && (int)$r['index_allowed'],
                'edit'        => $this->adminLink('template', 'form', 'template_id=' . (int)$r['template_id']),
            ];
        }
        $data['seo_facets'] = [];
        foreach ($defs as $f) {
            if (!empty($f['seo_index'])) {
                $data['seo_facets'][] = $f['name'];
            }
        }
        $data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
        unset($this->session->data['success']);
        $data['add'] = $this->adminLink('template', 'form');
        $data['action'] = $this->adminLink('template');
        $data['url_facets'] = $this->adminLink('facet');
        $data['url_pages'] = $this->adminLink('page');
        $data['can_modify'] = $this->hasModify();
        $data['breadcrumbs'] = $this->breadcrumbs();

        $this->response->setOutput($this->view('template_list', $this->adminLayout($data)));
    }

    public function form() {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_templates'));
        $id = isset($this->request->get['template_id']) ? (int)$this->request->get['template_id'] : 0;
        $fields = ['language_id', 'scope_type', 'scope_id', 'facet_id', 'max_facets', 'max_values', 'min_products', 'index_allowed', 'title', 'h1', 'meta_description', 'text', 'status', 'sort_order'];

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $p = $this->request->post;
            $set = "store_id = 0, language_id = " . (int)$p['language_id'] . ", scope_type = " . ((int)$p['scope_type'] === 2 ? 2 : 1)
                . ", scope_id = " . (int)$p['scope_id'] . ", facet_id = " . (int)$p['facet_id'] . ", max_facets = " . max(1, (int)$p['max_facets'])
                . ", max_values = " . max(1, (int)$p['max_values']) . ", min_products = " . max(1, (int)$p['min_products'])
                . ", index_allowed = " . (int)!empty($p['index_allowed']) . ", status = " . (int)!empty($p['status']) . ", sort_order = " . (int)$p['sort_order'];
            // Тексты приходят через Request::clean (htmlspecialchars) — храним как есть, разэкранировав.
            foreach (['title', 'h1', 'meta_description', 'text'] as $k) {
                $set .= ", `" . $k . "` = '" . $this->db->escape(html_entity_decode((string)$p[$k], ENT_QUOTES, 'UTF-8')) . "'";
            }
            if ($id) {
                $this->db->query("UPDATE `" . $kernel->table('seo_template') . "` SET " . $set . " WHERE template_id = " . $id);
            } else {
                $this->db->query("INSERT INTO `" . $kernel->table('seo_template') . "` SET " . $set);
            }
            $kernel->templates()->invalidate();
            $this->session->data['success'] = $this->language->get('text_template_saved');
            $this->response->redirect($this->adminLink('template'));
        }

        $row = $id ? $this->db->query("SELECT * FROM `" . $kernel->table('seo_template') . "` WHERE template_id = " . $id)->row : [];
        $defaults = ['language_id' => $kernel->defaultLanguageId(), 'scope_type' => 1, 'scope_id' => 0, 'facet_id' => 0, 'max_facets' => 1, 'max_values' => 1,
            'min_products' => 3, 'index_allowed' => 1, 'title' => '{category} {values}', 'h1' => '{category} {values}',
            'meta_description' => '{category} {values}: {count} товаров, цены от {min_price} ₽.', 'text' => '', 'status' => 1, 'sort_order' => 0];
        foreach ($fields as $f) {
            $data[$f] = isset($this->request->post[$f]) ? html_entity_decode((string)$this->request->post[$f], ENT_QUOTES, 'UTF-8') : (isset($row[$f]) ? $row[$f] : $defaults[$f]);
        }
        $data['scope_name'] = (int)$data['scope_id'] ? $this->scopeName((int)$data['scope_type'], (int)$data['scope_id']) : '';
        $data['error'] = $this->error;

        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();
        $data['facets'] = [];
        foreach ($kernel->facets()->snapshot((int)$this->config->get('config_language_id'))['facets'] as $fid => $f) {
            if ((int)$f['kind'] === 0) {
                $data['facets'][] = ['facet_id' => $fid, 'name' => $f['name'] . (!empty($f['seo_index']) ? '' : ' (' . $this->language->get('text_not_indexed') . ')')];
            }
        }
        $data['action'] = $this->adminLink('template', 'form', $id ? '&template_id=' . $id : '');
        $data['cancel'] = $this->adminLink('template');
        $data['url_category_ac'] = html_entity_decode($this->coreLink(Platform::oc4() ? 'catalog/category.autocomplete' : 'catalog/category/autocomplete'), ENT_QUOTES, 'UTF-8');
        $data['url_manufacturer_ac'] = html_entity_decode($this->coreLink(Platform::oc4() ? 'catalog/manufacturer.autocomplete' : 'catalog/manufacturer/autocomplete'), ENT_QUOTES, 'UTF-8');
        $data['breadcrumbs'] = $this->breadcrumbs();
        $data['can_modify'] = $this->hasModify();

        $this->response->setOutput($this->view('template_form', $this->adminLayout($data)));
    }

    /** @return bool */
    private function validate() {
        if (!$this->hasModify()) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (trim((string)$this->request->post['title']) === '') {
            $this->error['title'] = $this->language->get('error_template_title');
        }
        return !$this->error;
    }

    /** @return bool */
    private function hasModify() {
        return $this->can('modify', 'template');
    }

    private function scopeName($type, $id) {
        if ($type === 2) {
            $q = $this->db->query("SELECT name FROM `" . DB_PREFIX . "manufacturer` WHERE manufacturer_id = " . (int)$id);
            return $q->num_rows ? $q->row['name'] : '#' . $id;
        }
        $q = $this->db->query("SELECT GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ') AS name FROM `" . DB_PREFIX . "category_path` cp
            JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = cp.path_id AND cd.language_id = " . (int)$this->config->get('config_language_id') . "
            WHERE cp.category_id = " . (int)$id);
        return $q->num_rows && $q->row['name'] ? $q->row['name'] : '#' . $id;
    }

    private function breadcrumbs() {
        return [
            ['text' => $this->language->get('text_home'), 'href' => $this->coreLink('common/dashboard')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->adminLink('base')],
            ['text' => $this->language->get('heading_templates'), 'href' => $this->adminLink('template')],
        ];
    }

}
