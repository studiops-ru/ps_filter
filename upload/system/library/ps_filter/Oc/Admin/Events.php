<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * Обработчики событий админки.
 *
 * Инкрементальная индексация: сохранение товара сразу меняет фильтр на витрине, без cron.
 * Индексируется только затронутый товар (~15 запросов), кеш сбрасывается точечно: категории
 * товара (до и после правки — товар мог переехать) и его бренд.
 * Обработчики ничего не возвращают: в OC3 непустой ответ подменил бы результат, в OC4 он игнорируется.
 */
class Events extends Base
{
    /**
     * admin/view/common/column_left/before — пункт «PS Filter» в меню.
     * Через $data['menus'], без строковых вставок в шаблон: переживет любую тему админки.
     */
    public function menu(&$route, &$data)
    {
        if (!$this->can('access', 'base') || !isset($data['menus']) || !isset($this->session->data['user_token'])) {
            return null;
        }
        // Файлы модуля обновили, а схему — нет: догоняем при первом заходе в админку
        // (версия схемы уже в $config, на обычных страницах это ничего не стоит).
        if ((int)$this->config->get('module_ps_filter_db_version') < \PsFilter\Schema::DB_VERSION) {
            try {
                $this->kernel()->schema()->upgrade();
            } catch (\Exception $e) {
                $this->log->write('PS Filter: обновление схемы не удалось: ' . $e->getMessage());
            }
        }
        // Своя версия в настройках (ps_filter_version) — для вкладки «Поддержка» других модулей студии.
        // Файлы обновили без смены схемы — догоняем здесь же.
        if ((string)$this->config->get('ps_filter_version') !== \PsFilter\Kernel::VERSION) {
            $this->kernel()->settings()->setRaw('ps_filter_version', \PsFilter\Kernel::VERSION);
        }
        // OC4: где стоит панель — чтобы новая установка после удаления вернула ее на места (Schema::restoreLayouts).
        $this->kernel()->schema()->rememberLayouts();
        // Модуль выключен — пункта нет (стандарт модулей студии); включают в «Расширения → Модули».
        if (!$this->enabled()) {
            return null;
        }
        $this->lang();

        $children = [];
        foreach (['menu_settings' => 'base', 'menu_facets' => 'facet', 'menu_pages' => 'page', 'menu_templates' => 'template', 'menu_index' => 'index'] as $text => $name) {
            if ($this->can('access', $name)) {
                $children[] = ['name' => $this->language->get($text), 'href' => $this->adminLink($name), 'children' => []];
            }
        }

        // «PS Filter» — внутри «Каталога», подпункты третьим уровнем (своего верхнего пункта у модулей студии нет).
        $item = ['name' => $this->language->get('menu_title'), 'href' => '', 'children' => $children];
        foreach ($data['menus'] as &$menu) {
            if (isset($menu['id']) && $menu['id'] === 'menu-catalog') {
                $menu['children'][] = $item;
                break;
            }
        }
        unset($menu);
        return null;
    }

