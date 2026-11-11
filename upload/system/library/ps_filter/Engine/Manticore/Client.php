<?php
namespace PsFilter\Engine\Manticore;

/**
 * Соединение с Manticore Search по протоколу MySQL (порт 9306, mysqli).
 *
 * Свое соединение, не соединение OpenCart: у Manticore свой сервер и свой диалект SQL.
 * Режим mysqli_report глобальный (драйвер OC4 включает исключения, OC3 — нет), поэтому
 * ловим оба варианта: исключение mysqli и false. Наружу — всегда \RuntimeException:
 * вызывающий решает, откатиться ли на MySQL.
 */
class Client
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var \mysqli|null */
    private $link;
    /** @var string|null ошибка соединения (запоминается до конца процесса) */
    private $failed;

    public function __construct($host, $port)
    {
        $this->host = (string)$host;
        $this->port = (int)$port;
    }

    /** @return \mysqli */
    private function link()
    {
        if ($this->failed !== null) {
            throw new \RuntimeException($this->failed); // не ждать таймаут повторно в том же процессе
        }
        if ($this->link === null) {
            if (!function_exists('mysqli_init')) {
                throw new \RuntimeException('Manticore: нет расширения mysqli');
            }
            $link = mysqli_init();
            $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
            try {
                $ok = @$link->real_connect($this->host, '', '', '', $this->port);
            } catch (\mysqli_sql_exception $e) {
                $ok = false;
            }
            if (!$ok) {
                $this->failed = 'Manticore: нет соединения с ' . $this->host . ':' . $this->port . ' — ' . mysqli_connect_error();
                throw new \RuntimeException($this->failed);
            }
            $this->link = $link;
        }
        return $this->link;
    }

    /**
     * Один запрос.
     *
     * @return array строки (для команд без выборки — пустой массив)
     */
    public function query($sql)
    {
        $link = $this->link();
        try {
            $r = $link->query($sql);
        } catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('Manticore: ' . $e->getMessage() . ' — ' . mb_substr($sql, 0, 300, 'UTF-8'));
        }
        if ($r === false) {
            throw new \RuntimeException('Manticore: ' . $link->error . ' — ' . mb_substr($sql, 0, 300, 'UTF-8'));
        }
        if ($r === true) {
            return [];
        }
        $rows = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
        return $rows;
    }

    /**
     * Несколько запросов за один проход (multi_query). Запрос с FACET возвращает несколько
     * наборов строк — поэтому ответ — плоский список наборов в порядке выдачи сервером.
     *
     * @param string[] $sqls
     * @return array[] наборы строк
     */
    public function batch(array $sqls)
    {
        $link = $this->link();
        $sql = implode(";\n", $sqls);
        $sets = [];
        try {
            if (!$link->multi_query($sql)) {
                throw new \RuntimeException($link->error);
            }
            do {
                $r = $link->store_result();
                if ($r instanceof \mysqli_result) {
                    $sets[] = $r->fetch_all(MYSQLI_ASSOC);
                    $r->free();
                } elseif ($link->errno) {
                    throw new \RuntimeException($link->error);
                }
            } while ($link->more_results() && $link->next_result());
            if ($link->errno) {
                throw new \RuntimeException($link->error);
            }
        } catch (\mysqli_sql_exception $e) {
            $this->drain();
            throw new \RuntimeException('Manticore: ' . $e->getMessage() . ' — ' . mb_substr($sql, 0, 300, 'UTF-8'));
        } catch (\RuntimeException $e) {
            $this->drain();
            throw new \RuntimeException('Manticore: ' . $e->getMessage() . ' — ' . mb_substr($sql, 0, 300, 'UTF-8'));
        }
        return $sets;
    }

    /** Дочитывает оставшиеся наборы после ошибки, иначе соединение «занято» для следующего запроса. */
    private function drain()
    {
        try {
            while ($this->link->more_results() && @$this->link->next_result()) {
                $r = $this->link->store_result();
                if ($r instanceof \mysqli_result) {
                    $r->free();
                }
            }
        } catch (\mysqli_sql_exception $e) {
            // соединение уже в ошибке — следующий запрос переподключится
            $this->link = null;
        }
    }

    /** @return string строка в кавычках для SQL Manticore */
    public function quote($value)
    {
        return "'" . $this->link()->real_escape_string((string)$value) . "'";
    }

    /** @return bool доступен ли сервер */
    public function ping()
    {
        try {
            $this->query("SHOW STATUS LIKE 'uptime'");
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }
}
