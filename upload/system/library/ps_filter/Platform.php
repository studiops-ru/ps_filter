<?php
namespace PsFilter;

/**
 * Версия OpenCart, под которой работает модуль: все, что в ядре различается между OC3 и OC4
 * (маршруты, имена событий, схема таблиц товара), спрашивается здесь.
 *
 * OC4 узнаем по DIR_EXTENSION: константа есть в config.php любой OC4 (и в CLI, где VERSION
 * не определена), в OC3 ее нет.
 */
class Platform
{
    /** @var bool|null */
    private static $oc4;

    /** @return bool */
    public static function oc4()
    {
        if (self::$oc4 === null) {
            self::$oc4 = defined('DIR_EXTENSION') || (defined('VERSION') && version_compare(VERSION, '4.0.0.0', '>='));
        }
        return self::$oc4;
    }

    /**
     * Папка библиотеки модуля (без слеша в конце): OC3 — system/library/ps_filter,
     * OC4 — extension/ps_filter/system/library/ps_filter.
     *
     * @return string
     */
    public static function libraryDir()
    {
        return self::oc4() ? DIR_EXTENSION . 'ps_filter/system/library/ps_filter' : rtrim(DIR_SYSTEM, '/') . '/library/ps_filter';
    }

    /**
     * Маршрут витрины в нотации текущей версии.
     *
     * @param string $name category | manufacturer | search
     * @return string
     */
    public static function route($name)
    {
        $routes = [
            'category'     => 'product/category',
            'manufacturer' => self::oc4() ? 'product/manufacturer.info' : 'product/manufacturer/info',
            'search'       => 'product/search',
        ];
        return $routes[$name];
    }

    /**
     * Маршрут контроллера модуля: base (панель) | event | facet | page | template | index.
     *
     * В OC3 рядом с основным контроллером в extension/module/ лежит только он сам: список
     * Расширения → Модули строится по glob('controller/extension/module/*.php'), и любой другой файл
     * там — строка «heading_title» с кнопкой «Установить». Служебные — в extension/ps_filter/.
     * В OC4 то же: список строится по extension/<код>/admin/controller/module/*.php, поэтому служебные
     * контроллеры — в tool/ (extension/ps_filter/tool/<имя>), в module/ — только основной.
     *
     * @param string $name
     * @param string $method метод (OC3 — через «/», OC4 — через «.»)
     * @return string
     */
    public static function moduleRoute($name = 'base', $method = '')
    {
        if (self::oc4()) {
            $route = $name === 'base' ? 'extension/ps_filter/module/ps_filter' : 'extension/ps_filter/tool/' . $name;
        } else {
            $route = $name === 'base' ? 'extension/module/ps_filter' : 'extension/ps_filter/' . $name;
        }
        return $method === '' ? $route : $route . (self::oc4() ? '.' : '/') . $method;
    }

    /**
     * Запись прав (oc_user_group.permission) на контроллер модуля.
     *
     * OC3 (admin/controller/startup/permission.php) у маршрута не из штатных папок extension/<тип>/
     * проверяет только первые две части, поэтому все служебные контроллеры покрывает одна запись
     * «extension/ps_filter». OC4 проверяет маршрут целиком.
     *
     * @param string $name
     * @return string
     */
    public static function permissionRoute($name = 'base')
    {
        if (self::oc4() || $name === 'base') {
            return self::moduleRoute($name);
        }
        return 'extension/ps_filter';
    }
}