    /**
     * admin/view/catalog/product_form/after — вкладка «Фильтр» со своими фасетами (source = custom).
     * Вставляется в готовый HTML рядом со штатной вкладкой «Связи»; если тема админки разметку
     * изменила — вкладки просто нет. $output меняется по ссылке.
     */
    public function productTab(&$route, &$data, &$output)
    {
        if (!$this->enabled() || strpos($output, 'id="tab-links"') === false) {
            return null;
        }
        $kernel = $this->kernel();
        $lang = (int)$this->config->get('config_language_id');
        $facets = $this->db->query("SELECT f.facet_id, COALESCE(fd.name, f.code) AS name FROM `" . $kernel->table('facet') . "` f
            LEFT JOIN `" . $kernel->table('facet_description') . "` fd ON fd.facet_id = f.facet_id AND fd.language_id = " . $lang . "
            WHERE f.source = 'custom' ORDER BY f.sort_order, f.facet_id")->rows;

        $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        $checked = [];
        if ($productId && $facets) {
            foreach ($this->db->query("SELECT value_id FROM `" . $kernel->table('index') . "` WHERE product_id = " . $productId . " AND facet_id IN (" . implode(',', array_map('intval', array_column($facets, 'facet_id'))) . ")")->rows as $r) {
                $checked[(int)$r['value_id']] = true;
            }
        }
        foreach ($facets as &$f) {
            $f['values'] = [];
            foreach ($this->db->query("SELECT v.value_id, COALESCE(vd.name, v.slug) AS name FROM `" . $kernel->table('value') . "` v
                    LEFT JOIN `" . $kernel->table('value_description') . "` vd ON vd.value_id = v.value_id AND vd.language_id = " . $lang . "
                    WHERE v.facet_id = " . (int)$f['facet_id'] . " AND v.alias_of = 0 ORDER BY v.pinned DESC, v.sort_order, name")->rows as $v) {
                $f['values'][] = ['value_id' => (int)$v['value_id'], 'name' => $v['name'], 'checked' => isset($checked[(int)$v['value_id']])];
            }
        }
        unset($f);

        // Строки читаем из файла, а не через общий $language: форма товара уже отрисована его ключами (entry_name и т.п.).
        $t = $this->strings();
        $tab = $this->view('product_tab', [
            'facets'                => $facets,
            'help_product_filter'   => $t['help_product_filter'],
            'text_no_custom_facets' => $t['text_no_custom_facets'],
            'entry_new_values'      => $t['entry_new_values'],
        ]);
        $title = htmlspecialchars($t['tab_product_filter'], ENT_QUOTES, 'UTF-8');
        $li = Platform::oc4()
            ? '<li class="nav-item"><a href="#tab-ps-filter" data-bs-toggle="tab" class="nav-link">' . $title . '</a></li>'
            : '<li><a href="#tab-ps-filter" data-toggle="tab">' . $title . '</a></li>';

        // Пункт — в конец первого списка вкладок, панель — перед «Связями».
        $quote = function ($s) { return str_replace(['\\', '$'], ['\\\\', '\\$'], $s); };
        $output = preg_replace('~(<ul class="nav nav-tabs">.*?)(</ul>)~s', '$1' . $quote($li) . '$2', $output, 1);
        $output = preg_replace('~<div (?:class="tab-pane"\s+id="tab-links"|id="tab-links"\s+class="tab-pane")>~', $quote($tab) . '$0', $output, 1);
        return null;
    }

    /** admin/model/catalog/product/addProduct/after (и copyProduct в OC4) — id нового товара в $output. */
    public function productAdd(&$route, &$args, &$output)
    {
        $this->saveCustom((int)$output);
        $this->reindex([(int)$output]);
        return null;
    }

    /** admin/model/catalog/product/{editProduct,editProductStatus}/after — id в $args[0]. */
    public function productEdit(&$route, &$args, &$output)
    {
        $id = isset($args[0]) ? (int)$args[0] : 0;
        if (strpos($route, 'editProductStatus') === false) {
            $this->saveCustom($id);
        }
        $this->reindex([$id]);
        return null;
    }

    /** OC4: admin/model/catalog/product.editVariants/after — варианты главного товара $args[0] переписаны. */
    public function variantsEdit(&$route, &$args, &$output)
    {
        $masterId = isset($args[0]) ? (int)$args[0] : 0;
        if ($masterId) {
            $this->reindex(array_column($this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE master_id = " . $masterId)->rows, 'product_id'));
        }
        return null;
    }

    /**
     * admin/ps_batch/changed/after — модуль «Массовая смена цен» (ps_batch) записал пачку цен, акций, скидок или цен
     * опций прямым SQL (или отменил ее): товары пачки (до 500) переиндексируются сразу, не дожидаясь reconcile.
     * $data = ['product_ids' => int[], 'kind' => price|special|discount|option, 'batch' => int].
     */
    public function batchChanged(&$route, &$data)
    {
        if (isset($data['kind']) && $data['kind'] === 'option') {
            return null; // цены опций индекс не хранит
        }
        $this->reindex(isset($data['product_ids']) && is_array($data['product_ids']) ? $data['product_ids'] : []);
        return null;
    }

    /** admin/model/catalog/product/deleteProduct/after. */
    public function productDelete(&$route, &$args, &$output)
    {
        $id = isset($args[0]) ? (int)$args[0] : 0;
        if ($id && $this->enabled()) {
            $kernel = $this->kernel();
            $kernel->cache()->invalidateProducts([$id]);
            $kernel->indexer()->deleteProducts([$id]);
        }
        return null;
    }

    /**
     * admin/model/catalog/category/{editCategory,deleteCategory,repairCategories}/after.
     * Изменилось дерево — товары поддерева переиндексируются (у них меняются предки).
     */
    public function categoryChanged(&$route, &$args, &$output)
    {
        if (!$this->enabled()) {
            return null;
        }
        $kernel = $this->kernel();
        if (strpos($route, 'repairCategories') !== false || empty($args[0])) {
            // Пересборка путей всего дерева: проще пересчитать привязки всех товаров одним job.
            $kernel->job()->start();
            return null;
        }
        $categoryId = (int)$args[0];
        $ids = array_column($this->db->query("SELECT DISTINCT p2c.product_id FROM `" . DB_PREFIX . "product_to_category` p2c
            JOIN `" . DB_PREFIX . "category_path` cp ON cp.category_id = p2c.category_id WHERE cp.path_id = " . $categoryId . "
            UNION SELECT product_id FROM `" . $kernel->table('category_product') . "` WHERE category_id = " . $categoryId)->rows, 'product_id');
        // От дерева категорий в индексе зависят только привязки (с предками) — их и пересчитываем одним SQL,
        // а не переиндексируем все поддерево (сохранение корневой категории большого каталога шло минутами).
        $kernel->indexer()->rebindCategories($ids);
        $kernel->facets()->invalidate(); // переопределения фасетов по ветке наследуются по дереву
        return null;
    }

    /**
     * Атрибуты, опции, производители, группы фильтров: изменились названия или значения.
     * Удаление сущности → фасет выключается (иначе на витрине осталась бы «пустая» группа).
     */
    public function referenceChanged(&$route, &$args, &$output)
    {
        if (!$this->enabled() || empty($args[0])) {
            return null;
        }
        $kernel = $this->kernel();
        // OC3: catalog/filter/* — это группа фильтров; OC4: группа — catalog/filter_group.*, catalog/filter.* — одно значение.
        $map = [
            'catalog/attribute' => 'attribute', 'catalog/option' => 'option', 'catalog/manufacturer' => 'manufacturer',
            Platform::oc4() ? 'catalog/filter_group' : 'catalog/filter/' => 'oc_filter',
        ];
        $source = null;
        foreach ($map as $needle => $src) {
            if (strpos($route, $needle) !== false) {
                $source = $src;
            }
        }
        $id = (int)$args[0];
        if ($source && stripos($route, 'delete') !== false && $source !== 'manufacturer') {
            $this->db->query("UPDATE `" . $kernel->table('facet') . "` SET status = 0 WHERE source = '" . $source . "' AND ref_id = " . $id);
        } elseif ($source) {
            $this->syncNames($kernel, $source, $id);
        }
        $kernel->facets()->invalidate();
        return null;
    }

    /**
     * Переименовали производителя, значения опции или фильтры группы в OpenCart — имена значений фасетов
     * следуют за источником (переиндексация имена не трогает: ValueStore::resolve находит значение по ключу).
     */
    private function syncNames($kernel, $source, $id)
    {
        $P = DB_PREFIX;
        $join = "`" . $kernel->table('value_description') . "` vd JOIN `" . $kernel->table('value') . "` v ON v.value_id = vd.value_id JOIN `" . $kernel->table('facet') . "` f ON f.facet_id = v.facet_id";
        if ($source === 'manufacturer') {
            $rows = $this->db->query("SELECT vd.value_id, vd.language_id, m.name FROM " . $join . " JOIN `{$P}manufacturer` m ON m.manufacturer_id = " . $id . "
                WHERE f.source = 'manufacturer' AND v.source_key = '" . $id . "'")->rows;
        } elseif ($source === 'option') {
            $rows = $this->db->query("SELECT vd.value_id, vd.language_id, d.name FROM " . $join . " JOIN `{$P}option_value_description` d ON d.option_value_id = v.source_key AND d.language_id = vd.language_id
                WHERE f.source = 'option' AND f.ref_id = " . $id)->rows;
        } elseif ($source === 'oc_filter') {
            $rows = $this->db->query("SELECT vd.value_id, vd.language_id, d.name FROM " . $join . " JOIN `{$P}filter_description` d ON d.filter_id = v.source_key AND d.language_id = vd.language_id
                WHERE f.source = 'oc_filter' AND f.ref_id = " . $id)->rows;
        } else {
            return;
        }
        // Названия в таблицах OpenCart лежат экранированными (Request::clean); в индексе — как текст, экранирует шаблон.
        foreach ($rows as $r) {
            $this->db->query("UPDATE `" . $kernel->table('value_description') . "` SET name = '" . $this->db->escape(mb_substr(\PsFilter\Index\Normalizer::clean($r['name']), 0, 255, 'UTF-8')) . "'
                WHERE value_id = " . (int)$r['value_id'] . " AND language_id = " . (int)$r['language_id']);
        }
    }

    /** admin/model/customer/customer_group/{add,delete}CustomerGroup/after — цены по группам. */
    public function customerGroupChanged(&$route, &$args, &$output)
    {
        if ($this->enabled()) {
            $kernel = $this->kernel();
            $kernel->indexer()->writePrices('1');
            $kernel->cache()->flush();
        }
        return null;
    }

    /**
     * admin/model/user/user_group/{add,edit}UserGroup/after — права служебных экранов вслед за правом
     * на модуль (Schema::syncPermissions). Работает и при выключенном модуле: права нужны, чтобы включить.
     */
    public function userGroupChanged(&$route, &$args, &$output)
    {
        // editUserGroup($user_group_id, $data), addUserGroup($data) возвращает id
        $groupId = isset($args[0]) && !is_array($args[0]) ? (int)$args[0] : (int)$output;
        if ($groupId) {
            try {
                $this->kernel()->schema()->syncPermissions($groupId);
            } catch (\Exception $e) {
                $this->log->write('PS Filter: права группы ' . $groupId . ': ' . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Значения своих фасетов из вкладки «Фильтр»: psf_custom[facet_id][] = value_id,
     * psf_custom_new[facet_id] = «новое, еще одно». Строки индекса — с generation = 0:
     * полная переиндексация их не трогает (источник — только эта форма).
     * Без маркера psf_custom_present (форма без вкладки, импорт, копирование) ничего не меняется.
     */
    private function saveCustom($productId)
    {
        if (!$productId || !$this->enabled() || !isset($this->request->post['psf_custom_present'])) {
            return;
        }
        $kernel = $this->kernel();
        try {
            $facetIds = array_map('intval', array_column($this->db->query("SELECT facet_id FROM `" . $kernel->table('facet') . "` WHERE source = 'custom'")->rows, 'facet_id'));
            if (!$facetIds) {
                return;
            }
            $picked = isset($this->request->post['psf_custom']) && is_array($this->request->post['psf_custom']) ? $this->request->post['psf_custom'] : [];
            $added = isset($this->request->post['psf_custom_new']) && is_array($this->request->post['psf_custom_new']) ? $this->request->post['psf_custom_new'] : [];
            $store = new \PsFilter\Index\ValueStore($kernel);
            $rows = [];
            foreach ($facetIds as $fid) {
                $ids = array_map('intval', isset($picked[$fid]) ? (array)$picked[$fid] : []);
                if ($ids) {
                    // Только значения этого фасета; синоним — в основное значение.
                    $ids = array_map('intval', array_column($this->db->query("SELECT IF(alias_of > 0, alias_of, value_id) AS id FROM `" . $kernel->table('value') . "`
                        WHERE facet_id = " . $fid . " AND value_id IN (" . implode(',', $ids) . ")")->rows, 'id'));
                }
                // Request::clean экранирует POST — названия возвращаем как ввели.
                $text = isset($added[$fid]) ? html_entity_decode((string)$added[$fid], ENT_QUOTES, 'UTF-8') : '';
                foreach (preg_split('/\s*[,;]\s*/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $name) {
                    $ids[] = $store->custom($fid, mb_substr($name, 0, 255, 'UTF-8'));
                }
                foreach (array_unique($ids) as $vid) {
                    $rows[] = "(" . $fid . ", " . (int)$vid . ", " . (int)$productId . ", 0)";
                }
            }
            $this->db->query("DELETE FROM `" . $kernel->table('index') . "` WHERE product_id = " . (int)$productId . " AND facet_id IN (" . implode(',', $facetIds) . ")");
            if ($rows) {
                $this->db->query("INSERT IGNORE INTO `" . $kernel->table('index') . "` (facet_id, value_id, product_id, generation) VALUES " . implode(',', $rows));
            }
            foreach ($facetIds as $fid) {
                $kernel->job()->recountValues($fid);
            }
        } catch (\Exception $e) {
            $this->log->write('PS Filter: ошибка сохранения своих фасетов товара ' . (int)$productId . ': ' . $e->getMessage());
        }
    }

    /** @param int[] $ids */
    private function reindex(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !$this->enabled()) {
            return;
        }
        $kernel = $this->kernel();
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $kernel->cache()->invalidateProducts($chunk); // старые категории и бренд
                $kernel->indexer()->indexProducts($chunk, $kernel->job()->currentGeneration());
                $kernel->cache()->invalidateProducts($chunk); // новые
            }
            $kernel->facets()->invalidate();            // могли появиться новые значения
        } catch (\Throwable $e) {
            // Сбой индекса не должен ломать сохранение товара: товар догонит reconcile/полная переиндексация.
            $this->log->write('PS Filter: ошибка индексации товаров ' . implode(',', $ids) . ': ' . $e->getMessage());
        }
    }

    /**
     * Строки языкового файла модуля на языке админки — без общего объекта $language.
     *
     * @return array
     */
    private function strings()
    {
        $code = (string)$this->config->get(Platform::oc4() ? 'config_language_admin' : 'config_admin_language');
        $base = Platform::oc4() ? DIR_EXTENSION . 'ps_filter/admin/language/' : DIR_LANGUAGE;
        $tail = Platform::oc4() ? '/module/ps_filter.php' : '/extension/module/ps_filter.php';
        $_ = [];
        foreach (array_unique(['en-gb', $code]) as $c) {
            if ($c !== '' && is_file($base . $c . $tail)) {
                include $base . $c . $tail;
            }
        }
        return $_;
    }
}
