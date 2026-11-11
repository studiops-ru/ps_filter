<?php
namespace PsFilter\Engine\Manticore;

use PsFilter\Kernel;

/**
 * Режим Manticore: настройки, состояние таблицы, синхронизация и решение «можно ли сейчас им пользоваться».
 *
 * Состояние (oc_setting module_ps_filter_manticore_state):
 *   ''       — таблица не строилась;
 *   building — идет полная пересборка (синхронизация по товарам уже работает, витрина — еще на MySQL);
 *   ready    — таблица совпадает с MySQL, витрина считает на Manticore;
 *   stale    — синхронизация не удалась (сервер был недоступен): витрина на MySQL до пересборки.
 * Недоступность сервера на витрине запоминается в кеше на минуту, чтобы каждая страница
 * не ждала таймаут соединения.
 */
class Manager
{
    const STATE_KEY = 'module_ps_filter_manticore_state';
    const FORMAT_KEY = 'module_ps_filter_manticore_format';

    /** @var Kernel */
    private $k;
    /** @var Client|null */
    private $client;
    /** @var Index|null */
    private $index;
    /** @var string|null */
    private $state;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /** @return bool в настройках выбран Manticore */
    public function enabled()
    {
        return $this->k->settings()->get('backend') === 'manticore';
    }

    /** @return Client */
    public function client()
    {
        if ($this->client === null) {
            $s = $this->k->settings();
            $this->client = new Client((string)$s->get('manticore_host', '127.0.0.1'), (int)$s->get('manticore_port', 9306));
        }
        return $this->client;
    }

    /** @return Index */
    public function index()
    {
        if ($this->index === null) {
            $this->index = new Index($this->k, $this->client(), $this->tableName());
        }
        return $this->index;
    }

