<?php
namespace PsFilter\Oc\Admin;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * Экран индексации: статистика, полная переиндексация с прогрессом,
 * мастер фасетов, очистка кеша, адрес для cron.
 *
 * Переиндексация идет AJAX-шагами по ~4 с (start → step → step …): так она укладывается
 * в лимиты любого хостинга и продолжается с курсора после обрыва.
 */
class Index extends Base
{
    public function index()
    {
        $kernel = $this->kernel();
        $this->lang();
        $this->document->setTitle($this->language->get('heading_index'));

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->coreLink('common/dashboard')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->adminLink('base')],
            ['text' => $this->language->get('heading_index'), 'href' => $this->adminLink('index')],
        ];
        $m = $kernel->manticore();
        $data['manticore'] = $m->enabled() ? $m->status() : ['enabled' => false];
        foreach (['start', 'step', 'wizard', 'clear', 'cronkey', 'pages', 'manticore'] as $action) {
            $data['url_' . $action] = $this->adminLink('index', $action);
        }

        $data['stats'] = $this->stats($kernel);
        $data['job'] = $kernel->job()->last();
        $data['running'] = (bool)$kernel->job()->running();
        $data['can_modify'] = $this->can('modify', 'index');

        // Ключ cron — только с правом изменения: с ним по HTTP запускаются переиндексация и пересчеты.
        $key = $data['can_modify'] ? (string)$kernel->settings()->get('cron_key') : '';
        $data['cron_key'] = $key;
        $catalog = $this->catalogUrl();
        $data['cron_http'] = $key ? $catalog . 'index.php?route=' . $this->route('base', 'cron') . '&key=' . $key . '&task=reconcile' : '';
        $library = Platform::libraryDir();
        $data['cron_cli'] = 'php ' . $library . '/cli.php reconcile';
        $data['sitemap'] = $catalog . 'index.php?route=' . $this->route('base', 'sitemap');
        $data['pages_total'] = (int)$kernel->db()->query("SELECT COUNT(*) AS c FROM `" . $kernel->table('page') . "` WHERE is_auto = 0")->row['c'];
        $data['pages_auto'] = (int)$kernel->db()->query("SELECT COUNT(*) AS c FROM `" . $kernel->table('page') . "` WHERE is_auto = 1")->row['c'];

        $this->response->setOutput($this->view('index', $this->adminLayout($data)));
    }

    /** AJAX: начать полную переиндексацию (или продолжить идущую). */
    public function start()
    {
        $this->ajax(function ($kernel) {
            $restart = !empty($this->request->get['restart']);
            return ['job' => $kernel->job()->start($restart)];
        });
    }

    /** AJAX: один шаг (~4 с). */
    public function step()
    {
        $this->ajax(function ($kernel) {
            $job = $kernel->job()->step(4.0);
            return ['job' => $job, 'stats' => $job['status'] === 'done' ? $this->stats($kernel) : null];
        });
    }

    /**
     * AJAX: шаг пересчета посадочных (число товаров, автостраницы SEO-шаблонов).
     * GET cursor (из прошлого ответа), start (время начала прогона).
     */
    public function pages()
    {
        $this->ajax(function ($kernel) {
            $cursor = isset($this->request->get['cursor']) ? (string)$this->request->get['cursor'] : '';
            // Начало прогона — в сессии: по нему в конце удаляются неподтвержденные автостраницы,
            // присланному клиентом значению верить нельзя (дата «из будущего» снесла бы все).
            if ($cursor === '' || empty($this->session->data['psf_pages_start'])) {
                $this->session->data['psf_pages_start'] = date('Y-m-d H:i:s');
            }
            $start = $this->session->data['psf_pages_start'];
            $next = $kernel->autoPages()->step($cursor, $start, 4.0);
            if ($next === null) {
                unset($this->session->data['psf_pages_start']);
            }
            $auto = (int)$kernel->db()->query("SELECT COUNT(*) AS c FROM `" . $kernel->table('page') . "` WHERE is_auto = 1")->row['c'];
            return ['cursor' => $next, 'start' => $start, 'done' => $next === null, 'auto' => $auto];
        });
    }

    /**
     * AJAX: шаг полной пересборки таблицы Manticore из индекса MySQL. GET cursor (0 — начать).
     */
    public function manticore()
    {
        $this->ajax(function ($kernel) {
            $m = $kernel->manticore();
            if (!$m->enabled()) {
                return ['error' => $this->language->get('error_mt_disabled')];
            }
            $cursor = isset($this->request->get['cursor']) ? (int)$this->request->get['cursor'] : 0;
            try {
                $next = $m->rebuildStep($cursor, 4.0);
            } catch (\RuntimeException $e) {
                $m->setState('stale');
                return ['error' => $e->getMessage()];
            }
            return ['cursor' => $next, 'done' => $next === null, 'docs' => $next === null ? $m->index()->count() : null];
        });
    }

    /** AJAX: мастер фасетов. */
    public function wizard()
    {
        $this->ajax(function ($kernel) {
            return ['created' => $kernel->facetWizard()->run()];
        });
    }

    /** AJAX: очистка кеша. */
    public function clear()
    {
        $this->ajax(function ($kernel) {
            $kernel->cache()->flush();
            return ['success' => $this->language->get('text_cache_cleared')];
        });
    }

    /** AJAX: новый ключ для cron по HTTP. */
    public function cronkey()
    {
        $this->ajax(function ($kernel) {
            $key = bin2hex(random_bytes(16));
            $kernel->settings()->save(['cron_key' => $key]);
            return ['key' => $key];
        });
    }

    /** @return array */
    private function stats(\PsFilter\Kernel $kernel)
    {
        $db = $kernel->db();
        $count = function ($table, $where = '') use ($db, $kernel) {
            return (int)$db->query("SELECT COUNT(*) AS c FROM `" . $kernel->table($table) . "`" . ($where ? ' WHERE ' . $where : ''))->row['c'];
        };
        $sizes = $db->query("SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . $db->escape(str_replace('_', '\\_', $kernel->table(''))) . "%'")->row;
        $pool = $db->query("SELECT @@innodb_buffer_pool_size AS b")->row;
        return [
            'products'     => $count('product'),
            'products_all' => (int)$db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "product`")->row['c'],
            'facets'       => $count('facet', 'status = 1'),
            'facets_all'   => $count('facet'),
            'values'       => $count('value'),
            'rows'         => $count('index'),
            'ranges'       => $count('index_range'),
            'cache'        => $count('cache'),
            'size_mb'      => (float)$sizes['mb'],
            'pool_mb'      => round((float)$pool['b'] / 1048576),
        ];
    }

    /** Общая обертка AJAX-действий: права, исключения, JSON. */
    private function ajax(callable $action)
    {
        $this->lang();
        $json = [];
        if (!$this->can('modify', 'index')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            try {
                $json = $action($this->kernel());
            } catch (\Exception $e) {
                $json['error'] = $e->getMessage();
            }
        }
        $this->json($json);
    }
}
