<?php
namespace PsFilter\Engine\Manticore;

use PsFilter\Kernel;

/**
 * RT-таблица Manticore — проекция индекса фильтра из MySQL (ps_filter_*): документ на товар.
 *
 * Источник правды остается в MySQL: таблица пересобирается из ps_filter_* целиком (rebuildStep)
 * или по товарам (sync). Поэтому индексатор, админка и cron пишут, как раньше, в MySQL,
 * а сюда изменения доезжают через Manager::touched().
 *
 * Числа: JSON-числа с дробью Manticore хранит как float32 (6972.35 → 6972.350098), а цены
 * с налогом считает неточно. Поэтому цены и значения диапазонов — целые ×10⁴ (как DECIMAL(15,4)
 * в MySQL), множитель налога — целый ×10⁶: вся арифметика целочисленная и сравнения на границах
 * совпадают с MySQL.
 */
class Index
{
    /** Масштаб цен и значений диапазонов (DECIMAL(15,4)). */
    const SCALE = 10000;

    /**
     * Версия формата документов. Меняется, когда меняется смысл или набор значений (2 — даты в UTC, 3 — просмотры для сортировки):
     * таблица со старым форматом считается неготовой до пересборки.
     */
    const FORMAT = 4; // 4 — колонка sold (продажи за период, 0.6.15)

    /** Колонки таблицы (без id) — и они же проверяются при сверке схемы. */
    const COLUMNS = [
        'stores' => 'multi', 'cats' => 'multi', 'cats_direct' => 'multi', 'vals' => 'multi',
        'active' => 'uint', 'date_available' => 'timestamp', 'quantity' => 'bigint', 'stock' => 'uint',
        'manufacturer_id' => 'uint', 'tax_class_id' => 'uint', 'rating' => 'uint', 'sort_order' => 'bigint',
        'name_sort' => 'string attribute', 'model_sort' => 'string attribute', 'date_added' => 'timestamp', 'viewed' => 'uint', 'sold' => 'uint',
        'prices' => 'json', 'ranges' => 'json',
    ];

    /** @var Kernel */
    private $k;
    /** @var Client */
    private $c;
    /** @var string */
    private $table;

    public function __construct(Kernel $kernel, Client $client, $table)
    {
        $this->k = $kernel;
        $this->c = $client;
        $this->table = $table;
    }

    /** @return string */
    public function table()
    {
        return $this->table;
    }

    /** @var array|null настройки фасета «Наличие» (режим и статус «в наличии» — см. \PsFilter\Stock) */
    private $stock;

    /** @return array */
    private function stockSettings()
    {
        if ($this->stock === null) {
            $row = $this->k->db()->query("SELECT settings FROM `" . $this->k->table('facet') . "` WHERE source = 'stock' ORDER BY status DESC, facet_id LIMIT 1")->row;
            $this->stock = $row ? (json_decode($row['settings'], true) ?: []) : [];
        }
        return $this->stock;
    }

    /** Удалить таблицу (удаление модуля). */
    public function drop()
    {
        $this->c->query("DROP TABLE IF EXISTS " . $this->table);
    }

    /**
     * Таблица есть и схема совпадает; иначе создается заново.
     *
     * @return bool таблица (пере)создана — данных в ней нет
     */
    public function ensure()
    {
        $have = [];
        try {
            foreach ($this->c->query("DESCRIBE " . $this->table) as $r) {
                $have[] = $r['Field'];
            }
        } catch (\RuntimeException $e) {
            $have = [];
        }
        $want = array_merge(['id'], array_keys(self::COLUMNS));
        if ($have && !array_diff($want, $have) && !array_diff($have, $want)) {
            return false;
        }
        $cols = [];
        foreach (self::COLUMNS as $name => $type) {
            $cols[] = $name . ' ' . $type;
        }
        $this->c->query("DROP TABLE IF EXISTS " . $this->table);
        $this->c->query("CREATE TABLE " . $this->table . " (" . implode(', ', $cols) . ")");
        return true;
    }

