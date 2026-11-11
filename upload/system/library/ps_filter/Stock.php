<?php
namespace PsFilter;

/**
 * Фасет «Наличие»: какое значение у товара.
 *
 * Режим quantity (по умолчанию) — по остатку: 1 «В наличии» (quantity > 0), 2 «Нет в наличии».
 * Режим status — как покупатель видит на витрине: при остатке > 0 OpenCart пишет «В наличии»,
 * при нуле — название статуса наличия товара («Под заказ», «Наличие уточняйте», …). Значение 1 —
 * «В наличии»: остаток > 0 или статус, отмеченный в настройках фасета как «в наличии»
 * (instock_status); остальные — 100 + stock_status_id.
 *
 * Одна формула для MySQL (sql), Manticore (code при записи документа) и сверки verify.
 */
class Stock
{
    const IN_STOCK = 1;
    const OUT_OF_STOCK = 2;
    const STATUS_BASE = 100;

    /** @return bool */
    public static function byStatus(array $settings)
    {
        return isset($settings['mode']) && $settings['mode'] === 'status';
    }

    /**
     * Значение для товара (PHP).
     *
     * @param int $quantity
     * @param int $statusId stock_status_id
     * @return int
     */
    public static function code($quantity, $statusId, array $settings)
    {
        if ((int)$quantity > 0) {
            return self::IN_STOCK;
        }
        if (!self::byStatus($settings)) {
            return self::OUT_OF_STOCK;
        }
        $in = isset($settings['instock_status']) ? (int)$settings['instock_status'] : 0;
        return $in && (int)$statusId === $in ? self::IN_STOCK : self::STATUS_BASE + (int)$statusId;
    }

    /**
     * То же выражением SQL по ps_filter_product.
     *
     * @param string $alias
     * @return string
     */
    public static function sql(array $settings, $alias = 'f')
    {
        if (!self::byStatus($settings)) {
            return "IF(" . $alias . ".quantity > 0, " . self::IN_STOCK . ", " . self::OUT_OF_STOCK . ")";
        }
        $in = isset($settings['instock_status']) ? (int)$settings['instock_status'] : 0;
        return "IF(" . $alias . ".quantity > 0" . ($in ? " OR " . $alias . ".stock_status_id = " . $in : '')
            . ", " . self::IN_STOCK . ", " . self::STATUS_BASE . " + " . $alias . ".stock_status_id)";
    }

    /**
     * Значения фасета: value_id => [value_id, slug, name, sort_order, …] (формат FacetRepository).
     *
     * @param array $statuses stock_status_id => название на языке витрины (режим status)
     * @return array
     */
    public static function values(array $settings, array $statuses)
    {
        $make = function ($id, $slug, $name, $sort) {
            return ['value_id' => $id, 'slug' => $slug, 'name' => $name, 'sort_order' => $sort, 'pinned' => 0,
                'color' => '', 'image' => '', 'hidden' => 0, 'numeric' => null];
        };
        $inText = isset($settings['text_instock']) && $settings['text_instock'] !== '' ? $settings['text_instock'] : 'В наличии';
        $out = [self::IN_STOCK => $make(self::IN_STOCK, 'instock', $inText, 0)];
        if (!self::byStatus($settings)) {
            $outText = isset($settings['text_outofstock']) && $settings['text_outofstock'] !== '' ? $settings['text_outofstock'] : 'Нет в наличии';
            $out[self::OUT_OF_STOCK] = $make(self::OUT_OF_STOCK, 'outofstock', $outText, 1);
            return $out;
        }
        $in = isset($settings['instock_status']) ? (int)$settings['instock_status'] : 0;
        $sort = 1;
        foreach ($statuses as $sid => $name) {
            if ((int)$sid === $in) {
                continue;
            }
            $id = self::STATUS_BASE + (int)$sid;
            $out[$id] = $make($id, Index\Normalizer::slug($name, 40), $name, $sort++);
        }
        return $out;
    }
}
