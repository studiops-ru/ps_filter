<?php
namespace PsFilter;

/**
 * Число товаров категорий (с подкатегориями) для меню и списка подкатегорий — из индекса фильтра.
 *
 * Штатный OpenCart при config_product_count считает каждую категорию отдельным COUNT по товарам
 * с JOIN описаний и магазинов: на 200 тыс. товаров это секунды на каждую страницу с меню.
 * В ps_filter_category_product уже лежат привязки товара ко всем предкам, поэтому все счетчики
 * магазина — один GROUP BY, кеш на час (сбрасывается при изменении товаров).
 * Условия те же, что у штатного getTotalProducts: товар включен, доступен по дате, есть в магазине.
 */
class CategoryCounts
{
    /** @var Kernel */
    private $k;
    /** @var array store_id => [category_id => count] */
    private $memory = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Наш ли это вызов getTotalProducts: только категория с подкатегориями, без поиска и фильтров.
     *
     * @param array $data аргумент модели
     * @return bool
     */
    public static function applies(array $data)
    {
        if (empty($data['filter_category_id']) || empty($data['filter_sub_category'])) {
            return false;
        }
        foreach (['filter_name', 'filter_search', 'filter_tag', 'filter_filter', 'filter_manufacturer_id'] as $k) {
            if (!empty($data[$k])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return int
     */
    public function get($categoryId, $storeId)
    {
        $storeId = (int)$storeId;
        if (!isset($this->memory[$storeId])) {
            // При группировке выдачи (group_by) в меню — число групп, как «Найдено» в самом разделе.
            $group = $this->k->groupField();
            $key = 'catcounts|' . $storeId . '|' . date('Y-m-d') . ($group !== '' ? '|g:' . $group : '');
            $counts = $this->k->cache()->get($key);
            if (!is_array($counts)) {
                $counts = [];
                $q = $this->k->db()->query("SELECT c.category_id, " . ($group !== '' ? 'COUNT(DISTINCT f.grp)' : 'COUNT(*)') . " AS n FROM `" . $this->k->table('category_product') . "` c
                    JOIN `" . $this->k->table('product') . "` f ON f.product_id = c.product_id
                    WHERE f.active = 1 AND f.date_available <= CURDATE() AND (f.store_mask & " . (1 << min(31, $storeId)) . ") <> 0
                    GROUP BY c.category_id");
                foreach ($q->rows as $r) {
                    $counts[(int)$r['category_id']] = (int)$r['n'];
                }
                $this->k->cache()->set($key, 'catcounts', $counts, 3600);
            }
            $this->memory[$storeId] = $counts;
        }
        return isset($this->memory[$storeId][(int)$categoryId]) ? $this->memory[$storeId][(int)$categoryId] : 0;
    }
}
