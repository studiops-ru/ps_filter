<?php
namespace PsFilter\Seo;

use PsFilter\Context;
use PsFilter\FacetRepository;
use PsFilter\Kernel;
use PsFilter\State;

/**
 * Пересчет посадочных после переиндексации (стадия pages полной индексации и cron).
 *
 *  - ручные посадочные: актуальное число товаров (пустая посадочная → noindex и вне карты сайта);
 *  - автостраницы: индексируемые комбинации из SEO-шаблонов («Смартфоны Titan», «Куртки красные»)
 *    материализуются в ps_filter_page (is_auto = 1) — для карты сайта и lastmod. Только запись
 *    по cron/индексации: витрина по GET в БД ничего не пишет.
 *
 * Автостраницы — только одиночные значения одного фасета: все комбинации дали бы взрыв
 * числа страниц и тонкий контент. Многофасетные индексируемые состояния обслуживает шаблон
 * на лету, без записи в карту сайта.
 */
class AutoPages
{
    /** @var Kernel */
    private $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Один шаг: разделы с id больше курсора, пока не кончится время.
     *
     * @param string $cursor «тип:id» последнего обработанного раздела ('' — начать)
     * @param string $runStart время начала прогона (Y-m-d H:i:s) — по нему удаляются устаревшие автостраницы
     * @param float  $timeLimit
     * @return string|null новый курсор или null, если все сделано
     */
    public function step($cursor, $runStart, $timeLimit = 4.0)
    {
        $started = microtime(true);
        $db = $this->k->db();
        if ($cursor === '') {
            $this->refreshManual();
        }
        $hasTemplates = (bool)$db->query("SELECT 1 FROM `" . $this->k->table('seo_template') . "` WHERE status = 1 AND index_allowed = 1 LIMIT 1")->num_rows;
        list($type, $id) = $cursor === '' ? [PageRepository::SCOPE_CATEGORY, 0] : array_map('intval', explode(':', $cursor));

        while ($hasTemplates && microtime(true) - $started < $timeLimit) {
            $next = $this->nextScope($type, $id);
            if ($next === null) {
                break;
            }
            list($type, $id) = $next;
            $this->materializeScope($type, $id);
        }
        if ($hasTemplates && microtime(true) - $started >= $timeLimit && $this->nextScope($type, $id) !== null) {
            return $type . ':' . $id;
        }
        // Конец прогона: автостраницы, которые не подтвердились, удаляем.
        $db->query("DELETE p, u, d FROM `" . $this->k->table('page') . "` p
            LEFT JOIN `" . $this->k->table('page_url') . "` u ON u.page_id = p.page_id
            LEFT JOIN `" . $this->k->table('page_description') . "` d ON d.page_id = p.page_id
            WHERE p.is_auto = 1 AND p.date_modified < '" . $db->escape($runStart) . "'");
        $this->k->pages()->invalidate();
        return null;
    }

    /** Полный прогон (CLI, cron). @return int обработано разделов */
    public function runAll()
    {
        $start = date('Y-m-d H:i:s');
        $cursor = '';
        do {
            $cursor = $this->step($cursor, $start, 30.0);
        } while ($cursor !== null);
        return 0;
    }

    /**
     * Прогон с лимитом времени — для AJAX админки (смена slug, объединение значений): на большом каталоге
     * полный прогон не укладывается в запрос. Что не успели, доделает «Пересчитать посадочные» или cron task=pages.
     *
     * @param float $seconds
     * @return bool закончено целиком
     */
    public function runFor($seconds)
    {
        $start = date('Y-m-d H:i:s');
        $until = microtime(true) + (float)$seconds;
        $cursor = '';
        do {
            $cursor = $this->step($cursor, $start, max(1.0, $until - microtime(true)));
        } while ($cursor !== null && microtime(true) < $until);
        return $cursor === null;
    }

    /** Число товаров ручных посадочных. */
    private function refreshManual()
    {
        $db = $this->k->db();
        $engine = $this->k->engine();
        $q = $db->query("SELECT page_id, scope_type, scope_id, state FROM `" . $this->k->table('page') . "` WHERE is_auto = 0");
        foreach ($q->rows as $r) {
            $ctx = $this->context((int)$r['scope_type'], (int)$r['scope_id']);
            $total = (int)$engine->search($ctx, new State((array)json_decode($r['state'], true)))->total;
            $db->query("UPDATE `" . $this->k->table('page') . "` SET product_count = " . $total . " WHERE page_id = " . (int)$r['page_id']);
        }
        $engine->release();
    }

    /**
     * Автостраницы одного раздела.
     */
    private function materializeScope($type, $id)
    {
        $db = $this->k->db();
        $ctx = $this->context($type, $id);
        $defs = $this->k->facets()->snapshot($ctx->languageId)['facets'];
        $base = $this->k->engine()->search($ctx, new State());
        $rows = [];
        foreach ($defs as $fid => $facet) {
            if (empty($facet['seo_index']) || (int)$facet['kind'] !== FacetRepository::KIND_LIST || empty($base->baseCounts[$fid])) {
                continue;
            }
            foreach ($base->baseCounts[$fid] as $vid => $count) {
                $state = new State([$fid => ['ids' => [$vid]]]);
                if (!$this->k->templates()->match($ctx, $state, $defs, $count, (int)$base->totalBase)) {
                    continue;
                }
                $rows[] = "(0, " . (int)$type . ", " . (int)$id . ", '" . $db->escape(json_encode($state->all())) . "', '" . $state->hash() . "', 1, 1, 0, 1, 0, 0, 1, " . (int)$count . ", NOW())";
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            // Ручная страница с тем же состоянием главнее: ее не трогаем.
            $db->query("INSERT INTO `" . $this->k->table('page') . "` (store_id, scope_type, scope_id, state, state_hash, facet_count, is_auto, url_mode, status, in_panel, in_category, in_sitemap, product_count, date_modified)
                VALUES " . implode(',', $chunk) . "
                ON DUPLICATE KEY UPDATE product_count = IF(is_auto = 1, VALUES(product_count), product_count), date_modified = IF(is_auto = 1, VALUES(date_modified), date_modified)");
        }
        $this->k->engine()->release();
    }

    /**
     * Следующий раздел: сначала категории с товарами, затем бренды.
     *
     * @return array|null [type, id]
     */
    private function nextScope($type, $id)
    {
        $db = $this->k->db();
        if ($type === PageRepository::SCOPE_CATEGORY) {
            $q = $db->query("SELECT MIN(category_id) AS id FROM `" . $this->k->table('category_product') . "` WHERE category_id > " . (int)$id);
            if ($q->row['id'] !== null) {
                return [PageRepository::SCOPE_CATEGORY, (int)$q->row['id']];
            }
            $id = 0;
        }
        $q = $db->query("SELECT MIN(manufacturer_id) AS id FROM `" . $this->k->table('product') . "` WHERE manufacturer_id > " . (int)$id);
        return $q->row['id'] !== null ? [PageRepository::SCOPE_MANUFACTURER, (int)$q->row['id']] : null;
    }

    /** @return Context */
    private function context($type, $id)
    {
        $ctx = new Context();
        $ctx->type = (int)$type === PageRepository::SCOPE_MANUFACTURER ? Context::MANUFACTURER : Context::CATEGORY;
        $ctx->id = (int)$id;
        $ctx->languageId = $this->k->defaultLanguageId();
        $ctx->subtree = (bool)$this->k->settings()->get('scope_subtree');
        $this->k->applyStock($ctx);
        return $ctx;
    }
}
