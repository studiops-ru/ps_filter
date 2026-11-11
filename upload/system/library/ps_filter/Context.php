<?php
namespace PsFilter;

/**
 * Контекст страницы, на которой работает фильтр: что за множество товаров и для кого.
 * Не зависит от OpenCart: его собирает адаптер витрины и передает движку.
 */
class Context
{
    const CATEGORY = 'category';
    const MANUFACTURER = 'manufacturer';
    const SEARCH = 'search';

    /** @var string category|manufacturer|search */
    public $type = self::CATEGORY;
    /** @var array параметры поиска как в запросе: search, tag, description, category_id, sub_category */
    public $search = [];
    /** @var string SELECT product_id товаров, найденных поиском (условия штатного поиска или набор ps_search) */
    public $searchSql = '';
    /** @var array|null порядок релевантности от ps_search: table, set (позиции товаров в его наборе), stock_last */
    public $searchRank = null;
    /** @var int category_id или manufacturer_id */
    public $id = 0;
    /** @var string значение path (для ссылок категории) */
    public $path = '';
    /** @var int */
    public $storeId = 0;
    /** @var int */
    public $languageId = 1;
    /** @var int */
    public $customerGroupId = 1;
    /** @var bool только товары в наличии */
    public $onlyInstock = false;
    /** @var bool включать товары подкатегорий (штатный список раздела — только прямые) */
    public $subtree = false;
    /** @var array tax_class_id => множитель цены с налогом; пусто, если цены без налога */
    public $taxRates = [];
    /** @var float курс валюты показа относительно валюты магазина */
    public $currencyRate = 1.0;
    /** @var string код валюты показа */
    public $currency = '';

    /**
     * @var bool only_instock_facets (0.7.6): фасеты, числа и выдача с фильтром — по товарам в наличии (onlyInstock),
     *           а выдача без фильтра — все товары (Kernel::applyStock, Events::listContext)
     */
    public $listAll = false;

    /** @var int[] разделы, товары которых не входят в выдачу (производитель: manufacturer_exclude_categories; Kernel::applyScope) */
    public $excludeCategories = [];

    /**
     * Группа инвалидации кеша.
     *
     * @return string
     */
    public function scope()
    {
        if ($this->type === self::SEARCH) {
            return 'search';
        }
        return ($this->type === self::MANUFACTURER ? 'mfg:' : 'cat:') . (int)$this->id;
    }

    /**
     * Ключ кеша: все, от чего зависит множество товаров и цены.
     *
     * @return string
     */
    public function key()
    {
        return implode('|', [
            $this->scope(), (int)$this->storeId, (int)$this->customerGroupId,
            $this->onlyInstock ? 1 : 0, $this->subtree ? 1 : 0, md5(json_encode($this->taxRates)),
            $this->type === self::SEARCH ? md5($this->languageId . '|' . $this->searchSql) : '',
            $this->excludeCategories ? 'x' . implode(',', $this->excludeCategories) : '',
        ]);
    }

    /**
     * SQL-множитель налога для цены товара (алиас таблицы ps_filter_product — f).
     *
     * @return string
     */
    public function taxSql()
    {
        if (!$this->taxRates) {
            return '1';
        }
        $sql = 'CASE f.tax_class_id';
        foreach ($this->taxRates as $classId => $rate) {
            $sql .= ' WHEN ' . (int)$classId . ' THEN ' . sprintf('%.6F', (float)$rate);
        }
        return $sql . ' ELSE 1 END';
    }
}
