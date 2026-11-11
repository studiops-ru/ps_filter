<?php
namespace PsFilter;

use PsFilter\Adapter\Cli\Db;

/**
 * Публичные вызовы для других модулей (ps_1c и т. п.), которым нужен фильтр без OpenCart: обработчик обмена
 * подключает только config.php, реестра и загрузчика нет. Вызов — после
 *   require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';   // OC4: DIR_EXTENSION . 'ps_filter/system/library/ps_filter/…'
 * Наружу ничего не бросает: модуль не установлен, выключен или база недоступна — короткий ответ с причиной.
 */
class Api
{
    /** Сколько секунд по умолчанию тратить на переиндексацию в одном вызове. */
    const BUDGET = 1.5;

    /**
     * Товары изменились (цена, остаток, статус, новые, удаленные) — переиндексировать их, сколько успеется
     * за $options['budget'] секунд (по умолчанию 1,5), порциями по 250, и сбросить кеш счетчиков по ним.
     * Не успевшие догонит reconcile по date_modified (cron раз в час) — вызывающий обязан его менять.
     * Если идет полная переиндексация, товары пишутся с ее меткой, как при правке товара в админке.
     *
     * @param int[] $productIds
     * @param array $options budget — секунд на переиндексацию (0 — только принять, все оставить reconcile)
     * @return array ok=1: accepted, indexed, deferred, ms; ok=0: skipped (no_config | not_installed | disabled | schema)
     *               или error — текст ошибки
     */
    public static function productsChanged(array $productIds, array $options = [])
    {
        $t = microtime(true);
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), function ($id) { return $id > 0; })));
        try {
            $kernel = self::kernel();
            if (!$kernel) {
                return ['ok' => 0, 'skipped' => 'no_config'];
            }
            $settings = $kernel->settings();
            $version = $settings->getRaw('module_ps_filter_db_version');
            if ($version === null) {
                return ['ok' => 0, 'skipped' => 'not_installed'];
            }
            if (!(int)$settings->getRaw('module_ps_filter_status', 0)) {
                return ['ok' => 0, 'skipped' => 'disabled'];
            }
            // Файлы модуля новее схемы (обновили, в админку еще не заходили): писать в старые таблицы не будем.
            if ((int)$version < Schema::DB_VERSION) {
                return ['ok' => 0, 'skipped' => 'schema'];
            }
            $budget = isset($options['budget']) ? max(0, (float)$options['budget']) : self::BUDGET;
            $done = 0;
            if ($ids && $budget > 0) {
                $generation = $kernel->job()->currentGeneration();
                // Порция 250: бюджет проверяется после каждой, перебор — не больше одной порции (~0,2 с на большом каталоге).
                foreach (array_chunk($ids, 250) as $chunk) {
                    $kernel->cache()->invalidateProducts($chunk); // старые категории и бренд
                    $kernel->indexer()->indexProducts($chunk, $generation);
                    $kernel->cache()->invalidateProducts($chunk); // новые
                    $done += count($chunk);
                    if (microtime(true) - $t >= $budget) {
                        break;
                    }
                }
                $kernel->facets()->invalidate(); // могли появиться новые значения
            }
            return ['ok' => 1, 'accepted' => count($ids), 'indexed' => $done, 'deferred' => count($ids) - $done,
                'ms' => (int)round((microtime(true) - $t) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => 0, 'error' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $t) * 1000)];
        }
    }

    /** Ядро на своем соединении с базой из config.php; null — констант базы нет. */
    private static function kernel()
    {
        foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PREFIX'] as $c) {
            if (!defined($c)) {
                return null;
            }
        }
        $db = new Db(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? DB_PORT : 3306);
        // Часовой пояс как у магазина (как в cli.php): даты индекса из вызова и из админки не разойдутся.
        $tz = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'config' AND `key` = 'config_timezone'");
        if ($tz->num_rows && $tz->row['value'] !== '' && @date_default_timezone_set($tz->row['value'])) {
            $db->query("SET time_zone = '" . date('P') . "'");
        }
        return new Kernel($db, DB_PREFIX);
    }
}
