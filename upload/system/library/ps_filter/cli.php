<?php
/**
 * ps_filter — командная строка.
 *
 *   php system/library/ps_filter/cli.php status
 *   php system/library/ps_filter/cli.php install [--group=1]
 *   php system/library/ps_filter/cli.php uninstall
 *   php system/library/ps_filter/cli.php wizard | reindex [--restart] | reconcile
 *   php system/library/ps_filter/cli.php bench | verify [--category=ID] [--subtree] [--derived]
 *   php system/library/ps_filter/cli.php layout [--position=content_bottom]   (без --position — только показать)
 *   php system/library/ps_filter/cli.php sold        пересчитать продажи за период («Популярные», ps_filter_sold)
 *
 * Незнакомый флаг или лишнее слово — ошибка, команда не выполняется.
 *
 * Запускать из корня магазина или откуда угодно: корень вычисляется от расположения файла.
 * Конфиг берется из admin/config.php (там же DB_*, что и у витрины, плюс DIR_CATALOG).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Корень магазина: OC3 — system/library/ps_filter, OC4 — extension/ps_filter/system/library/ps_filter.
$root = __DIR__;
do {
    $root = dirname($root);
} while (!is_file($root . '/admin/config.php') && dirname($root) !== $root);
$config = is_file($root . '/admin/config.php') ? $root . '/admin/config.php' : $root . '/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "ps_filter: не найден config.php в $root\n");
    exit(1);
}
require $config;
require __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$command = $args ? array_shift($args) : 'status';

// Команды и их флаги. Незнакомый флаг или лишнее слово — ошибка до любых действий: опечатка
// (`layout --list` вместо просмотра) иначе молча выполняла команду по умолчанию и меняла макеты.
$commands = [
    'status'    => [],
    'install'   => ['group'],
    'uninstall' => [],
    'wizard'    => [],
    'reindex'   => ['restart'],
    'reconcile' => [],
    'bench'     => ['category', 'manufacturer', 'subtree', 'n', 'cache'],
    'verify'    => ['category', 'manufacturer', 'subtree', 'n'],
    'compare'   => ['category', 'manufacturer', 'subtree', 'n', 'tax'],
    'manticore' => [],
    'layout'    => ['position', 'list'],
    'sold'      => [],
    'regroup'   => [],
];
$common = ['derived', 'backend', 'manticore_host'];
$usage = "Команды: status, install [--group=N], uninstall, wizard, reindex [--restart], reconcile,\n"
    . "  bench|verify|compare [--category=ID] [--manufacturer=ID] [--subtree] [--n=N], manticore status|rebuild,\n"
    . "  layout --list | layout --position=column_left|column_right|content_top|content_bottom, sold\n"
    . "Общие флаги: --derived, --backend=mysql|manticore, --manticore-host=HOST\n";
if (!isset($commands[$command])) {
    fwrite(STDERR, "ps_filter: неизвестная команда «{$command}»\n" . $usage);
    exit(2);
}
$opts = [];
$words = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z][a-z_-]*)(?:=(.*))?$/', $a, $m)) {
        $name = str_replace('-', '_', $m[1]);
        if (!in_array($name, $commands[$command], true) && !in_array($name, $common, true)) {
            fwrite(STDERR, "ps_filter: у команды «{$command}» нет флага --{$m[1]}, ничего не сделано\n" . $usage);
            exit(2);
        }
        $opts[$name] = isset($m[2]) ? $m[2] : true;
    } else {
        $words[] = $a;
    }
}
// Слова после команды понимает только manticore (status | rebuild).
$wordsOk = $command === 'manticore' ? count($words) <= 1 && (!$words || in_array($words[0], ['status', 'rebuild'], true)) : !$words;
if (!$wordsOk) {
    fwrite(STDERR, "ps_filter: лишнее «" . implode(' ', $words) . "» у команды «{$command}», ничего не сделано\n" . $usage);
    exit(2);
}
if ($command === 'layout' && isset($opts['position']) && !in_array($opts['position'], ['column_left', 'column_right', 'content_top', 'content_bottom'], true)) {
    fwrite(STDERR, "ps_filter: позиция «{$opts['position']}» не из column_left, column_right, content_top, content_bottom\n");
    exit(2);
}

$kernel = new \PsFilter\Kernel(
    new \PsFilter\Adapter\Cli\Db(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? DB_PORT : 3306),
    DB_PREFIX
);

// Часовой пояс как у магазина (startup.php: config_timezone + SET time_zone), иначе даты индекса из CLI и из админки разойдутся.
$tz = $kernel->db()->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'config' AND `key` = 'config_timezone'");
if ($tz->num_rows && $tz->row['value'] !== '' && @date_default_timezone_set($tz->row['value'])) {
    $kernel->db()->query("SET time_zone = '" . date('P') . "'");
}

$out = function ($lines) {
    foreach ((array)$lines as $line) {
        echo (is_array($line) ? json_encode($line, JSON_UNESCAPED_UNICODE) : $line), "\n";
    }
};

// --derived: проверить запасную стратегию множеств (подзапросы вместо временных таблиц).
if (!empty($opts['derived'])) {
    $kernel->settings()->override(['set_strategy' => 'derived']);
}
// --backend=mysql|manticore: движок для bench/verify независимо от настроек магазина
// (--manticore-host=… для запуска вне сети Docker).
if (!empty($opts['backend'])) {
    $kernel->settings()->override(['backend' => $opts['backend'] === 'manticore' ? 'manticore' : 'mysql']);
}
if (!empty($opts['manticore_host'])) {
    $kernel->settings()->override(['manticore_host' => (string)$opts['manticore_host']]);
}

try {
    if ($command !== 'uninstall') {
        foreach ($kernel->schema()->upgrade() as $line) {
            $out($line); // файлы модуля новее схемы в БД
        }
    }
    switch ($command) {
        case 'install':
            $group = isset($opts['group']) ? (int)$opts['group'] : 1;
            $out($kernel->schema()->install([$group], true));
            break;

        case 'uninstall':
            $out($kernel->schema()->uninstall());
            break;

        case 'status':
            echo json_encode($kernel->schema()->status(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
            break;

        case 'wizard':
            // Создать фасеты из атрибутов, опций и групп фильтров (существующие не трогаются).
            $created = $kernel->facetWizard()->run();
            $out('создано фасетов: ' . count($created));
            foreach ($created as $code => $name) {
                $out("  $code — $name");
            }
            break;

        case 'reindex':
            // Полная переиндексация. --restart прерывает идущий job и начинает заново.
            $t = microtime(true);
            if (!empty($opts['restart'])) {
                $kernel->job()->start(true);
            }
            $job = $kernel->job()->runAll(function ($job) use ($t) {
                printf("  [%5.1fs] %s %d/%d\n", microtime(true) - $t, $job['stage'], $job['done'], $job['total']);
            });
            $out(sprintf('job %d: %s за %.1f с%s', $job['job_id'], $job['status'], microtime(true) - $t, $job['log'] ? ' — ' . $job['log'] : ''));
            break;

        case 'reconcile':
            // Догон товаров, измененных в обход админки (импорт, правка БД).
            $t = microtime(true);
            do {
                $r = $kernel->indexer()->reconcile(30.0);
                $out(sprintf('  проиндексировано %d, удалено %d%s', $r['indexed'], $r['deleted'], !empty($r['viewed']) ? ', просмотры ' . $r['viewed'] : ''));
            } while ($r['more']);
            $out(sprintf('reconcile: %.1f с', microtime(true) - $t));
            break;

        case 'bench':
            // Замер движка: cli.php bench [--category=ID | --manufacturer=ID] [--n=20] [--cache]
            // Без --cache кеш выключен: меряем холодные запросы. sql_mode строгий, как на хорошем хостинге.
            if (empty($opts['cache'])) {
                $kernel->settings()->override(['cache_driver' => 'none']);
            }
            $kernel->db()->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $ctx = new \PsFilter\Context();
            if (!empty($opts['manufacturer'])) {
                $ctx->type = \PsFilter\Context::MANUFACTURER;
                $ctx->id = (int)$opts['manufacturer'];
            } else {
                // По умолчанию — самый крупный раздел (без --subtree считаются только прямые привязки).
                $ctx->id = isset($opts['category']) ? (int)$opts['category'] : (int)$kernel->db()->query("SELECT category_id FROM `" . $kernel->table('category_product') . "`"
                    . (empty($opts['subtree']) ? " WHERE is_direct = 1" : "") . " GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1")->row['category_id'];
            }
            $ctx->subtree = !empty($opts['subtree']);
            $ctx->languageId = $kernel->defaultLanguageId();
            $runs = isset($opts['n']) ? max(1, (int)$opts['n']) : 20;
            $defs = $kernel->facets()->snapshot($ctx->languageId)['facets'];

            $empty = $kernel->engine()->search($ctx, new \PsFilter\State(), ['sort' => 'p.sort_order', 'start' => 0, 'limit' => 20]);
            $out(sprintf('контекст %s: товаров %d, фасетов со значениями %d; пустое состояние %.1f мс (база %.1f, страница %.1f)',
                $ctx->scope(), $empty->totalBase, count($empty->baseCounts) + count($empty->baseRanges), $empty->timings['total'], $empty->timings['base'], $empty->timings['page']));

            mt_srand(7);
            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                // Случайное состояние: 1–3 фасета, в каждом 1–2 значения из тех, что есть в контексте.
                $sel = [];
                $facetIds = array_keys($empty->baseCounts);
                shuffle($facetIds);
                foreach (array_slice($facetIds, 0, mt_rand(1, 3)) as $fid) {
                    $vals = array_keys($empty->baseCounts[$fid]);
                    shuffle($vals);
                    $sel[$fid] = ['ids' => array_slice($vals, 0, mt_rand(1, 2))];
                }
                if (mt_rand(0, 1) && $empty->baseRanges) {
                    $fid = array_rand($empty->baseRanges);
                    list($mn, $mx) = $empty->baseRanges[$fid];
                    $sel[$fid] = ['min' => $mn + ($mx - $mn) * 0.2, 'max' => $mn + ($mx - $mn) * 0.7];
                }
                $state = new \PsFilter\State($sel);
                $r = $kernel->engine()->search($ctx, $state, ['sort' => 'p.price', 'order' => 'ASC', 'start' => 0, 'limit' => 20]);
                $times[] = $r->timings['total'];
                $codes = [];
                foreach ($state->all() as $fid => $s) {
                    $codes[] = $defs[$fid]['code'] . (isset($s['ids']) ? '×' . count($s['ids']) : '[]');
                }
                printf("  %-55s → %5d товаров, %6.1f мс (фильтр %.1f, страница %.1f)\n", implode(' ', $codes), $r->total, $r->timings['total'], isset($r->timings['filtered']) ? $r->timings['filtered'] : 0, isset($r->timings['page']) ? $r->timings['page'] : 0);
            }
            sort($times);
            $out(sprintf('клик: медиана %.1f мс, p90 %.1f мс, максимум %.1f мс', $times[(int)floor(count($times) / 2)], $times[(int)floor(count($times) * 0.9)], end($times)));
            break;

        case 'verify':
            // Сверка движка с наивным SQL: cli.php verify [--category=ID | --manufacturer=ID] [--subtree] [--n=30]
            // Для случайных состояний сравниваются total и счетчики каждого значения выбранных фасетов.
            $kernel->settings()->override(['cache_driver' => 'none']);
            $db = $kernel->db();
            $ctx = new \PsFilter\Context();
            if (!empty($opts['manufacturer'])) {
                $ctx->type = \PsFilter\Context::MANUFACTURER;
                $ctx->id = (int)$opts['manufacturer'];
            } else {
                $ctx->id = isset($opts['category']) ? (int)$opts['category'] : 1002;
            }
            $ctx->subtree = !empty($opts['subtree']);
            $ctx->languageId = $kernel->defaultLanguageId();
            $kernel->applyScope($ctx);
            $defs = $kernel->facets()->snapshot($ctx->languageId)['facets'];
            $runs = isset($opts['n']) ? (int)$opts['n'] : 30;
            // Группировка выдачи (group_by): движок считает группы — наивный SQL тоже.
            $count = $kernel->groupField() !== '' ? 'COUNT(DISTINCT x.grp)' : 'COUNT(*)';
            // Производитель — без исключенных разделов (manufacturer_exclude_categories): у товара в category_product
            // есть строки всех предков, поэтому достаточно проверить отмеченные id.
            $ctxSql = $ctx->type === \PsFilter\Context::MANUFACTURER
                ? "SELECT f.product_id, f.grp FROM `" . $kernel->table('product') . "` f
                    WHERE f.manufacturer_id = " . $ctx->id . " AND f.active = 1 AND f.date_available <= CURDATE() AND (f.store_mask & 1) <> 0"
                    . ($ctx->excludeCategories ? " AND f.product_id NOT IN (SELECT cx.product_id FROM `" . $kernel->table('category_product') . "` cx
                        WHERE cx.category_id IN (" . implode(',', $ctx->excludeCategories) . "))" : "")
                : "SELECT f.product_id, f.grp FROM `" . $kernel->table('category_product') . "` c JOIN `" . $kernel->table('product') . "` f ON f.product_id = c.product_id
                WHERE c.category_id = " . $ctx->id . ($ctx->subtree ? '' : ' AND c.is_direct = 1') . " AND f.active = 1 AND f.date_available <= CURDATE() AND (f.store_mask & 1) <> 0";
            // Наивное условие одного фасета на товар f.product_id.
            $cond = function ($fid, $sel) use ($kernel, $defs) {
                $facet = $defs[$fid];
                if ($facet['source'] === 'price') {
                    $c = [];
                    if ($sel['min'] !== null) { $c[] = "pr.price >= " . (float)$sel['min']; }
                    if ($sel['max'] !== null) { $c[] = "pr.price <= " . (float)$sel['max']; }
                    return "EXISTS (SELECT 1 FROM `" . $kernel->table('price') . "` pr WHERE pr.product_id = f.product_id AND pr.customer_group_id = 1" . ($c ? ' AND ' . implode(' AND ', $c) : '') . ")";
                }
                if ($facet['source'] === 'stock') {
                    return \PsFilter\Stock::sql($facet['settings'], 'f') . " IN (" . implode(',', $sel['ids']) . ")";
                }
                if ((int)$facet['kind'] === 1) {
                    $c = [];
                    if ($sel['min'] !== null) { $c[] = "r.value >= " . (float)$sel['min']; }
                    if ($sel['max'] !== null) { $c[] = "r.value <= " . (float)$sel['max']; }
                    return "EXISTS (SELECT 1 FROM `" . $kernel->table('index_range') . "` r WHERE r.product_id = f.product_id AND r.facet_id = " . (int)$fid . ($c ? ' AND ' . implode(' AND ', $c) : '') . ")";
                }
                return "EXISTS (SELECT 1 FROM `" . $kernel->table('index') . "` i WHERE i.product_id = f.product_id AND i.facet_id = " . (int)$fid . " AND i.value_id IN (" . implode(',', $sel['ids']) . "))";
            };
            $naive = function (\PsFilter\State $state, $extra = '') use ($db, $ctxSql, $cond, $count) {
                $w = [];
                foreach ($state->all() as $fid => $sel) {
                    $w[] = $cond($fid, $sel);
                }
                return (int)$db->query("SELECT " . $count . " AS c FROM (" . $ctxSql . ($w ? ' AND ' . implode(' AND ', $w) : '') . $extra . ") x")->row['c'];
            };
            $empty = $kernel->engine()->search($ctx, new \PsFilter\State());
            mt_srand(11);
            $errors = 0;
            $checks = 0;
            for ($i = 0; $i < $runs; $i++) {
                $sel = [];
                $facetIds = array_keys($empty->baseCounts);
                shuffle($facetIds);
                foreach (array_slice($facetIds, 0, mt_rand(1, 3)) as $fid) {
                    $vals = array_keys($empty->baseCounts[$fid]);
                    shuffle($vals);
                    $sel[$fid] = ['ids' => array_slice($vals, 0, mt_rand(1, 2))];
                }
                if (mt_rand(0, 1) && $empty->baseRanges) {
                    $fid = array_rand($empty->baseRanges);
                    list($mn, $mx) = $empty->baseRanges[$fid];
                    $sel[$fid] = ['min' => round($mn + ($mx - $mn) * 0.2, 2), 'max' => round($mn + ($mx - $mn) * 0.7, 2)];
                }
                $state = new \PsFilter\State($sel);
                $r = $kernel->engine()->search($ctx, $state, $kernel->groupField() !== '' ? ['sort' => 'p.price', 'order' => 'ASC', 'start' => 0, 'limit' => 20] : null);
                $want = $naive($state);
                $checks++;
                if ($want !== $r->total) {
                    $errors++;
                    $out("  ОШИБКА total: движок {$r->total}, SQL {$want} для " . json_encode($state->all()));
                }
                // Группировка: на странице — по одному товару на группу, и их столько, сколько групп влезает.
                if ($kernel->groupField() !== '') {
                    $checks++;
                    $ids = $r->productIds ? implode(',', array_map('intval', $r->productIds)) : '0';
                    $groups = (int)$db->query("SELECT COUNT(DISTINCT grp) AS c FROM `" . $kernel->table('product') . "` WHERE product_id IN (" . $ids . ")")->row['c'];
                    if ($groups !== count($r->productIds) || count($r->productIds) !== min(20, $r->total)) {
                        $errors++;
                        $out("  ОШИБКА страница: товаров " . count($r->productIds) . ", групп {$groups}, ожидалось " . min(20, $r->total));
                    }
                    // Представитель группы: из подходящих под фильтр товаров группы — в наличии, дешевле, меньший id.
                    $instock = 'f.quantity > 0';
                    foreach ($defs as $facet) {
                        if ($facet['source'] === 'stock') {
                            $instock = \PsFilter\Stock::sql($facet['settings'], 'f') . ' = ' . \PsFilter\Stock::IN_STOCK;
                            break;
                        }
                    }
                    $w = [];
                    foreach ($state->all() as $fid => $s) {
                        $w[] = $cond($fid, $s);
                    }
                    foreach ($r->productIds as $pid) {
                        $grp = $db->query("SELECT grp FROM `" . $kernel->table('product') . "` WHERE product_id = " . (int)$pid)->row['grp'];
                        // Раздел или производитель — тот же $ctxSql, что и для total.
                        $best = $db->query("SELECT f.product_id FROM (" . $ctxSql . ") b JOIN `" . $kernel->table('product') . "` f ON f.product_id = b.product_id
                            LEFT JOIN `" . $kernel->table('price') . "` pr ON pr.product_id = f.product_id AND pr.customer_group_id = 1
                            WHERE f.grp = '" . $db->escape($grp) . "'" . ($w ? ' AND ' . implode(' AND ', $w) : '') . "
                            ORDER BY (" . $instock . ") DESC, COALESCE(pr.price, 0) ASC, f.product_id ASC LIMIT 1")->row;
                        $checks++;
                        if (!$best || (int)$best['product_id'] !== (int)$pid) {
                            $errors++;
                            $out("  ОШИБКА представитель группы {$grp}: движок {$pid}, ожидался " . ($best ? $best['product_id'] : '—'));
                        }
                    }
                }
                // Счетчики значений выбранных списков: товаров при остальных фильтрах + это значение.
                foreach ($state->all() as $fid => $s) {
                    if (!isset($s['ids'])) {
                        continue;
                    }
                    foreach (array_slice(array_keys($empty->baseCounts[$fid]), 0, 4) as $vid) {
                        $want = $naive($state->without($fid)->with($fid, ['ids' => [$vid]]));
                        $got = isset($r->counts[$fid][$vid]) ? $r->counts[$fid][$vid] : 0;
                        $checks++;
                        if ($want !== $got) {
                            $errors++;
                            $out("  ОШИБКА счетчик {$defs[$fid]['code']}#{$vid}: движок {$got}, SQL {$want}");
                        }
                    }
                }
            }
            $out("проверок: $checks, ошибок: $errors");
            exit($errors ? 1 : 0);

        case 'layout':
            // cli.php layout [--list] — где стоит панель; cli.php layout --position=… — поставить в макеты
            // «Категория», «Производитель», «Поиск». Без --position макеты не меняются.
            if (!isset($opts['position'])) {
                $q = $kernel->db()->query("SELECT lm.layout_id, l.name, lm.position FROM `" . DB_PREFIX . "layout_module` lm
                    LEFT JOIN `" . DB_PREFIX . "layout` l ON l.layout_id = lm.layout_id
                    WHERE lm.code = '" . $kernel->db()->escape(\PsFilter\Schema::layoutCode()) . "' ORDER BY lm.layout_id, lm.position");
                foreach ($q->rows as $r) {
                    $out("макет {$r['layout_id']} ({$r['name']}): {$r['position']}");
                }
                $out($q->num_rows ? '' : 'панель не стоит ни в одном макете');
                $out('поставить: cli.php layout --position=column_left|column_right|content_top|content_bottom');
                break;
            }
            $out($kernel->schema()->addToLayouts($opts['position']));
            break;

        case 'regroup':
            // Ключи групп выдачи (group_by) заново — после смены поля в обход админки или правки артикулов SQL.
            $t = microtime(true);
            $kernel->syncGroups(true);
            $r = $kernel->db()->query("SELECT COUNT(*) AS n, COUNT(DISTINCT grp) AS g FROM `" . $kernel->table('product') . "`")->row;
            $out(sprintf('группировка: %s, товаров %d, групп %d, %.0f мс', $kernel->groupField() ?: 'выключена', $r['n'], $r['g'], (microtime(true) - $t) * 1000));
            break;

        case 'sold':
            // Продажи за период сейчас (обычно — сами раз в сутки при первом запросе выдачи).
            $sales = $kernel->sales();
            $r = $sales->refresh(true);
            if ($r === null) {
                $out('пересчет уже идет в другом процессе');
                break;
            }
            $out(sprintf('продажи за %d дн. (статусы заказов %s): товаров %d, штук %d, изменилось %d, %d мс; «Популярные» — %s',
                $r['days'], implode(',', $sales->statuses()) ?: 'не заданы', $r['products'], $r['units'], $r['changed'], $r['ms'],
                $sales->enabled() ? 'по продажам' : 'по просмотрам'));
            foreach ($kernel->db()->query("SELECT s.product_id, s.sold, pd.name FROM `" . $kernel->table('sold') . "` s
                    LEFT JOIN `" . DB_PREFIX . "product_description` pd ON pd.product_id = s.product_id
                        AND pd.language_id = (SELECT MIN(language_id) FROM `" . DB_PREFIX . "language` WHERE status = 1)
                    ORDER BY s.sold DESC, s.product_id LIMIT 10")->rows as $row) {
                $out(sprintf('  %5d  #%d %s', $row['sold'], $row['product_id'], html_entity_decode((string)$row['name'], ENT_QUOTES, 'UTF-8')));
            }
            break;

        case 'manticore':
            // cli.php manticore status | rebuild — состояние и полная пересборка таблицы Manticore.
            $m = $kernel->manticore();
            $action = $words ? $words[0] : 'status';
            if ($action === 'rebuild') {
                $t = microtime(true);
                $cursor = 0;
                do {
                    $cursor = $m->rebuildStep($cursor, 10.0);
                    printf("  [%5.1fs] %s\n", microtime(true) - $t, $cursor === null ? 'готово' : 'до товара ' . $cursor);
                } while ($cursor !== null);
            }
            $out(json_encode($m->status(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            break;

        case 'compare':
            // Сверка Manticore с MySQL: cli.php compare [--category=ID] [--manufacturer=ID] [--subtree] [--n=40]
            // Для случайных состояний сравниваются total, все счетчики, диапазоны и первые страницы при всех сортировках.
            if ($kernel->groupField() !== '') {
                // Manticore группы не считает: при group_by выборка идет через MySQL, сравнивать не с чем.
                $out('compare: включена группировка выдачи (group_by = ' . $kernel->groupField() . ') — выборка идет через MySQL, Manticore не используется');
                break;
            }
            $kernel->settings()->override(['cache_driver' => 'none', 'backend' => 'manticore']);
            $m = $kernel->manticore();
            if ($m->state() !== 'ready') {
                throw new \RuntimeException('таблица Manticore не готова (' . ($m->state() ?: 'не строилась') . '): cli.php manticore rebuild');
            }
            $mysql = $kernel->mysqlEngine();
            $mt = new \PsFilter\Engine\Manticore\Engine($kernel, $m, $mysql);
            $ctx = new \PsFilter\Context();
            if (!empty($opts['manufacturer'])) {
                $ctx->type = \PsFilter\Context::MANUFACTURER;
                $ctx->id = (int)$opts['manufacturer'];
            } else {
                $ctx->id = isset($opts['category']) ? (int)$opts['category'] : 1002;
            }
            $ctx->subtree = !empty($opts['subtree']);
            $ctx->languageId = $kernel->defaultLanguageId();
            if (!empty($opts['tax'])) {
                $ctx->taxRates = [9 => 1.2]; // проверка цены с налогом (класс 9 у части тестовых товаров)
            }
            $runs = isset($opts['n']) ? (int)$opts['n'] : 40;
            $diff = function ($what, $a, $b) use (&$errors) {
                if (json_encode($a) !== json_encode($b)) {
                    $errors++;
                    echo "  ≠ $what\n    mysql:     " . mb_substr(json_encode($a), 0, 300) . "\n    manticore: " . mb_substr(json_encode($b), 0, 300) . "\n";
                }
            };
            $norm = function ($result) {
                $c = $result->counts;
                ksort($c);
                foreach ($c as &$v) { ksort($v); $v = array_filter($v); }
                unset($v);
                $r = [];
                foreach ($result->ranges as $fid => $rg) { $r[$fid] = [round($rg[0], 4), round($rg[1], 4)]; }
                ksort($r);
                return ['total' => $result->total, 'counts' => array_filter($c), 'ranges' => $r];
            };
            $errors = 0;
            $empty = $mysql->search($ctx, new \PsFilter\State());
            $diff('раздел без фильтра', $norm($empty), $norm($mt->search($ctx, new \PsFilter\State())));
            mt_srand(23);
            $sorts = [['p.sort_order', 'ASC'], ['pd.name', 'ASC'], ['pd.name', 'DESC'], ['p.price', 'ASC'], ['p.price', 'DESC'], ['rating', 'DESC'], ['p.model', 'ASC'], ['p.date_added', 'DESC'], ['p.viewed', 'DESC'], ['popular', 'DESC']];
            $tm = ['mysql' => [], 'manticore' => []];
            for ($i = 0; $i < $runs; $i++) {
                $sel = [];
                $facetIds = array_keys($empty->baseCounts);
                shuffle($facetIds);
                foreach (array_slice($facetIds, 0, mt_rand(1, 3)) as $fid) {
                    $vals = array_keys($empty->baseCounts[$fid]);
                    shuffle($vals);
                    $sel[$fid] = ['ids' => array_slice($vals, 0, mt_rand(1, 2))];
                }
                if (mt_rand(0, 1) && $empty->baseRanges) {
                    $fid = array_rand($empty->baseRanges);
                    list($mn, $mx) = $empty->baseRanges[$fid];
                    $sel[$fid] = ['min' => round($mn + ($mx - $mn) * 0.2, 2), 'max' => round($mn + ($mx - $mn) * 0.7, 2)];
                }
                $state = new \PsFilter\State($sel);
                $sort = $sorts[$i % count($sorts)];
                $listing = ['sort' => $sort[0], 'order' => $sort[1], 'start' => mt_rand(0, 1) ? 0 : 20, 'limit' => 20];
                $a = $mysql->search($ctx, $state, $listing);
                $b = $mt->search($ctx, $state, $listing);
                $tm['mysql'][] = $a->timings['total'];
                $tm['manticore'][] = $b->timings['total'];
                $diff("#$i " . json_encode($sel), $norm($a), $norm($b));
                $diff("#$i страница " . implode(' ', $sort), $a->productIds, $b->productIds);
                $mysql->release();
            }
            foreach ($tm as $name => $t) {
                sort($t);
                $out(sprintf('%-10s медиана %.1f мс, p90 %.1f мс', $name, $t[(int)floor(count($t) / 2)], $t[(int)floor(count($t) * 0.9)]));
            }
            $out(sprintf('состояний: %d, расхождений: %d', $runs, $errors));
            exit($errors ? 1 : 0);

        default:
            fwrite(STDERR, "ps_filter: неизвестная команда «{$command}»\n" . $usage);
            exit(2);
    }
} catch (\Exception $e) {
    fwrite(STDERR, 'ps_filter: ' . $e->getMessage() . "\n");
    exit(1);
}
