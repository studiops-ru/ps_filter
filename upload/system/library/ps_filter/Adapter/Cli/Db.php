<?php
namespace PsFilter\Adapter\Cli;

use PsFilter\Contract\Db as DbContract;

/**
 * Собственное mysqli-соединение для CLI (cli.php, cron), где реестра OpenCart нет.
 * Charset и sql_mode — как у драйвера OpenCart (system/library/db/mysqli.php),
 * чтобы CLI и витрина видели данные одинаково.
 */
class Db implements DbContract
{
    /** @var \mysqli */
    private $link;

    public function __construct($hostname, $username, $password, $database, $port = 3306)
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->link = @new \mysqli($hostname, $username, $password, $database, (int)$port);
        if ($this->link->connect_errno) {
            throw new \RuntimeException('ps_filter: не удалось подключиться к БД: ' . $this->link->connect_error);
        }
        $this->link->set_charset('utf8');
        $this->link->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    }

    public function query($sql)
    {
        $result = $this->link->query($sql);
        if ($this->link->errno) {
            throw new \RuntimeException('ps_filter SQL error ' . $this->link->errno . ': ' . $this->link->error . "\n" . $sql);
        }
        if ($result instanceof \mysqli_result) {
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            $out = new \stdClass();
            $out->num_rows = count($rows);
            $out->row = $rows ? $rows[0] : [];
            $out->rows = $rows;
            return $out;
        }
        return true;
    }

    public function escape($value)
    {
        return $this->link->real_escape_string((string)$value);
    }

    public function getLastId()
    {
        return (int)$this->link->insert_id;
    }

    public function countAffected()
    {
        return (int)$this->link->affected_rows;
    }
}
