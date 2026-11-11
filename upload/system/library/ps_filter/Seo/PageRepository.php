<?php
namespace PsFilter\Seo;

use PsFilter\Kernel;
use PsFilter\State;

/**
 * Посадочные страницы фильтра: «Красные смартфоны» = категория «Смартфоны» + цвет=красный,
 * со своим адресом, заголовками и текстом.
 *
 * Адрес (ps_filter_page_url, по магазину и языку):
 *   url_mode 0 — под путем категории или бренда: /smartfony/krasnye
 *   url_mode 1 — в корне сайта:                   /krasnye-smartfony
 * Ключевые слова НЕ лежат в oc_seo_url: SeoPro превратил бы неизвестный query в мусорный маршрут
 * и увел запрос в 404 мимо событий. Разбор — в обработчике error/not_found.
 *
 * Состояние хранится по id фасетов и значений (они стабильны между переиндексациями),
 * state_hash совпадает с State::hash() — по нему страница находится для любого адреса фильтра.
 * Автостраницы (is_auto = 1) — индексируемые комбинации из SEO-шаблонов, без ключевого слова.
 */
class PageRepository
{
    const SCOPE_CATEGORY = 1;
    const SCOPE_MANUFACTURER = 2;

    /** @var Kernel */
    private $k;
    /** @var array память запроса */
    private $memory = [];

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Страница по ключевому слову из адреса.
     *
     * @return array|null
     */
    public function findByKeyword($storeId, $languageId, $urlMode, $scopeType, $scopeId, $keyword)
    {
        $db = $this->k->db();
        $sql = "SELECT p.* FROM `" . $this->k->table('page_url') . "` u
            JOIN `" . $this->k->table('page') . "` p ON p.page_id = u.page_id
            WHERE u.store_id = " . (int)$storeId . " AND u.language_id = " . (int)$languageId . " AND u.url_mode = " . (int)$urlMode
            . " AND u.keyword = '" . $db->escape(mb_strtolower($keyword, 'UTF-8')) . "' AND p.status = 1";
        if ((int)$urlMode === 0) {
            $sql .= " AND u.scope_type = " . (int)$scopeType . " AND u.scope_id = " . (int)$scopeId;
        }
        $q = $db->query($sql . " LIMIT 1");
        return $q->num_rows ? $this->hydrate($q->row) : null;
    }

    /**
     * Карта посадочных раздела: state_hash => [page_id, keyword, url_mode, is_auto].
     * По ней Builder отдает адрес посадочной вместо адреса фильтра — и в панели, и при канонизации.
     *
     * @return array
     */
    public function scopeMap($storeId, $languageId, $scopeType, $scopeId)
    {
        $key = 'pagemap|' . (int)$storeId . '|' . (int)$languageId . '|' . (int)$scopeType . '|' . (int)$scopeId;
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }
        $map = $this->k->cache()->get($key);
        if (!is_array($map)) {
            $map = [];
            $q = $this->k->db()->query("SELECT p.page_id, p.state_hash, p.is_auto, u.keyword, u.url_mode
                FROM `" . $this->k->table('page') . "` p
                LEFT JOIN `" . $this->k->table('page_url') . "` u ON u.page_id = p.page_id AND u.store_id = " . (int)$storeId . " AND u.language_id = " . (int)$languageId . "
                WHERE p.store_id = " . (int)$storeId . " AND p.scope_type = " . (int)$scopeType . " AND p.scope_id = " . (int)$scopeId . " AND p.status = 1");
            foreach ($q->rows as $r) {
                $map[$r['state_hash']] = [
                    'page_id'  => (int)$r['page_id'],
                    'keyword'  => (string)$r['keyword'],
                    'url_mode' => (int)$r['url_mode'],
                    'is_auto'  => (int)$r['is_auto'],
                ];
            }
            $this->k->cache()->set($key, 'pages', $map, 86400);
        }
        return $this->memory[$key] = $map;
    }