    /**
     * Шаг полной пересборки: товары после курсора пачками, пока не выйдет время.
     *
     * @param int   $cursor последний перенесенный product_id (0 — начать: таблица очищается)
     * @param float $timeLimit
     * @return int|null следующий курсор или null — готово
     */
    public function rebuildStep($cursor, $timeLimit = 4.0)
    {
        $started = microtime(true);
        if ((int)$cursor === 0) {
            if (!$this->ensure()) {
                $this->c->query("TRUNCATE TABLE " . $this->table);
            }
        }
        $cursor = (int)$cursor;
        while (microtime(true) - $started < $timeLimit) {
            $ids = array_map('intval', array_column($this->k->db()->query("SELECT product_id FROM `" . $this->k->table('product') . "`
                WHERE product_id > " . $cursor . " ORDER BY product_id LIMIT 2000")->rows, 'product_id'));
            if (!$ids) {
                return null;
            }
            $this->write($ids);
            $cursor = end($ids);
        }
        return $cursor;
    }

    /**
     * Перенос товаров из MySQL: есть в ps_filter_product — REPLACE, нет — DELETE.
     *
     * @param int[] $ids
     */
    public function sync(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach (array_chunk($ids, 1000) as $chunk) {
            $present = $this->write($chunk);
            $gone = array_diff($chunk, $present);
            if ($gone) {
                $this->c->query("DELETE FROM " . $this->table . " WHERE id IN (" . implode(',', $gone) . ")");
            }
        }
    }

    /** @return int документов в таблице */
    public function count()
    {
        $r = $this->c->query("SELECT COUNT(*) AS c FROM " . $this->table);
        return $r ? (int)$r[0]['c'] : 0;
    }

    /**
     * Документы для товаров из ps_filter_* и REPLACE пачками.
     *
     * @param int[] $ids
     * @return int[] id, которые есть в ps_filter_product
     */
    private function write(array $ids)
    {
        if (!$ids) {
            return [];
        }
        $db = $this->k->db();
        $in = implode(',', $ids);
        $T = function ($n) { return '`' . $this->k->table($n) . '`'; };

        $docs = [];
        foreach ($db->query("SELECT fp.*, LCASE(LEFT(COALESCE(p.model, ''), 64)) AS model_sort, COALESCE(so.sold, 0) AS sold FROM " . $T('product') . " fp
                LEFT JOIN `" . $this->k->prefix() . "product` p ON p.product_id = fp.product_id
                LEFT JOIN " . $T('sold') . " so ON so.product_id = fp.product_id WHERE fp.product_id IN ($in)")->rows as $r) {
            $id = (int)$r['product_id'];
            $stores = [];
            for ($s = 0, $mask = (int)$r['store_mask']; $s < 32; $s++) {
                if ($mask & (1 << $s)) {
                    $stores[] = $s;
                }
            }
            $docs[$id] = [
                'stores' => $stores, 'cats' => [], 'cats_direct' => [], 'vals' => [],
                'active' => (int)$r['active'], 'date_available' => self::day($r['date_available']),
                'quantity' => (int)$r['quantity'], 'stock' => \PsFilter\Stock::code($r['quantity'], $r['stock_status_id'], $this->stockSettings()),
                'manufacturer_id' => (int)$r['manufacturer_id'], 'tax_class_id' => (int)$r['tax_class_id'],
                'rating' => (int)$r['rating'], 'sort_order' => (int)$r['sort_order'],
                'name_sort' => (string)$r['name_sort'], 'model_sort' => (string)$r['model_sort'],
                'date_added' => self::day($r['date_added']), 'viewed' => (int)$r['viewed'], 'sold' => (int)$r['sold'], 'prices' => [], 'ranges' => [],
            ];
        }
        if (!$docs) {
            return [];
        }
        foreach ($db->query("SELECT product_id, category_id, is_direct FROM " . $T('category_product') . " WHERE product_id IN ($in)")->rows as $r) {
            if (isset($docs[(int)$r['product_id']])) {
                $docs[(int)$r['product_id']]['cats'][] = (int)$r['category_id'];
                if ((int)$r['is_direct']) {
                    $docs[(int)$r['product_id']]['cats_direct'][] = (int)$r['category_id'];
                }
            }
        }
        foreach ($db->query("SELECT product_id, value_id FROM " . $T('index') . " WHERE product_id IN ($in)")->rows as $r) {
            if (isset($docs[(int)$r['product_id']])) {
                $docs[(int)$r['product_id']]['vals'][] = (int)$r['value_id'];
            }
        }
        foreach ($db->query("SELECT product_id, facet_id, value FROM " . $T('index_range') . " WHERE product_id IN ($in)")->rows as $r) {
            $pid = (int)$r['product_id'];
            if (!isset($docs[$pid])) {
                continue;
            }
            $f = (int)$r['facet_id'];
            $v = self::scaled($r['value']);
            $docs[$pid]['ranges']['f' . $f][] = $v;
            $docs[$pid]['ranges']['lo' . $f] = isset($docs[$pid]['ranges']['lo' . $f]) ? min($docs[$pid]['ranges']['lo' . $f], $v) : $v;
            $docs[$pid]['ranges']['hi' . $f] = isset($docs[$pid]['ranges']['hi' . $f]) ? max($docs[$pid]['ranges']['hi' . $f], $v) : $v;
        }
        foreach ($db->query("SELECT product_id, customer_group_id, price FROM " . $T('price') . " WHERE product_id IN ($in)")->rows as $r) {
            if (isset($docs[(int)$r['product_id']])) {
                $docs[(int)$r['product_id']]['prices']['g' . (int)$r['customer_group_id']] = self::scaled($r['price']);
            }
        }

        $cols = array_merge(['id'], array_keys(self::COLUMNS));
        $rows = [];
        foreach ($docs as $id => $d) {
            $v = [$id];
            foreach (self::COLUMNS as $name => $type) {
                $x = $d[$name];
                if ($type === 'multi') {
                    $v[] = '(' . implode(',', array_unique($x)) . ')';
                } elseif ($type === 'json') {
                    $v[] = $this->c->quote(json_encode((object)$x));
                } elseif ($type === 'string attribute') {
                    $v[] = $this->c->quote($x);
                } else {
                    $v[] = (int)$x;
                }
            }
            $rows[] = '(' . implode(',', $v) . ')';
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->c->query("REPLACE INTO " . $this->table . " (" . implode(',', $cols) . ") VALUES " . implode(',', $chunk));
        }
        return array_keys($docs);
    }

    /** @return int число ×10⁴ без потерь (из строки DECIMAL) */
    public static function scaled($decimal)
    {
        $s = (string)$decimal;
        $neg = $s !== '' && $s[0] === '-';
        $s = ltrim($s, '-+');
        $parts = explode('.', $s, 2);
        $frac = substr(str_pad(isset($parts[1]) ? $parts[1] : '', 4, '0'), 0, 4);
        $n = (int)$parts[0] * self::SCALE + (int)$frac;
        return $neg ? -$n : $n;
    }

    /**
     * Дата как полночь UTC — не зависит от часового пояса PHP. Админка, витрина и CLI могут жить
     * в разных поясах (OC4 ставит UTC, CLI — пояс сервера): сравнение «доступен с» обязано совпадать.
     *
     * @return int
     */
    public static function day($date)
    {
        $t = strtotime(substr((string)$date, 0, 10) . ' 00:00:00 UTC');
        return $t === false || $t < 0 ? 0 : $t;
    }
}
