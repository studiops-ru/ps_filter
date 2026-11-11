<?php
namespace PsFilter\Adapter\Oc3;

use PsFilter\Contract\Db as DbContract;

/**
 * Обертка над $registry->get('db') OpenCart 3.
 * Своего соединения не открываем: временные таблицы движка должны жить в том же
 * соединении, что и штатные запросы страницы.
 */
class Db implements DbContract
{
    /** @var \DB */
    private $db;

    /** @param \DB $db */
    public function __construct($db)
    {
        $this->db = $db;
    }

    public function query($sql)
    {
        return $this->db->query($sql);
    }

    public function escape($value)
    {
        return $this->db->escape((string)$value);
    }

    public function getLastId()
    {
        return (int)$this->db->getLastId();
    }

    public function countAffected()
    {
        return (int)$this->db->countAffected();
    }
}
