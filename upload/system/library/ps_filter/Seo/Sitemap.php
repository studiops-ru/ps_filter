<?php
namespace PsFilter\Seo;

use PsFilter\Kernel;
use PsFilter\State;

/**
 * Адреса для карты сайта: включенные посадочные (с товарами, в карте) и автостраницы SEO-шаблонов.
 * Работает только внутри витрины OpenCart: адреса строит штатный url->link через Storefront.
 */
class Sitemap
{
    /** @var Kernel */
    private $k;
    /** @var array scope => Context */
    private $contexts = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /** @return int число адресов */
    public function count()
    {
        return (int)$this->k->db()->query("SELECT COUNT(*) AS c FROM `" . $this->k->table('page') . "` p WHERE " . $this->where())->row['c'];
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return array [loc, lastmod]
     */
    public function entries($offset, $limit)
    {
        $front = $this->k->storefront();
        $builder = $front->builder();
        $q = $this->k->db()->query("SELECT p.page_id, p.scope_type, p.scope_id, p.state, p.is_auto, p.date_modified, u.keyword, u.url_mode
            FROM `" . $this->k->table('page') . "` p
            LEFT JOIN `" . $this->k->table('page_url') . "` u ON u.page_id = p.page_id AND u.store_id = " . $this->storeId() . " AND u.language_id = " . $this->languageId() . "
            WHERE " . $this->where() . " ORDER BY p.is_auto, p.page_id LIMIT " . max(0, (int)$offset) . ", " . max(1, (int)$limit));
        $out = [];
        foreach ($q->rows as $r) {
            $key = $r['scope_type'] . ':' . $r['scope_id'];
            if (!isset($this->contexts[$key])) {
                $this->contexts[$key] = $front->scopeContext($r['scope_type'], $r['scope_id']);
            }
            $ctx = $this->contexts[$key];
            if (!(int)$r['is_auto'] && $r['keyword'] !== null && $r['keyword'] !== '') {
                $loc = $builder->landingUrl($ctx, $r['keyword'], $r['url_mode']);
            } elseif ((int)$r['is_auto']) {
                $loc = $builder->url($ctx, new State((array)json_decode($r['state'], true)));
            } else {
                continue; // ручная страница без ключевого слова на этом языке
            }
            $out[] = ['loc' => $loc, 'lastmod' => date('Y-m-d', strtotime($r['date_modified']))];
        }
        return $out;
    }

    /** @return string */
    private function where()
    {
        $min = max(1, (int)$this->k->settings()->get('seo_min_products', 1));
        return "p.status = 1 AND p.in_sitemap = 1 AND p.product_count >= " . $min . " AND p.store_id = 0";
    }

    /** @return int */
    private function storeId()
    {
        $r = $this->k->registry();
        return $r ? (int)$r->get('config')->get('config_store_id') : 0;
    }

    /** @return int */
    private function languageId()
    {
        $r = $this->k->registry();
        return $r ? (int)$r->get('config')->get('config_language_id') : $this->k->defaultLanguageId();
    }
}
