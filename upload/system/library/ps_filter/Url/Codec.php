<?php
namespace PsFilter\Url;

use PsFilter\FacetRepository;
use PsFilter\State;

/**
 * Строковый вид состояния фильтра.
 *
 * Токен фасета: «код=значения». Списки — slug через запятую (brand=titan,ural),
 * диапазоны — «мин-макс» (price=100-500, открытый край пуст: price=100-, отрицательное — m5).
 * В пути токены идут сегментами (/filter/brand=titan/price=100-500/), в query — через «;».
 * Знак «=» в сегменте гарантирует, что токен не совпадет с ЧПУ-ключом категории или товара.
 *
 * Канонический порядок: фасеты — как в панели (sort_order, facet_id), значения — как в списке.
 * Одинаковый выбор всегда дает одинаковую строку: без дублей страниц и с попаданием в кеш.
 */
class Codec
{
    /** Пределы адреса: фасетов в состоянии и значений в одном фасете (лишнее — dirty → 301). */
    const MAX_FACETS = 20;
    const MAX_VALUES = 50;

    /** @var array facet_id => facet (FacetRepository::snapshot()['facets']) */
    private $defs;
    /** @var array code => facet_id */
    private $codes;

    /**
     * @param array $snapshot FacetRepository::snapshot()
     */
    public function __construct(array $snapshot)
    {
        $this->defs = $snapshot['facets'];
        $this->codes = $snapshot['codes'];
    }

    /**
     * @param State $state
     * @param array $override facet_id => готовая строка значения (шаблоны для JS)
     * @return string[] токены в каноническом порядке
     */
    public function tokens(State $state, array $override = [])
    {
        $tokens = [];
        foreach ($this->defs as $fid => $facet) {
            if (isset($override[$fid])) {
                $tokens[] = $facet['code'] . '=' . $override[$fid];
                continue;
            }
            $sel = $state->get($fid);
            if ($sel === null) {
                continue;
            }
            if (isset($sel['ids'])) {
                $slugs = [];
                foreach ($facet['values'] as $vid => $value) {
                    if (in_array((int)$vid, $sel['ids'], true)) {
                        $slugs[] = $value['slug'];
                    }
                }
                if ($slugs) {
                    $tokens[] = $facet['code'] . '=' . implode(',', $slugs);
                }
            } else {
                $tokens[] = $facet['code'] . '=' . self::number($sel['min']) . '-' . self::number($sel['max']);
            }
        }
        return $tokens;
    }

    /**
     * Разбирает токены. Неизвестные фасеты и значения отбрасываются, флаг $dirty говорит,
     * что строка была не канонической (нужен 301 на канонический адрес).
     *
     * @param string[] $tokens
     * @param bool     $dirty
     * @return State
     */
    public function decode(array $tokens, &$dirty = false)
    {
        $sel = [];
        $dirty = false;
        foreach ($tokens as $token) {
            $token = rawurldecode(trim($token));
            if ($token === '') {
                continue;
            }
            // Пределы на адрес: больше MAX_FACETS фасетов или MAX_VALUES значений в одном — мусор (бот, перебор),
            // лишнее отбрасывается с 301 на канонический адрес; строки кеша и временные таблицы на такое не тратим.
            if (count($sel) >= self::MAX_FACETS) {
                $dirty = true;
                break;
            }
            $pos = strpos($token, '=');
            if ($pos === false) {
                $dirty = true;
                continue;
            }
            $code = strtolower(substr($token, 0, $pos));
            $raw = substr($token, $pos + 1);
            if (!isset($this->codes[$code]) || isset($sel[$this->codes[$code]])) {
                $dirty = true;
                continue;
            }
            $fid = $this->codes[$code];
            $facet = $this->defs[$fid];

            if ((int)$facet['kind'] === FacetRepository::KIND_RANGE) {
                // До 12 целых и 4 дробных знаков: иначе (float) дает INF, а sprintf — «INF» в SQL.
                if (!preg_match('/^(m?\d{1,12}(?:\.\d{1,4})?)?-(m?\d{1,12}(?:\.\d{1,4})?)?$/', $raw, $m) || (empty($m[1]) && empty($m[2]))) {
                    $dirty = true;
                    continue;
                }
                $sel[$fid] = [
                    'min' => isset($m[1]) && $m[1] !== '' ? self::parseNumber($m[1]) : null,
                    'max' => isset($m[2]) && $m[2] !== '' ? self::parseNumber($m[2]) : null,
                ];
                continue;
            }

            $ids = [];
            foreach (explode(',', strtolower($raw)) as $slug) {
                if (count($ids) >= self::MAX_VALUES) {
                    $dirty = true;
                    break;
                }
                if (isset($facet['slugs'][$slug])) {
                    $ids[] = (int)$facet['slugs'][$slug];
                } else {
                    $dirty = true;
                }
            }
            if ($ids) {
                $sel[$fid] = ['ids' => array_values(array_unique($ids))];
                if (count($sel[$fid]['ids']) !== count($ids)) {
                    $dirty = true; // повтор значения в адресе
                }
            }
        }
        $state = new State($sel);
        return $state;
    }

    /**
     * Разбор query-формы: «brand=titan,ural;price=100-500».
     *
     * @param string $value
     * @param bool   $dirty
     * @return State
     */
    public function decodeQuery($value, &$dirty = false)
    {
        return $this->decode(explode(';', (string)$value), $dirty);
    }

    /**
     * @param State $state
     * @param array $override
     * @return string
     */
    public function encodeQuery(State $state, array $override = [])
    {
        return implode(';', $this->tokens($state, $override));
    }

    /**
     * Число для URL: без лишних нулей, минус как «m» (дефис занят разделителем диапазона).
     *
     * @param float|null $v
     * @return string
     */
    public static function number($v)
    {
        if ($v === null) {
            return '';
        }
        $s = rtrim(rtrim(sprintf('%.4F', abs((float)$v)), '0'), '.');
        return ((float)$v < 0 ? 'm' : '') . ($s === '' ? '0' : $s);
    }

    /** @return float */
    private static function parseNumber($s)
    {
        return $s[0] === 'm' ? -(float)substr($s, 1) : (float)$s;
    }
}
