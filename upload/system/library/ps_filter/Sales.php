<?php
namespace PsFilter;

/**
 * Продажи за период — для сортировки «Популярные» (настройка popular_by = sales) и для всех, кому
 * нужно «популярное»: ps_search, тема («Популярные модели» на главной).
 *
 * Таблица `<префикс>ps_filter_sold`: product_id, sold — проданных штук за последние popular_days дней
 * (по умолчанию 90). Только товары с продажами: нет строки — 0. Продажа — строка заказа в статусах из настройки
 * popular_statuses, а если она пуста — config_complete_status и config_processing_status (как в штатных
 * отчетах); по дате заказа, все магазины.
 *
 * Без cron: пересчет ленивый — раз в сутки при первом запросе выдачи (Events::routeBefore), под
 * блокировкой (второй запрос в это время не ждет и пересчет не повторяет), одним INSERT … SELECT SUM
 * в транзакции — читатели видят либо старые, либо новые числа. Сразу — при смене настроек в админке
 * и командой `cli.php sold`. Отметка последнего пересчета — настройка module_ps_filter_sold_at
 * («unix-время|дней|статусы»): смена периода или статусов заказов тоже дает пересчет.
 */
class Sales
{
    const AT_KEY = 'module_ps_filter_sold_at';
    /** Пересчитывать не чаще, с */
    const TTL = 86400;

    /** @var Kernel */
    private $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /** @return bool «Популярные» считаются по продажам */
    public function enabled()
    {
        return (string)$this->k->settings()->get('popular_by', 'sales') === 'sales';
    }

    /** @return int период, дней */
    public function days()
    {
        return max(1, min(3650, (int)$this->k->settings()->get('popular_days', 90)));
    }

    /**
     * Пересчитать, если числа устарели (сутки) или сменились период/статусы. Дешево, когда не нужно:
     * одна строка настроек. Таблица ведется при любой настройке popular_by — ее читают и ps_search, и тема.
     *
     * @return array|null отчет refresh() или null — пересчет не нужен (или идет в другом процессе)
     */
    public function refreshIfDue()
    {
        $at = explode('|', (string)$this->k->settings()->getRaw(self::AT_KEY, ''));
        // Время — unix: витрина, админка и CLI могут жить в разных часовых поясах (дата — у ранних записей 0.6.15).
        $time = isset($at[0]) && $at[0] !== '' ? (ctype_digit($at[0]) ? (int)$at[0] : strtotime($at[0])) : false;
        // Пересчет из cron (отметка cron) — витрина не трогает двое суток: пересчет в запросе посетителя — запасной путь.
        $ttl = isset($at[3]) && $at[3] === 'cron' ? 2 * self::TTL : self::TTL;
        $fresh = $time && time() - $time < $ttl && time() >= $time
            && isset($at[1], $at[2]) && (int)$at[1] === $this->days() && $at[2] === $this->statusKey($this->statuses());
        return $fresh ? null : $this->refresh(false);
    }

    /**
     * Пересчет продаж за период.
     *
     * @param bool $wait ждать блокировку (CLI, админка) или уйти, если пересчет уже идет (витрина)
     * @return array|null ['products' => с продажами, 'units' => штук, 'changed' => товаров с новым числом,
     *                    'days' => период, 'ms' => время] или null — блокировку держит другой процесс
     */
    public function refresh($wait = true, $cron = false)
    {
        $db = $this->k->db();
        $lock = 'psf_sold_' . md5($db->query("SELECT DATABASE() AS d")->row['d'] . '|' . $this->k->prefix());
        if (!(int)$db->query("SELECT GET_LOCK('" . $lock . "', " . ($wait ? 30 : 0) . ") AS l")->row['l']) {
            return null;
        }
        try {
            $started = microtime(true);
            $P = $this->k->prefix();
            $T = '`' . $this->k->table('sold') . '`';
            $days = $this->days();
            $statuses = $this->statuses();

            $before = [];
            foreach ($db->query("SELECT product_id, sold FROM " . $T)->rows as $r) {
                $before[(int)$r['product_id']] = (int)$r['sold'];
            }

            $db->query("START TRANSACTION");
            try {
                $db->query("DELETE FROM " . $T);
                if ($statuses) {
                    $db->query("INSERT INTO " . $T . " (product_id, sold)
                        SELECT op.product_id, SUM(op.quantity) FROM `{$P}order_product` op
                        JOIN `{$P}order` o ON o.order_id = op.order_id
                        WHERE o.order_status_id IN (" . implode(',', $statuses) . ")
                          AND o.date_added >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                        GROUP BY op.product_id HAVING SUM(op.quantity) > 0");
                }
                $db->query("COMMIT");
            } catch (\Exception $e) {
                $db->query("ROLLBACK");
                throw $e;
            }

            $after = [];
            $units = 0;
            foreach ($db->query("SELECT product_id, sold FROM " . $T)->rows as $r) {
                $after[(int)$r['product_id']] = (int)$r['sold'];
                $units += (int)$r['sold'];
            }
            $changed = [];
            foreach ($after + $before as $id => $unused) {
                if ((isset($before[$id]) ? $before[$id] : 0) !== (isset($after[$id]) ? $after[$id] : 0)) {
                    $changed[] = $id;
                }
            }
            // Manticore хранит sold в документе — переписать изменившиеся товары.
            if ($changed) {
                if (count($changed) > 5000) {
                    $this->k->touched(null);
                } else {
                    $this->k->touched($changed);
                }
            }
            $this->k->settings()->setRaw(self::AT_KEY, time() . '|' . $days . '|' . $this->statusKey($statuses) . ($cron ? '|cron' : ''));
            return ['products' => count($after), 'units' => $units, 'changed' => count($changed), 'days' => $days,
                'ms' => (int)round((microtime(true) - $started) * 1000)];
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    /**
     * Статусы заказов, которые считаются продажей: настройка popular_statuses (галочки в админке модуля),
     * пусто — «Выполнен» и «В обработке» из настроек магазина (config_complete_status, config_processing_status —
     * массивы, JSON). Штатный «В обработке» бывает шире продажи: в некоторых магазинах в нем и неоплаченное «Ожидание».
     *
     * @return int[]
     */
    public function statuses()
    {
        $own = array_values(array_unique(array_filter(array_map('intval', (array)$this->k->settings()->get('popular_statuses', [])), function ($id) { return $id > 0; })));
        if ($own) {
            sort($own);
            return $own;
        }
        $db = $this->k->db();
        $ids = [];
        foreach ($db->query("SELECT `value`, serialized FROM `" . $this->k->prefix() . "setting`
                WHERE store_id = 0 AND `code` = 'config' AND `key` IN ('config_complete_status', 'config_processing_status')")->rows as $r) {
            $v = (int)$r['serialized'] ? json_decode($r['value'], true) : $r['value'];
            foreach ((array)$v as $id) {
                if ((int)$id > 0) {
                    $ids[(int)$id] = (int)$id;
                }
            }
        }
        sort($ids);
        return array_values($ids);
    }

    /** @return string */
    private function statusKey(array $statuses)
    {
        return implode(',', $statuses);
    }
}
