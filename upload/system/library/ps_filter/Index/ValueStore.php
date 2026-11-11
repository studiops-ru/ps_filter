<?php
namespace PsFilter\Index;

use PsFilter\Kernel;

/**
 * Значения фасетов: source_key → value_id.
 *
 * value_id — суррогатный и стабильный: значение находится по (facet_id, source_key),
 * поэтому переиндексация не меняет id и slug, а значит не ломает URL и посадочные.
 * Карты держатся в памяти на время одного HTTP-шага индексации.
 */
class ValueStore
{
    /** @var Kernel */
    private $k;
    /** @var array facet_id => [source_key => value_id] */
    private $map = [];
    /** @var array facet_id => [slug => true] */
    private $slugs = [];
    /** @var array facet_id => [value_id => alias_of] */
    private $alias = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Подгружает карты значений фасетов (один раз на фасет за шаг).
     *
     * @param int[] $facetIds
     */
    public function load(array $facetIds)
    {
        $need = [];
        foreach ($facetIds as $id) {
            if (!isset($this->map[(int)$id])) {
                $need[] = (int)$id;
                $this->map[(int)$id] = [];
                $this->slugs[(int)$id] = [];
                $this->alias[(int)$id] = [];
            }
        }
        if (!$need) {
            return;
        }
        $q = $this->k->db()->query("SELECT value_id, facet_id, source_key, slug, alias_of FROM `" . $this->k->table('value') . "` WHERE facet_id IN (" . implode(',', $need) . ")");
        foreach ($q->rows as $row) {
            $f = (int)$row['facet_id'];
            $this->map[$f][$row['source_key']] = (int)$row['value_id'];
            $this->slugs[$f][$row['slug']] = true;
            if ((int)$row['alias_of']) {
                $this->alias[$f][(int)$row['value_id']] = (int)$row['alias_of'];
            }
        }
    }

    /**
     * id значения для записи в индекс (с учетом объединения синонимов alias_of).
     * Неизвестный ключ создается.
     *
     * @param int    $facetId
     * @param string $sourceKey
     * @param array  $names      language_id => название
     * @param array  $extra      sort_order, image, color, numeric_value
     * @return int
     */
    public function resolve($facetId, $sourceKey, array $names, array $extra = [])
    {
        $facetId = (int)$facetId;
        $sourceKey = (string)$sourceKey;
        if (!isset($this->map[$facetId])) {
            $this->load([$facetId]);
        }
        if (isset($this->map[$facetId][$sourceKey])) {
            $id = $this->map[$facetId][$sourceKey];
        } else {
            $id = $this->create($facetId, $sourceKey, $names, $extra);
        }
        return isset($this->alias[$facetId][$id]) ? $this->alias[$facetId][$id] : $id;
    }

    /**
     * Значение своего фасета (source = custom) по названию: находит или создает,
     * название пишется на всех языках магазина.
     *
     * @param int    $facetId
     * @param string $name
     * @return int value_id (основное, если значение — синоним)
     */
    public function custom($facetId, $name)
    {
        $name = trim((string)$name);
        $names = [];
        foreach ($this->k->db()->query("SELECT language_id FROM `" . $this->k->prefix() . "language`")->rows as $l) {
            $names[(int)$l['language_id']] = $name;
        }
        return $this->resolve($facetId, Normalizer::key($name), $names);
    }

    /**
     * @return int
     */
    private function create($facetId, $sourceKey, array $names, array $extra)
    {
        $db = $this->k->db();
        $base = Normalizer::slug($names ? reset($names) : $sourceKey);
        $slug = $base;
        for ($i = 2; isset($this->slugs[$facetId][$slug]); $i++) {
            $slug = substr($base, 0, 95) . '-' . $i;
        }

        $numeric = isset($extra['numeric_value']) && $extra['numeric_value'] !== null ? (float)$extra['numeric_value'] : null;
        $V = '`' . $this->k->table('value') . '`';
        $id = 0;
        for ($try = 0; $try < 8 && !$id; $try++) {
            // INSERT IGNORE, а не ловля ошибки: драйвер OpenCart (mysqli_report ERROR) писал бы в журнал предупреждение
            // о дубле ключа, хотя случай штатный (0.7.3, ночной reconcile на одном из магазинов).
            $db->query("INSERT IGNORE INTO " . $V . " SET facet_id = " . $facetId
                . ", source_key = '" . $db->escape($sourceKey) . "'"
                . ", slug = '" . $db->escape($slug) . "'"
                . ", numeric_value = " . ($numeric === null ? 'NULL' : $numeric)
                . ", image = '" . $db->escape(isset($extra['image']) ? $extra['image'] : '') . "'"
                . ", color = '" . $db->escape(isset($extra['color']) ? $extra['color'] : '') . "'"
                . ", sort_order = " . (int)(isset($extra['sort_order']) ? $extra['sort_order'] : 0)
                . ", products = 1, date_seen = NOW()"); // значение создается для товара, у которого оно есть; точный счетчик — recountValues
            if ($db->countAffected() > 0) {
                $id = (int)$db->getLastId();
                break;
            }
            // Строка не вставлена — занят ключ или slug. Ключ уже есть по правилам сравнения MySQL (general_ci:
            // слова, различающиеся только точками над «е», и «é» = «e»), хотя в карте PHP его не было, — это то же значение, берем его.
            // Иначе занят slug (значение создали одновременно из админки и cron) — следующий суффикс.
            $q = $db->query("SELECT value_id, slug FROM " . $V . " WHERE facet_id = " . $facetId . " AND source_key = '" . $db->escape($sourceKey) . "' LIMIT 1");
            if ($q->num_rows) {
                $existing = (int)$q->row['value_id'];
                $this->map[$facetId][$sourceKey] = $existing;
                $this->slugs[$facetId][$q->row['slug']] = true;
                return isset($this->alias[$facetId][$existing]) ? $this->alias[$facetId][$existing] : $existing;
            }
            $this->slugs[$facetId][$slug] = true;
            for ($i = 2; isset($this->slugs[$facetId][$slug]); $i++) {
                $slug = substr($base, 0, 95) . '-' . $i;
            }
        }
        if (!$id) {
            throw new \RuntimeException('ps_filter: не удалось создать значение «' . $sourceKey . '» фасета ' . $facetId);
        }

        $rows = [];
        foreach ($names as $languageId => $name) {
            $rows[] = "(" . $id . ", " . (int)$languageId . ", '" . $db->escape(mb_substr($name, 0, 255, 'UTF-8')) . "')";
        }
        if ($rows) {
            $db->query("INSERT IGNORE INTO `" . $this->k->table('value_description') . "` (value_id, language_id, name) VALUES " . implode(',', $rows));
        }

        $this->map[$facetId][$sourceKey] = $id;
        $this->slugs[$facetId][$slug] = true;
        return $id;
    }
}
