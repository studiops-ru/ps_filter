<?php
namespace PsFilter;

/**
 * Состояние фильтра: что выбрано. Неизменяемое: каждая операция возвращает новый объект,
 * поэтому из одного состояния безопасно строить сотни ссылок «переключить значение».
 *
 * Внутри — по id: facet_id => ['ids' => [value_id, …]] для списков,
 * facet_id => ['min' => float|null, 'max' => float|null] для диапазонов.
 * Строковый вид (коды и slug) — забота Url\Codec.
 */
class State
{
    /** @var array */
    private $sel = [];

    /**
     * @param array $selections
     */
    public function __construct(array $selections = [])
    {
        foreach ($selections as $facetId => $s) {
            $s = self::normalize($s);
            if ($s !== null) {
                $this->sel[(int)$facetId] = $s;
            }
        }
        ksort($this->sel);
    }

    /** @return bool */
    public function isEmpty()
    {
        return !$this->sel;
    }

    /** @return array facet_id => selection */
    public function all()
    {
        return $this->sel;
    }

    /** @return bool */
    public function has($facetId)
    {
        return isset($this->sel[(int)$facetId]);
    }

    /** @return array|null */
    public function get($facetId)
    {
        return isset($this->sel[(int)$facetId]) ? $this->sel[(int)$facetId] : null;
    }

    /** @return bool */
    public function hasValue($facetId, $valueId)
    {
        $s = $this->get($facetId);
        return $s !== null && isset($s['ids']) && in_array((int)$valueId, $s['ids'], true);
    }

    /** @return int число выбранных фасетов */
    public function count()
    {
        return count($this->sel);
    }

    /**
     * @return self
     */
    public function with($facetId, array $selection)
    {
        $sel = $this->sel;
        $sel[(int)$facetId] = $selection;
        return new self($sel);
    }

    /** @return self */
    public function without($facetId)
    {
        $sel = $this->sel;
        unset($sel[(int)$facetId]);
        return new self($sel);
    }

    /**
     * Переключает значение в списке; $single — для radio/select: значение заменяет выбранное.
     *
     * @return self
     */
    public function toggle($facetId, $valueId, $single = false)
    {
        $facetId = (int)$facetId;
        $valueId = (int)$valueId;
        $ids = isset($this->sel[$facetId]['ids']) ? $this->sel[$facetId]['ids'] : [];
        if (in_array($valueId, $ids, true)) {
            $ids = array_values(array_diff($ids, [$valueId]));
        } else {
            $ids = $single ? [$valueId] : array_merge($ids, [$valueId]);
        }
        return $ids ? $this->with($facetId, ['ids' => $ids]) : $this->without($facetId);
    }

    /**
     * Канонический хеш: одинаковый для одинакового выбора в любом порядке.
     *
     * @return string
     */
    public function hash()
    {
        return $this->sel ? md5(json_encode($this->sel)) : '';
    }

    /**
     * @param mixed $s
     * @return array|null
     */
    private static function normalize($s)
    {
        if (!is_array($s)) {
            return null;
        }
        if (isset($s['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$s['ids']))));
            sort($ids);
            return $ids ? ['ids' => $ids] : null;
        }
        if (array_key_exists('min', $s) || array_key_exists('max', $s)) {
            $min = isset($s['min']) && $s['min'] !== '' ? (float)$s['min'] : null;
            $max = isset($s['max']) && $s['max'] !== '' ? (float)$s['max'] : null;
            if ($min === null && $max === null) {
                return null;
            }
            if ($min !== null && $max !== null && $min > $max) {
                list($min, $max) = [$max, $min];
            }
            return ['min' => $min, 'max' => $max];
        }
        return null;
    }
}
