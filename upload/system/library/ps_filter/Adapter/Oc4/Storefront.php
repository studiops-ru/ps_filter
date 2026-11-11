<?php
namespace PsFilter\Adapter\Oc4;

use PsFilter\Platform;

/**
 * Витрина OpenCart 4: отличия от OC3.
 *
 *  - маршрут бренда product/manufacturer.info, в каждой ссылке параметр language;
 *  - ЧПУ OC4 (startup/seo_url) разбирает сегменты по одному, неизвестный дает error/not_found —
 *    поэтому адреса фильтра и посадочных устроены так же, как в OC3 с SeoPro;
 *  - поиск: filter_search, слова через AND/OR по config_product_search, коды товара в product_code;
 *  - шаблоны модуля лежат в extension/ps_filter/catalog/view/template.
 */
class Storefront extends \PsFilter\Adapter\Oc3\Storefront
{
    protected function builderOptions()
    {
        $config = $this->r->get('config');
        // У главной в OC4 нет ключа ЧПУ: ссылка вида /ru-ru?route=common/home. Корень для посадочных — путь без query.
        $home = str_replace('&amp;', '&', $this->r->get('url')->link('common/home', 'language=' . $config->get('config_language')));
        if ($config->get('config_seo_url')) {
            $home = preg_replace('/\?.*$/', '', $home);
        }
        return [
            'routes'      => ['manufacturer' => Platform::route('manufacturer')],
            'common_args' => 'language=' . $config->get('config_language'),
            'postfix'     => '',
            'addslash'    => false,
            'home'        => $home,
        ];
    }

    /**
     * Условия штатного поиска OC4 (catalog/model/catalog/product.php getProducts).
     *
     * @param array $s search, tag, description, category_id, sub_category
     * @return string
     */
    public function searchSql(array $s, $languageId)
    {
        $db = $this->k->db();
        $P = $this->k->prefix();
        $glue = $this->r->get('config')->get('config_product_search') == 'and' ? ' AND ' : ' OR ';
        $name = isset($s['search']) ? $s['search'] : '';
        $tag = isset($s['tag']) && $s['tag'] !== '' ? $s['tag'] : $name;
        $sql = "SELECT DISTINCT p.product_id FROM `{$P}product` p
            JOIN `{$P}product_description` pd ON pd.product_id = p.product_id AND pd.language_id = " . (int)$languageId . " WHERE 1";

        if (!empty($s['category_id'])) {
            if ((int)($s['sub_category'] ?? 0)) { // OC4 приводит к числу: «true» — это 0
                $sql .= " AND EXISTS (SELECT 1 FROM `{$P}product_to_category` p2c JOIN `{$P}category_path` cp ON cp.category_id = p2c.category_id
                    WHERE p2c.product_id = p.product_id AND cp.path_id = " . (int)$s['category_id'] . ")";
            } else {
                $sql .= " AND EXISTS (SELECT 1 FROM `{$P}product_to_category` p2c WHERE p2c.product_id = p.product_id AND p2c.category_id = " . (int)$s['category_id'] . ")";
            }
        }

        $words = function ($text, $column) use ($db, $glue) {
            $out = [];
            foreach (array_filter(explode(' ', trim(preg_replace('/\s+/', ' ', $text)))) as $word) {
                $out[] = $column . " LIKE '" . $db->escape('%' . $word . '%') . "'";
            }
            return $out ? '(' . implode($glue, $out) . ')' : '';
        };
        $or = [];
        if ($name !== '') {
            $or[] = $words($name, 'pd.name');
            if ((int)($s['description'] ?? 0)) {
                $or[] = $words($name, 'pd.description');
            }
        }
        if ($tag !== '') {
            $or[] = $words($tag, 'pd.tag');
        }
        if ($name !== '') {
            $or[] = "LCASE(p.model) = '" . $db->escape(mb_strtolower($name, 'UTF-8')) . "'";
            $or[] = "EXISTS (SELECT 1 FROM `{$P}product_code` pc WHERE pc.product_id = p.product_id AND pc.value LIKE '" . $db->escape($name . '%') . "')";
        }
        $or = array_filter($or);
        if ($or) {
            $sql .= " AND (" . implode(' OR ', $or) . ")";
        }
        return $sql;
    }

    public function seoRequest()
    {
        $out = parent::seoRequest();
        $out['limit_default'] = (int)$this->r->get('config')->get('config_pagination');
        return $out;
    }

    protected function canonicalParams()
    {
        return array_merge(parent::canonicalParams(), ['language']);
    }

    /**
     * Шаблоны панели: переопределения магазина (catalog/view/template/ps_filter/) и родные расширения.
     * Пути — от корня сайта: так их понимает загрузчик Twig в OC4.
     *
     * @return array
     */
    public function templateDirs()
    {
        $own = 'extension/ps_filter/catalog/view/template/module/ps_filter/';
        $custom = 'catalog/view/template/ps_filter/';
        return [is_dir(DIR_OPENCART . $custom) ? $custom : $own, $own];
    }
}