    /**
     * Ссылки на посадочные раздела для панели и над листингом.
     *
     * @param string $flag in_panel | in_category
     * @return array [page_id, name, keyword, url_mode, state, product_count]
     */
    public function links($storeId, $languageId, $scopeType, $scopeId, $flag)
    {
        $flag = $flag === 'in_category' ? 'in_category' : 'in_panel';
        $key = 'pagelinks|' . $flag . '|' . (int)$storeId . '|' . (int)$languageId . '|' . (int)$scopeType . '|' . (int)$scopeId;
        $rows = $this->k->cache()->get($key);
        if (!is_array($rows)) {
            $q = $this->k->db()->query("SELECT p.page_id, p.state, p.product_count, u.keyword, u.url_mode, COALESCE(pd.name, '') AS name
                FROM `" . $this->k->table('page') . "` p
                JOIN `" . $this->k->table('page_url') . "` u ON u.page_id = p.page_id AND u.store_id = " . (int)$storeId . " AND u.language_id = " . (int)$languageId . "
                LEFT JOIN `" . $this->k->table('page_description') . "` pd ON pd.page_id = p.page_id AND pd.language_id = " . (int)$languageId . "
                WHERE p.store_id = " . (int)$storeId . " AND p.scope_type = " . (int)$scopeType . " AND p.scope_id = " . (int)$scopeId
                . " AND p.status = 1 AND p.is_auto = 0 AND p." . $flag . " = 1 AND p.product_count > 0
                ORDER BY p.sort_order, pd.name");
            $rows = $q->rows;
            $this->k->cache()->set($key, 'pages', $rows, 86400);
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'page_id'  => (int)$r['page_id'],
                'name'     => $r['name'],
                'keyword'  => $r['keyword'],
                'url_mode' => (int)$r['url_mode'],
                'state'    => new State((array)json_decode($r['state'], true)),
                'count'    => (int)$r['product_count'],
            ];
        }
        return $out;
    }

