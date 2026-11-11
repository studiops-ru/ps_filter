<?php
/**
 * ps_filter — точка входа библиотеки.
 *
 * Подключается явно (require_once) из каждого контроллера и обработчика события модуля.
 * Автозагрузчик OpenCart ищет классы в system/library/<имя в нижнем регистре>.php, а наш код
 * лежит в CamelCase под namespace PsFilter\, поэтому регистрируем свой загрузчик.
 * Pre-action startup/seo_url выполняется без событий, так что «кто-то уже загрузил» не бывает:
 * каждый вход в модуль обязан вызвать ps_filter_boot() сам.
 */

if (!function_exists('ps_filter_boot')) {
    spl_autoload_register(function ($class) {
        if (strncmp($class, 'PsFilter\\', 9) !== 0) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    /**
     * Возвращает ядро модуля, создавая его один раз на запрос.
     *
     * @param \Registry $registry
     * @return \PsFilter\Kernel
     */
    function ps_filter_boot($registry) {
        if (!$registry->has('ps_filter')) {
            $registry->set('ps_filter', \PsFilter\Kernel::fromRegistry($registry));
        }
        return $registry->get('ps_filter');
    }
}