    /**
     * Имя таблицы: из настроек или по базе и префиксу магазина — несколько магазинов
     * на одном сервере Manticore не пересекаются.
     *
     * @return string
     */
    public function tableName()
    {
        $name = (string)$this->k->settings()->get('manticore_table', '');
        if ($name === '') {
            $db = defined('DB_DATABASE') ? DB_DATABASE : 'shop';
            $name = 'psf_' . $db . '_' . $this->k->prefix() . 'products';
        }
        return strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $name));
    }

    /**
     * @return string '', building, ready, stale или outdated (таблица в старом формате документов — нужна пересборка)
     */
    public function state()
    {
        if ($this->state === null) {
            $this->state = (string)$this->k->settings()->getRaw(self::STATE_KEY, '');
            if ($this->state === 'ready' && (int)$this->k->settings()->getRaw(self::FORMAT_KEY, 0) !== Index::FORMAT) {
                $this->state = 'outdated';
            }
        }
        return $this->state;
    }

    /** @param string $state */
    public function setState($state)
    {
        $this->state = (string)$state;
        $this->k->settings()->setRaw(self::STATE_KEY, $this->state);
    }

    /**
     * Можно ли витрине считать на Manticore прямо сейчас.
     *
     * @return bool
     */
    public function usable()
    {
        return $this->enabled() && $this->state() === 'ready' && !$this->k->cache()->get('manticore|down');
    }

    /** Сервер не ответил: минуту витрина работает на MySQL без попыток соединиться. */
    public function markDown($error)
    {
        $this->k->cache()->set('manticore|down', 'manticore', 1, 60);
        $this->k->log('PS Filter: Manticore недоступен, выборка на MySQL — ' . $error);
    }

    /**
     * Изменились товары в индексе MySQL — перенести их в Manticore.
     * Ошибка не ломает сохранение товара: таблица помечается устаревшей до пересборки.
     *
     * @param int[] $ids
     */
    public function touched(array $ids)
    {
        if (!$ids || !$this->enabled() || !in_array($this->state(), ['ready', 'building'], true)) {
            return;
        }
        try {
            $this->index()->sync($ids);
        } catch (\RuntimeException $e) {
            $this->setState('stale');
            $this->k->log('PS Filter: синхронизация с Manticore не удалась, витрина переведена на MySQL до пересборки — ' . $e->getMessage());
        }
    }

    /** Изменились все товары (цены по датам, группы покупателей) — перенести все. */
    public function touchedAll()
    {
        if (!$this->enabled() || !in_array($this->state(), ['ready', 'building'], true)) {
            return;
        }
        $cursor = 0;
        try {
            while (true) {
                $ids = array_column($this->k->db()->query("SELECT product_id FROM `" . $this->k->table('product') . "`
                    WHERE product_id > " . (int)$cursor . " ORDER BY product_id LIMIT 2000")->rows, 'product_id');
                if (!$ids) {
                    break;
                }
                $this->index()->sync($ids);
                $cursor = (int)end($ids);
            }
        } catch (\RuntimeException $e) {
            $this->setState('stale');
            $this->k->log('PS Filter: синхронизация с Manticore не удалась — ' . $e->getMessage());
        }
    }

    /**
     * Шаг полной пересборки (админка, cron, CLI, конец переиндексации).
     *
     * @param int   $cursor 0 — начать
     * @param float $timeLimit
     * @return int|null следующий курсор или null — готово
     */
    public function rebuildStep($cursor, $timeLimit = 4.0)
    {
        if ((int)$cursor === 0) {
            $this->setState('building');
        }
        $next = $this->index()->rebuildStep((int)$cursor, $timeLimit);
        if ($next === null) {
            $this->k->settings()->setRaw(self::FORMAT_KEY, (string)Index::FORMAT);
            $this->setState('ready');
            // Счетчики MySQL и Manticore в кеше разделены префиксом, но статистику Manticore
            // от прошлой таблицы надо выбросить.
            $this->k->cache()->flush();
        }
        return $next;
    }

    /**
     * Пересборка в пределах времени с продолжением между вызовами (cron по HTTP).
     *
     * @return int|null курсор, на котором остановились, или null — готово
     */
    public function rebuildFor($timeLimit)
    {
        $key = 'module_ps_filter_manticore_cursor';
        $cursor = $this->state() === 'building' ? (int)$this->k->settings()->getRaw($key, 0) : 0;
        $until = microtime(true) + $timeLimit;
        do {
            $cursor = $this->rebuildStep($cursor, max(0.5, $until - microtime(true)));
        } while ($cursor !== null && microtime(true) < $until);
        $this->k->settings()->setRaw($key, (string)(int)$cursor);
        return $cursor;
    }

    /**
     * Удаление модуля: убрать таблицу Manticore, если она могла быть построена.
     * Магазины без Manticore сервер не трогают — нет ожидания таймаута соединения.
     *
     * @return string строка отчета или ''
     */
    public function drop()
    {
        if (!$this->enabled() && $this->state() === '') {
            return '';
        }
        try {
            $this->index()->drop();
            return 'таблица Manticore ' . $this->tableName() . ' удалена';
        } catch (\RuntimeException $e) {
            return 'таблицу Manticore ' . $this->tableName() . ' удалить не удалось (' . $e->getMessage() . ') — удалите вручную';
        }
    }

    /**
     * Состояние для админки и CLI.
     *
     * @return array
     */
    public function status()
    {
        $out = ['enabled' => $this->enabled(), 'state' => $this->state(), 'table' => $this->tableName(),
            'host' => (string)$this->k->settings()->get('manticore_host', '127.0.0.1') . ':' . (int)$this->k->settings()->get('manticore_port', 9306),
            'docs' => null, 'products' => (int)$this->k->db()->query("SELECT COUNT(*) AS c FROM `" . $this->k->table('product') . "`")->row['c'],
            'error' => '', 'version' => ''];
        try {
            $v = $this->client()->query("SHOW STATUS LIKE 'version'");
            $out['version'] = $v ? (string)$v[0]['Value'] : '';
        } catch (\RuntimeException $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        try {
            $out['docs'] = $this->index()->count();
        } catch (\RuntimeException $e) {
            $out['docs'] = null; // сервер отвечает, таблицы еще нет — нужна пересборка
        }
        return $out;
    }
}
