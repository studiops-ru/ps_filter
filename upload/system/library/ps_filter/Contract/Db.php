<?php
namespace PsFilter\Contract;

/**
 * Минимальный доступ к БД, который нужен ядру.
 *
 * Повторяет интерфейс драйвера OpenCart 3/4: query() возвращает объект с полями
 * row / rows / num_rows для SELECT и true для остальных запросов, ошибка — исключение.
 */
interface Db
{
    /**
     * @param string $sql
     * @return \stdClass|bool
     */
    public function query($sql);

    /**
     * @param string $value
     * @return string
     */
    public function escape($value);

    /** @return int */
    public function getLastId();

    /** @return int */
    public function countAffected();
}
