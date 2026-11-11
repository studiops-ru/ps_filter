<?php
namespace PsFilter;

/**
 * Кеш модуля в таблице ps_filter_cache (драйвер db) с памятью запроса поверх.
 *
 * Файловый кеш OpenCart не подходит: его конструктор на каждый запрос перебирает glob'ом
 * все файлы кеша, а у фильтра ключ на каждую комбинацию — тысячи файлов.
 * Инвалидация точечная, по scope: сохранение товара сбрасывает только его категории и бренд,
 * а не весь кеш магазина.
 */
class Cache
{
    /** @var Kernel */
    private $k;
    /** @var string db|none */
    private $driver;
    /** @var array */
    private $memory = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
        $driver = (string)$kernel->settings()->get('cache_driver', 'db');
        $this->driver = $driver === 'none' ? 'none' : 'db';
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function get($key)
    {
        $hash = md5($key);
        if (array_key_exists($hash, $this->memory)) {
            return $this->memory[$hash];
        }
        if ($this->driver === 'none') {
            return null;
        }
        $q = $this->k->db()->query("SELECT `data` FROM `" . $this->k->table('cache') . "` WHERE cache_key = '" . $hash . "' AND expire > " . time());
        if (!$q->num_rows) {
            return null;
        }
        $value = json_decode($q->row['data'], true);
        $this->memory[$hash] = $value;
        return $value;
    }

    /**
     * @param string $key
     * @param string $scope   группа для инвалидации: defs, cat:57, mfg:8, search…
     * @param mixed  $value
     * @param int    $ttl     секунды; ±10 % разброса, чтобы ключи не протухали разом
     */
    public function set($key, $scope, $value, $ttl)
    {
        $hash = md5($key);
        $this->memory[$hash] = $value;
        if ($this->driver === 'none') {
            return;
        }
        $ttl = max(1, (int)$ttl);
        $expire = time() + $ttl + (int)round($ttl * (mt_rand(-10, 10) / 100));
        $db = $this->k->db();
        // Без cron просроченные строки копились бы вечно: примерно каждая сотая запись чистит их сама.
        if (mt_rand(1, 100) === 1) {
            $this->gc();
        }
        $db->query("REPLACE INTO `" . $this->k->table('cache') . "` (cache_key, scope, expire, `data`) VALUES ('" . $hash . "', '"
            . $db->escape(substr($scope, 0, 48)) . "', " . $expire . ", '" . $db->escape(json_encode($value, JSON_UNESCAPED_UNICODE)) . "')");
    }

    /**
     * Сбрасывает группы ключей.
     *
     * @param string[] $scopes
     */
    public function deleteScopes(array $scopes)
    {
        $this->memory = [];
        $scopes = array_values(array_unique(array_filter($scopes)));
        if (!$scopes || $this->driver === 'none') {
            return;
        }
        $db = $this->k->db();
        $list = implode(',', array_map(function ($s) use ($db) {
            return "'" . $db->escape($s) . "'";
        }, $scopes));
        $db->query("DELETE FROM `" . $this->k->table('cache') . "` WHERE scope IN (" . $list . ")");
    }

    /** Полная очистка. */
    public function flush()
    {
        $this->memory = [];
        $this->k->db()->query("TRUNCATE TABLE `" . $this->k->table('cache') . "`");
    }

    /** Удаление просроченных записей (cron). */
    public function gc()
    {
        $this->k->db()->query("DELETE FROM `" . $this->k->table('cache') . "` WHERE expire <= " . time());
    }

    /**
     * Сброс кеша по товарам: их категории (со всеми предками), бренды и все, что зависит от поиска.
     *
     * @param int[] $productIds
     */
    public function invalidateProducts(array $productIds)
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (!$ids) {
            return;
        }
        $in = implode(',', $ids);
        $db = $this->k->db();
        $scopes = ['search', 'catcounts'];
        foreach ($db->query("SELECT DISTINCT category_id FROM `" . $this->k->table('category_product') . "` WHERE product_id IN ($in)")->rows as $r) {
            $scopes[] = 'cat:' . (int)$r['category_id'];
        }
        foreach ($db->query("SELECT DISTINCT manufacturer_id FROM `" . $this->k->table('product') . "` WHERE product_id IN ($in)")->rows as $r) {
            $scopes[] = 'mfg:' . (int)$r['manufacturer_id'];
        }
        $this->deleteScopes($scopes);
    }
}
