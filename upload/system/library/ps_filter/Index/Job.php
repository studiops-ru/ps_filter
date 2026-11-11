<?php
namespace PsFilter\Index;

use PsFilter\Kernel;

/**
 * Полная переиндексация шагами.
 *
 * Каждый шаг укладывается в несколько секунд (лимиты хостинга, AJAX-цикл админки),
 * состояние — в ps_filter_job, поэтому оборванный job продолжается с курсора.
 * Метка generation = job_id: строки, не тронутые этим job (удаленные товары), убирает стадия cleanup.
 *
 * Стадии: batches → cleanup → values → pages|<курсор> (посадочные) → cache → done.
 */
class Job
{
    /** Минут без шага, после которых running-job считается брошенным. */
    const STALE_MINUTES = 15;

    /** @var Kernel */
    private $k;
    /** @var bool */
    private $lockBusy = false;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * Начинает полную переиндексацию или возвращает уже идущую.
     *
     * @param bool $restart прервать идущий job и начать заново
     * @return array строка job
     */
    public function start($restart = false)
    {
        $db = $this->k->db();
        $running = $this->running();
        if ($running && !$restart) {
            return $running;
        }
        if ($running) {
            $db->query("UPDATE `" . $this->k->table('job') . "` SET status = 'aborted', date_updated = NOW() WHERE job_id = " . (int)$running['job_id']);
        }
        $total = (int)$db->query("SELECT COUNT(*) AS c FROM `" . $this->k->prefix() . "product`")->row['c'];
        $db->query("INSERT INTO `" . $this->k->table('job') . "` SET type = 'full', status = 'running', stage = 'batches', total = " . $total . ", done = 0, cursor_id = 0, log = '', date_started = NOW(), date_updated = NOW()");
        $id = $db->getLastId();
        $db->query("UPDATE `" . $this->k->table('job') . "` SET generation = " . $id . " WHERE job_id = " . $id);
        return $this->get($id);
    }

