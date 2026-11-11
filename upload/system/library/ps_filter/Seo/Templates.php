<?php
namespace PsFilter\Seo;

use PsFilter\Context;
use PsFilter\Kernel;
use PsFilter\State;

/**
 * SEO-шаблоны: правила, какие комбинации фильтра без ручной посадочной можно индексировать,
 * и мета-теги для них.
 *
 * Шаблон подходит состоянию, если: все выбранные фасеты помечены «индексировать» (seo_index),
 * фасетов не больше max_facets, значений в каждом не больше max_values, товаров не меньше
 * min_products, а раздел и фасет совпадают с заданными (0 — любой). Из подходящих берется
 * самый конкретный: с указанным разделом, затем с указанным фасетом, затем по sort_order.
 *
 * Плейсхолдеры: {category} {brand} {values} {facet:код} {count} {min_price}.
 */
class Templates
{
    /** @var Kernel */
    private $k;
    /** @var array */
    private $memory = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Подходящий шаблон или null.
     *
     * @param Context $ctx
     * @param State   $state
     * @param array   $defs  facet_id => facet
     * @param int     $total товаров в состоянии
     * @param int|null $baseTotal товаров в разделе без фильтра (Result::totalBase); null — не сравнивать
     * @return array|null
     */
    public function match(Context $ctx, State $state, array $defs, $total, $baseTotal = null)
    {
        if ($state->isEmpty()) {
            return null;
        }
        // Выбор, который ничего не сужает (в разделе все товары одного бренда), — полный дубль раздела: не индексируем
        // (seo_skip_unnarrowed, 0.7.7). Посадочные, созданные вручную, сюда не попадают — они решают сами.
        if ($baseTotal !== null && (int)$total >= (int)$baseTotal && (bool)$this->k->settings()->get('seo_skip_unnarrowed', 1)) {
            return null;
        }
        foreach ($state->all() as $fid => $sel) {
            if (empty($defs[$fid]['seo_index'])) {
                return null;
            }
        }
        $scopeType = $ctx->type === Context::MANUFACTURER ? PageRepository::SCOPE_MANUFACTURER : PageRepository::SCOPE_CATEGORY;
        foreach ($this->templates($ctx->storeId, $ctx->languageId) as $t) {
            if ((int)$t['scope_type'] !== $scopeType || ((int)$t['scope_id'] && (int)$t['scope_id'] !== (int)$ctx->id)) {
                continue;
            }
            if ((int)$t['facet_id'] && !$state->has((int)$t['facet_id'])) {
                continue;
            }
            if ($state->count() > (int)$t['max_facets'] || $total < (int)$t['min_products']) {
                continue;
            }
            $tooMany = false;
            foreach ($state->all() as $sel) {
                if (isset($sel['ids']) && count($sel['ids']) > (int)$t['max_values']) {
                    $tooMany = true;
                }
                // Диапазоны (цена «от 100 до 500») не индексируем: их бесконечно много.
                if (!isset($sel['ids'])) {
                    $tooMany = true;
                }
            }
            if ($tooMany) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * Подстановка плейсхолдеров.
     *
     * @param string $text
     * @param array  $vars category, brand, values, facets[code => строка], count, min_price
     * @return string
     */
    public static function render($text, array $vars)
    {
        $text = (string)$text;
        if ($text === '' || strpos($text, '{') === false) {
            return $text;
        }
        // Подставляемые имена — из базы (значения фасетов, разделы) и выводятся темой как есть:
        // экранируем здесь, иначе имя значения со скобками уйдет в <title>, <h1> и meta сырым.
        $text = preg_replace_callback('/\{facet:([a-z0-9-]+)\}/', function ($m) use ($vars) {
            return isset($vars['facets'][$m[1]]) ? self::text($vars['facets'][$m[1]]) : '';
        }, $text);
        $map = [];
        foreach (['category', 'brand', 'values', 'count', 'min_price'] as $k) {
            $map['{' . $k . '}'] = isset($vars[$k]) ? self::text($vars[$k]) : '';
        }
        $text = strtr($text, $map);
        // Пустой плейсхолдер не должен оставлять двойные пробелы и висящие запятые.
        return trim(preg_replace(['/\s{2,}/u', '/\s+([,.:;])/u', '/^[,:;\s]+|[,:;\s]+$/u'], [' ', '$1', ''], $text));
    }

    /**
     * Строка для вывода в HTML без экранирования на стороне темы (title, h1, meta, крошки):
     * сущности сначала снимаются, поэтому уже экранированные строки OpenCart не удваиваются.
     *
     * @param string $s
     * @return string
     */
    public static function text($s)
    {
        return htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Активные шаблоны магазина и языка, от конкретных к общим.
     *
     * @return array
     */
    public function templates($storeId, $languageId)
    {
        $key = 'tpl|' . (int)$storeId . '|' . (int)$languageId;
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }
        $rows = $this->k->cache()->get($key);
        if (!is_array($rows)) {
            $rows = $this->k->db()->query("SELECT * FROM `" . $this->k->table('seo_template') . "`
                WHERE status = 1 AND index_allowed = 1 AND store_id = " . (int)$storeId . " AND language_id = " . (int)$languageId . "
                ORDER BY scope_id > 0 DESC, facet_id > 0 DESC, sort_order, template_id")->rows;
            $this->k->cache()->set($key, 'pages', $rows, 86400);
        }
        return $this->memory[$key] = $rows;
    }

    /** Сброс после правки шаблонов. */
    public function invalidate()
    {
        $this->memory = [];
        $this->k->cache()->deleteScopes(['pages']);
    }
}
