<?php
namespace PsFilter\Engine;

use PsFilter\Context;
use PsFilter\State;

/**
 * Бэкенд выборки. Сейчас один — MySQL (Engine\Mysql). Интерфейс нужен, чтобы позже
 * подключить внешний движок (Manticore) для очень больших каталогов, не трогая витрину и админку.
 */
interface Backend
{
    /**
     * @param Context    $context
     * @param State      $state
     * @param array|null $listing ['sort' => …, 'order' => ASC|DESC, 'start' => int, 'limit' => int]
     *                            или null, если список товаров не нужен (только счетчики)
     * @return Result
     */
    public function search(Context $context, State $state, $listing = null);

    /**
     * Состояние без фасетов, которых нет в разделе (как сделает search()), без самой выборки.
     *
     * @return State
     */
    public function restrict(Context $context, State $state);

    /** Освободить ресурсы, накопленные за запрос (временные таблицы и т.п.). */
    public function release();
}