    /**
     * Выполняет работу, пока не истечет лимит времени.
     *
     * @param float $timeLimit секунды
     * @return array строка job после шага
     */
    public function step($timeLimit = 5.0)
    {
        $this->lockBusy = false;
        $job = $this->running();
        if (!$job) {
            return $this->last();
        }
        $db = $this->k->db();
        // Два параллельных шага (кнопка в админке + cron) испортили бы курсор. Имя блокировки — с базой:
        // два магазина с одним префиксом на одном MySQL не должны мешать друг другу.
        $lock = 'psf_job_' . md5($db->query("SELECT DATABASE() AS d")->row['d'] . '|' . $this->k->prefix());
        if (!(int)$db->query("SELECT GET_LOCK('" . $lock . "', 0) AS l")->row['l']) {
            $this->lockBusy = true;
            return $job;
        }

        try {
            $started = microtime(true);
            $indexer = $this->k->indexer();
            $batch = max(50, (int)$this->k->settings()->get('batch_size', 500));

            while (microtime(true) - $started < $timeLimit && $job['status'] === 'running') {
                switch ($job['stage']) {
                    case 'batches':
                        $ids = array_column($db->query("SELECT product_id FROM `" . $this->k->prefix() . "product` WHERE product_id > " . (int)$job['cursor_id'] . " ORDER BY product_id LIMIT " . $batch)->rows, 'product_id');
                        if (!$ids) {
                            $job = $this->update($job, ['stage' => 'cleanup']);
                            break;
                        }
                        $indexer->indexProducts($ids, (int)$job['generation']);
                        $job = $this->update($job, ['cursor_id' => (int)end($ids), 'done' => (int)$job['done'] + count($ids)]);
                        break;

                    case 'cleanup':
                        $this->cleanup((int)$job['generation']);
                        $job = $this->update($job, ['stage' => 'values']);
                        break;

                    case 'values':
                        $this->recountValues();
                        // Определения и статистика разделов изменились — посадочным нужны свежие.
                        $this->k->cache()->flush();
                        $job = $this->update($job, ['stage' => 'pages|']);
                        break;

                    case 'cache':
                        $this->k->cache()->flush();
                        $this->k->settings()->setRaw('module_ps_filter_generation', (string)$job['generation']);
                        $job = $this->update($job, ['stage' => 'done', 'status' => 'done']);
                        break;

                    default:
                        if (strpos($job['stage'], 'pages|') === 0) {
                            // Посадочные: число товаров и автостраницы SEO-шаблонов, шагами по разделам.
                            $remaining = max(0.5, $timeLimit - (microtime(true) - $started));
                            $cursor = $this->k->autoPages()->step(substr($job['stage'], 6), $job['date_started'], $remaining);
                            // Manticore включен, но таблица не готова (первое включение, сбой) — пересобрать.
                            $m = $this->k->manticore();
                            $next = $m->enabled() && $m->state() !== 'ready' ? 'manticore|0' : 'cache';
                            $job = $this->update($job, ['stage' => $cursor === null ? $next : 'pages|' . $cursor]);
                            break;
                        }
                        if (strpos($job['stage'], 'manticore|') === 0) {
                            // Полная пересборка таблицы Manticore из ps_filter_* шагами по товарам.
                            $remaining = max(0.5, $timeLimit - (microtime(true) - $started));
                            try {
                                $cursor = $this->k->manticore()->rebuildStep((int)substr($job['stage'], 10), $remaining);
                            } catch (\RuntimeException $e) {
                                // Индекс MySQL готов, витрина продолжит на нем; ошибку видно в журнале задания.
                                $this->k->manticore()->setState('stale');
                                $job = $this->update($job, ['stage' => 'cache', 'log' => substr($e->getMessage(), 0, 2000)]);
                                break;
                            }
                            $job = $this->update($job, ['stage' => $cursor === null ? 'cache' : 'manticore|' . $cursor]);
                            break;
                        }
                        $job = $this->update($job, ['status' => 'done']);
                }
            }
        } catch (\Throwable $e) {
            // И \Error (TypeError и т.п.): иначе job остался бы running и блокировал reconcile.
            $job = $this->update($job, ['status' => 'error', 'log' => substr($e->getMessage(), 0, 2000)]);
        }

        $db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        return $job;
    }

    /** Последний step() не выполнялся: шаг уже идет в другом процессе (админка + cron). */
    public function lockBusy()
    {
        return $this->lockBusy;
    }

    /**
     * Полный прогон до конца (CLI, cron).
     *
     * @param callable|null $progress function(array $job)
     * @return array
     */
    public function runAll(?callable $progress = null)
    {
        $job = $this->start();
        while ($job['status'] === 'running') {
            $job = $this->step(10.0);
            if ($this->lockBusy) {
                sleep(1); // шаг идет в другом процессе — не крутить пустой цикл
            }
            if ($progress) {
                $progress($job);
            }
        }
        return $job;
    }

    /**
     * Идущий job. $activeOnly — только тот, что действительно шагает (date_updated свежее STALE_MINUTES):
     * брошенный (закрыли вкладку «Индексация», убитый PHP) остается running и продолжается с курсора
     * следующим step(), но reconcile он блокировать не должен.
     *
     * @param bool $activeOnly
     * @return array|null
     */
    public function running($activeOnly = false)
    {
        // По модулю: job мог записать процесс с другим часовым поясом сессии MySQL (админка, cron, CLI) —
        // тогда date_updated «из будущего», а брошенный job выглядел бы живым вечно.
        $q = $this->k->db()->query("SELECT *, ABS(TIMESTAMPDIFF(MINUTE, date_updated, NOW())) AS idle_minutes FROM `" . $this->k->table('job') . "` WHERE status = 'running' ORDER BY job_id DESC LIMIT 1");
        if (!$q->num_rows) {
            return null;
        }
        if ($activeOnly && (int)$q->row['idle_minutes'] >= self::STALE_MINUTES) {
            return null;
        }
        return $q->row;
    }