    /**
     * Тексты страницы на языке (с откатом на язык по умолчанию).
     *
     * @return array name, h1, meta_title, meta_description, meta_keyword, description, text_position
     */
    public function description($pageId, $languageId)
    {
        $q = $this->k->db()->query("SELECT * FROM `" . $this->k->table('page_description') . "` WHERE page_id = " . (int)$pageId
            . " AND language_id IN (" . (int)$languageId . ", " . (int)$this->k->defaultLanguageId() . ")
            ORDER BY language_id = " . (int)$languageId . " DESC LIMIT 1");
        return $q->num_rows ? $q->row : ['name' => '', 'h1' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'description' => '', 'text_position' => 0];
    }

    /**
     * Перенаправление со старого ключевого слова (после переименования).
     *
     * @return array|null [page_id]
     */
    public function findRedirect($storeId, $languageId, $urlMode, $scopeType, $scopeId, $keyword)
    {
        $q = $this->k->db()->query("SELECT to_page_id FROM `" . $this->k->table('redirect') . "` WHERE store_id = " . (int)$storeId
            . " AND from_hash = '" . self::redirectHash($languageId, $urlMode, $scopeType, $scopeId, $keyword) . "' LIMIT 1");
        return $q->num_rows ? ['page_id' => (int)$q->row['to_page_id']] : null;
    }

    /** @return array|null */
    public function get($pageId)
    {
        $q = $this->k->db()->query("SELECT * FROM `" . $this->k->table('page') . "` WHERE page_id = " . (int)$pageId);
        return $q->num_rows ? $this->hydrate($q->row) : null;
    }

    /**
     * Ключевое слово страницы для магазина и языка.
     *
     * @return array|null [keyword, url_mode]
     */
    public function url($pageId, $storeId, $languageId)
    {
        $q = $this->k->db()->query("SELECT keyword, url_mode FROM `" . $this->k->table('page_url') . "` WHERE page_id = " . (int)$pageId
            . " AND store_id = " . (int)$storeId . " AND language_id = " . (int)$languageId);
        return $q->num_rows ? ['keyword' => $q->row['keyword'], 'url_mode' => (int)$q->row['url_mode']] : null;
    }

    /**
     * Сохранение страницы из админки. Ключевые слова — по языкам (одинаковые для всех магазинов).
     * При смене ключевого слова старое пишется в ps_filter_redirect → 301.
     *
     * @param array $data store_id, scope_type, scope_id, state (array id), url_mode, status, in_panel, in_category,
     *                    in_sitemap, sort_order, product_count, description[lang] = [...], keyword[lang] = '...'
     * @return int page_id
     */
    public function save(array $data, $pageId = 0)
    {
        $db = $this->k->db();
        $state = new State(isset($data['state']) ? (array)$data['state'] : []);
        $storeIds = array_column($db->query("SELECT 0 AS store_id UNION SELECT store_id FROM `" . $this->k->prefix() . "store`")->rows, 'store_id');
        $fields = "store_id = 0, scope_type = " . (int)$data['scope_type'] . ", scope_id = " . (int)$data['scope_id']
            . ", state = '" . $db->escape(json_encode($state->all())) . "', state_hash = '" . $db->escape($state->hash()) . "', facet_count = " . $state->count()
            . ", is_auto = 0, url_mode = " . (int)$data['url_mode'] . ", status = " . (int)!empty($data['status'])
            . ", in_panel = " . (int)!empty($data['in_panel']) . ", in_category = " . (int)!empty($data['in_category'])
            . ", in_sitemap = " . (int)!empty($data['in_sitemap']) . ", layout_id = " . (int)(isset($data['layout_id']) ? $data['layout_id'] : 0)
            . ", sort_order = " . (int)(isset($data['sort_order']) ? $data['sort_order'] : 0)
            . ", product_count = " . (int)(isset($data['product_count']) ? $data['product_count'] : 0) . ", date_modified = NOW()";

        if ($pageId) {
            $old = $db->query("SELECT * FROM `" . $this->k->table('page_url') . "` WHERE page_id = " . (int)$pageId)->rows;
            $db->query("UPDATE `" . $this->k->table('page') . "` SET " . $fields . " WHERE page_id = " . (int)$pageId);
        } else {
            $old = [];
            // Автостраница с тем же состоянием уступает место ручной.
            $db->query("DELETE FROM `" . $this->k->table('page') . "` WHERE is_auto = 1 AND store_id = 0 AND scope_type = " . (int)$data['scope_type']
                . " AND scope_id = " . (int)$data['scope_id'] . " AND state_hash = '" . $db->escape($state->hash()) . "'");
            $db->query("INSERT INTO `" . $this->k->table('page') . "` SET " . $fields);
            $pageId = $db->getLastId();
        }

        $db->query("DELETE FROM `" . $this->k->table('page_description') . "` WHERE page_id = " . (int)$pageId);
        foreach ((array)$data['description'] as $languageId => $d) {
            $db->query("INSERT INTO `" . $this->k->table('page_description') . "` SET page_id = " . (int)$pageId . ", language_id = " . (int)$languageId
                . ", name = '" . $db->escape((string)$d['name']) . "', h1 = '" . $db->escape((string)$d['h1']) . "', meta_title = '" . $db->escape((string)$d['meta_title'])
                . "', meta_description = '" . $db->escape((string)$d['meta_description']) . "', meta_keyword = '" . $db->escape((string)$d['meta_keyword'])
                . "', description = '" . $db->escape((string)$d['description']) . "', text_position = " . (int)(isset($d['text_position']) ? $d['text_position'] : 0));
        }

        $db->query("DELETE FROM `" . $this->k->table('page_url') . "` WHERE page_id = " . (int)$pageId);
        foreach ((array)$data['keyword'] as $languageId => $keyword) {
            $keyword = mb_strtolower(trim((string)$keyword), 'UTF-8');
            if ($keyword === '') {
                continue;
            }
            foreach ($storeIds as $storeId) {
                $db->query("INSERT INTO `" . $this->k->table('page_url') . "` SET page_id = " . (int)$pageId . ", store_id = " . (int)$storeId
                    . ", language_id = " . (int)$languageId . ", url_mode = " . (int)$data['url_mode'] . ", scope_type = " . (int)$data['scope_type']
                    . ", scope_id = " . (int)$data['scope_id'] . ", keyword = '" . $db->escape($keyword) . "'");
            }
        }

        // Старые адреса страницы → 301 на новый (смена ключевого слова, режима или раздела).
        foreach ($old as $o) {
            $hash = self::redirectHash($o['language_id'], $o['url_mode'], $o['scope_type'], $o['scope_id'], $o['keyword']);
            $same = $db->query("SELECT 1 FROM `" . $this->k->table('page_url') . "` WHERE page_id = " . (int)$pageId . " AND store_id = " . (int)$o['store_id']
                . " AND language_id = " . (int)$o['language_id'] . " AND url_mode = " . (int)$o['url_mode'] . " AND scope_type = " . (int)$o['scope_type']
                . " AND scope_id = " . (int)$o['scope_id'] . " AND keyword = '" . $db->escape($o['keyword']) . "'")->num_rows;
            if (!$same) {
                $db->query("REPLACE INTO `" . $this->k->table('redirect') . "` SET store_id = " . (int)$o['store_id'] . ", from_hash = '" . $hash
                    . "', from_path = '" . $db->escape($o['keyword']) . "', to_page_id = " . (int)$pageId . ", to_path = '', date_added = NOW()");
            }
        }
        // Если новое ключевое слово совпало со старым перенаправлением — перенаправление больше не нужно.
        foreach ((array)$data['keyword'] as $languageId => $keyword) {
            $db->query("DELETE FROM `" . $this->k->table('redirect') . "` WHERE from_hash = '"
                . self::redirectHash($languageId, $data['url_mode'], $data['scope_type'], $data['scope_id'], mb_strtolower(trim((string)$keyword), 'UTF-8')) . "'");
        }

        $this->invalidate();
        return (int)$pageId;
    }

    /** @param int[] $pageIds */
    public function delete(array $pageIds)
    {
        $ids = implode(',', array_map('intval', $pageIds));
        if ($ids === '') {
            return;
        }
        foreach (['page_description', 'page_url', 'page'] as $t) {
            $this->k->db()->query("DELETE FROM `" . $this->k->table($t) . "` WHERE page_id IN (" . $ids . ")");
        }
        $this->k->db()->query("DELETE FROM `" . $this->k->table('redirect') . "` WHERE to_page_id IN (" . $ids . ")");
        $this->invalidate();
    }

    /**
     * Занято ли ключевое слово: ЧПУ магазина, префикс фильтра, другая посадочная того же уровня.
     *
     * @return string|null причина
     */
    public function keywordConflict($keyword, $languageId, $urlMode, $scopeType, $scopeId, $pageId)
    {
        $db = $this->k->db();
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        if (!preg_match('/^[a-z0-9а-я\x{0451}][a-z0-9а-я\x{0451}_-]{0,190}$/u', $keyword)) {
            return 'format';
        }
        if (preg_match('/\.(ico|gif|jpe?g|png|js|css|html?)$/', $keyword)) {
            return 'format';
        }
        if ($keyword === strtolower((string)$this->k->settings()->get('url_prefix', 'filter'))) {
            return 'prefix';
        }
        // OC4 ищет сегмент и как окончание составного ключа («desktops/mac» ловится по «mac»).
        $suffix = \PsFilter\Platform::oc4() ? " OR keyword LIKE '" . $db->escape('%/' . $keyword) . "'" : '';
        if ($db->query("SELECT 1 FROM `" . $this->k->prefix() . "seo_url` WHERE keyword = '" . $db->escape($keyword) . "'" . $suffix . " LIMIT 1")->num_rows) {
            return 'seo_url';
        }
        $sql = "SELECT 1 FROM `" . $this->k->table('page_url') . "` WHERE keyword = '" . $db->escape($keyword) . "' AND language_id = " . (int)$languageId
            . " AND url_mode = " . (int)$urlMode . " AND page_id <> " . (int)$pageId;
        if ((int)$urlMode === 0) {
            $sql .= " AND scope_type = " . (int)$scopeType . " AND scope_id = " . (int)$scopeId;
        }
        if ($db->query($sql . " LIMIT 1")->num_rows) {
            return 'page';
        }
        return null;
    }

    /** Сброс кешей посадочных. */
    public function invalidate()
    {
        $this->memory = [];
        $this->k->cache()->deleteScopes(['pages']);
    }

    /** @return string */
    public static function redirectHash($languageId, $urlMode, $scopeType, $scopeId, $keyword)
    {
        $scope = (int)$urlMode === 0 ? (int)$scopeType . ':' . (int)$scopeId : 'root';
        return md5((int)$languageId . '|' . (int)$urlMode . '|' . $scope . '|' . $keyword);
    }

    /** @return array */
    private function hydrate(array $row)
    {
        $row['page_id'] = (int)$row['page_id'];
        $row['scope_type'] = (int)$row['scope_type'];
        $row['scope_id'] = (int)$row['scope_id'];
        $row['url_mode'] = (int)$row['url_mode'];
        $row['state_obj'] = new State((array)json_decode($row['state'], true));
        return $row;
    }
}