    /** Брошенный job: running, но без шагов дольше STALE_MINUTES. @return array|null */
    public function stale()
    {
        $job = $this->running();
        return $job && (int)$job['idle_minutes'] >= self::STALE_MINUTES ? $job : null;
    }

    /** @return array|null */
    public function last()
    {
        $q = $this->k->db()->query("SELECT * FROM `" . $this->k->table('job') . "` ORDER BY job_id DESC LIMIT 1");
        return $q->num_rows ? $q->row : null;
    }

    /**
     * Метка для инкрементальной индексации: generation идущего job, иначе последнего завершенного.
     * Так товар, сохраненный во время полной переиндексации, не будет удален ее cleanup.
     *
     * @return int
     */
    public function currentGeneration()
    {
        $job = $this->running();
        if ($job) {
            return (int)$job['generation'];
        }
        return max(1, (int)$this->k->settings()->getRaw('module_ps_filter_generation', 1));
    }

    /** Убирает строки товаров, которых не коснулся job (удалены из магазина). */
    private function cleanup($generation)
    {
        $db = $this->k->db();
        $stale = "SELECT product_id FROM `" . $this->k->table('product') . "` WHERE generation < " . (int)$generation;
        $ids = array_column($db->query($stale)->rows, 'product_id');
        foreach (array_chunk($ids, 5000) as $chunk) {
            $this->k->indexer()->deleteProducts($chunk);
        }
        // Строки без товара (ручная правка БД, товар удален мимо событий): индекс, в том числе свои фасеты, диапазоны, цены.
        $db->query("DELETE i FROM `" . $this->k->table('index') . "` i LEFT JOIN `" . $this->k->table('product') . "` fp ON fp.product_id = i.product_id WHERE fp.product_id IS NULL");
        $db->query("DELETE r FROM `" . $this->k->table('index_range') . "` r LEFT JOIN `" . $this->k->table('product') . "` fp ON fp.product_id = r.product_id WHERE fp.product_id IS NULL");
        $db->query("DELETE pr FROM `" . $this->k->table('price') . "` pr LEFT JOIN `" . $this->k->table('product') . "` fp ON fp.product_id = pr.product_id WHERE fp.product_id IS NULL");
    }

    /**
     * Счетчик товаров у значений и автоскрытие «сирот» (ручное скрытие не трогаем).
     *
     * @param int $facetId 0 — все фасеты
     */
    public function recountValues($facetId = 0)
    {
        $db = $this->k->db();
        $f = (int)$facetId;
        $db->query("UPDATE `" . $this->k->table('value') . "` v
            LEFT JOIN (SELECT value_id, COUNT(*) AS c FROM `" . $this->k->table('index') . "`" . ($f ? " WHERE facet_id = " . $f : "") . " GROUP BY value_id) x ON x.value_id = v.value_id
            SET v.products = COALESCE(x.c, 0), v.hidden = IF(v.hidden_manual = 1 OR COALESCE(x.c, 0) = 0, 1, 0)" . ($f ? " WHERE v.facet_id = " . $f : ""));
    }

    /** @return array */
    private function get($id)
    {
        return $this->k->db()->query("SELECT * FROM `" . $this->k->table('job') . "` WHERE job_id = " . (int)$id)->row;
    }

    /**
     * @param array $job
     * @param array $fields
     * @return array
     */
    private function update(array $job, array $fields)
    {
        $db = $this->k->db();
        $set = [];
        foreach ($fields as $k => $v) {
            $set[] = "`" . $k . "` = " . (is_int($v) ? $v : "'" . $db->escape($v) . "'");
        }
        $set[] = "date_updated = NOW()";
        $db->query("UPDATE `" . $this->k->table('job') . "` SET " . implode(', ', $set) . " WHERE job_id = " . (int)$job['job_id']);
        return array_merge($job, $fields);
    }
}
