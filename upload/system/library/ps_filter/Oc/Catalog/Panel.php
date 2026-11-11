<?php
namespace PsFilter\Oc\Catalog;

use PsFilter\Oc\Base;
use PsFilter\Platform;

/**
 * Панель фильтра на витрине, cron и карта сайта.
 *
 * Панель выводится как модуль позиции макета (column_left и т.д.) или переменной {{ ps_filter }}.
 * Контекст и результат выборки к этому моменту уже посчитаны обработчиками событий страницы,
 * панель их только показывает.
 */
class Panel extends Base
{
    public function index($setting = [])
    {
        if (!$this->enabled()) {
            return '';
        }
        $kernel = $this->kernel();
        $runtime = $kernel->runtime();
        if (!$runtime->context) {
            return '';
        }

        $front = $kernel->storefront();
        $result = $kernel->engine()->search($runtime->context, $runtime->state);
        $state = $result->state ?: $runtime->state;
        $defs = $kernel->facets()->snapshot($runtime->context->languageId)['facets'];

        $overrides = $kernel->facets()->overrides($runtime->context);
        $data = $front->presenter()->panel($runtime->context, $state, $result, $defs, $front->listingQuery(), $overrides);
        // Подборки раздела (посадочные с флагом «в панели»): настоящие ссылки для людей и поисковиков.
        $data['seo_links'] = $front->landingLinks($runtime->context, $state, 'in_panel');
        if (!$data['facets'] && !$data['rest'] && !$data['selected'] && !$data['seo_links']) {
            return '';
        }

        // «Все фильтры»: фасеты, не попавшие в автоподбор, приходят в панели только по запросу скрипта
        // (заголовок X-PSF-Rest: 1 — кнопку нажали, или список уже открыт). Со страницей — только кнопка
        // с их числом: в крупном разделе это десятки фасетов и сотни значений на каждый клик.
        $settings = $kernel->settings();
        $data['rest_mode'] = (string)$settings->get('auto_rest', 'drop');
        $data['rest_count'] = count($data['rest']);
        $data['rest_open'] = $data['rest_count'] && isset($this->request->server['HTTP_X_PSF_REST']) && $this->request->server['HTTP_X_PSF_REST'] === '1';
        if (!$data['rest_open']) {
            $data['rest'] = [];
        }

        $this->lang();
        list($data['tpl'], $data['tpl_default']) = $front->templateDirs();

        // Картинки значений (виджет image, картинки опций) — уменьшенные копии штатным ресайзом.
        $this->load->model('tool/image');
        foreach (['facets', 'rest'] as $list) {
            foreach ($data[$list] as &$facet) {
                foreach ($facet['values'] as &$value) {
                    $value['thumb'] = $value['image'] && is_file(DIR_IMAGE . $value['image']) ? $this->model_tool_image->resize($value['image'], 48, 48) : '';
                }
                unset($value);
            }
            unset($facet);
        }

        // Числа диапазонов — одним видом везде (плашка, поля, ползунок): разделители из языка магазина
        // (decimal_point, thousand_point — те же, что у цен). Шаблонам — r.point / r.thousand и готовый
        // текст плашек from_text/to_text (from/to остаются числами), скрипту — point/thousand в конфиге.
        $point = (string)$this->language->get('decimal_point');
        $thousand = (string)$this->language->get('thousand_point');
        $point = $point === 'decimal_point' || $point === '' ? '.' : $point;
        $thousand = $thousand === 'thousand_point' ? ' ' : $thousand;
        $text = function ($v, $decimals) use ($point, $thousand) {
            if ($v === '' || $v === null) {
                return '';
            }
            $s = number_format((float)$v, (int)$decimals, $point, $thousand);
            // «6.0» → «6», «1.50» → «1.5»: нули после запятой ничего не говорят.
            return strpos($s, $point) !== false ? rtrim(rtrim($s, '0'), $point) : $s;
        };
        foreach (['facets', 'rest'] as $list) {
            foreach ($data[$list] as &$facet) {
                if (!empty($facet['range'])) {
                    $r = &$facet['range'];
                    $r['point'] = $point;
                    $r['thousand'] = $thousand;
                    foreach (['from', 'to', 'avail_min', 'avail_max'] as $k) {
                        $r[$k . '_text'] = $text($r[$k], $r['decimals']);
                    }
                    unset($r);
                }
            }
            unset($facet);
        }
        foreach ($data['selected'] as &$tag) {
            foreach (['from', 'to'] as $edge) {
                $tag[$edge . '_text'] = $text($tag[$edge], isset($tag['decimals']) ? $tag['decimals'] : 0);
            }
        }
        unset($tag);

        $data['autosubmit'] = (int)$settings->get('autosubmit');
        $data['apply_text'] = sprintf($this->plural((int)$data['total'], $this->language->get('text_show_1'), $this->language->get('text_show_2'), $this->language->get('text_show_5')), (int)$data['total']);
        $data['config'] = json_encode([
            'ajax'             => (int)$settings->get('ajax'),
            'ajaxPagination'   => (int)$settings->get('ajax_pagination'),
            'autosubmit'       => (int)$settings->get('autosubmit'),
            'popover'          => (int)$settings->get('apply_popover'),
            'selectors'        => (array)$settings->get('selectors'),
            'fallbackReload'   => (int)$settings->get('fallback_reload'),
            'mobileBreakpoint' => (int)$settings->get('mobile_breakpoint'),
            'drawerSide'       => (string)$settings->get('drawer_side', 'left'),
            'rest'             => $data['rest_mode'],
            'point'            => $point,
            'thousand'         => $thousand,
            'texts'            => ['open' => $this->language->get('text_open'), 'close' => $this->language->get('text_close'),
                'popover' => $this->language->get('text_popover'), 'popoverNone' => $this->language->get('text_popover_none'),
                'found' => $this->language->get('text_found'), 'all' => $this->language->get('text_rest')],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Переопределения токенов оформления из настроек: --psf-accent и т.п.
        $vars = [];
        foreach ((array)$settings->get('css_vars') as $name => $value) {
            if (preg_match('/^--psf-[a-z0-9-]+$/', $name) && preg_match('/^[#(),.%\w\s-]+$/', (string)$value)) {
                $vars[] = $name . ':' . $value;
            }
        }
        $data['style'] = implode(';', $vars);

        $html = Platform::oc4() || self::twigIncludes() ? $this->view('panel', $data) : $this->renderOc3('panel', $data);
        return $this->early((int)$settings->get('mobile_breakpoint')) . $html;
    }

    /**
     * Умеет ли обертка Twig ядра OC3 находить вложенные шаблоны ({% include %}): в 3.0.3.x и сборках с поиском по
     * папке шаблонов — да, в 3.0.5.x — нет (Template\Twig грузит только сам шаблон, ArrayLoader), и панель с
     * include фасетов и виджетов не выводилась.
     *
     * @return bool
     */
    private static function twigIncludes()
    {
        static $ok = null;
        if ($ok === null) {
            $file = DIR_SYSTEM . 'library/template/twig.php';
            $ok = !is_file($file) || strpos((string)file_get_contents($file), 'FilesystemLoader') !== false;
        }
        return $ok;
    }

    /**
     * Шаблон модуля через свое окружение Twig с поиском вложенных шаблонов по папке тем (OC3 без такого поиска).
     * Повторяет штатное: шаблон темы, если есть, иначе default, правка из редактора тем (design/theme),
     * строки загруженных языковых файлов в данных (событие event/language).
     *
     * @return string
     */
    private function renderOc3($name, array $data)
    {
        list($dir, $dirDefault) = $this->kernel()->storefront()->templateDirs();
        $route = is_file(DIR_TEMPLATE . $dir . $name . '.twig') ? $dir . $name : $dirDefault . $name;
        $theme = substr($route, 0, strpos($route, '/'));
        $this->load->model('design/theme');
        $info = $this->model_design_theme->getTheme(substr($route, strlen($theme . '/template/')), $theme);
        $code = $info ? html_entity_decode($info['code'], ENT_QUOTES, 'UTF-8') : (string)file_get_contents(DIR_TEMPLATE . $route . '.twig');
        foreach ($this->language->all() as $key => $value) {
            if (!isset($data[$key])) {
                $data[$key] = $value;
            }
        }
        $loader = new \Twig\Loader\ChainLoader([
            new \Twig\Loader\ArrayLoader([$route . '.twig' => $code]),
            new \Twig\Loader\FilesystemLoader([DIR_TEMPLATE]),
        ]);
        $twig = new \Twig\Environment($loader, ['autoescape' => false, 'debug' => false, 'auto_reload' => true, 'cache' => DIR_CACHE . 'template/']);
        return $twig->render($route . '.twig', $data);
    }

    /**
     * Без сдвига верстки на телефоне (CLS): на ширине шторки скрипт уносит панель из колонки в шторку уже после
     * первой отрисовки, и выдача прыгает вверх на высоту панели. Метка psf-js на <html> ставится сразу (до панели),
     * и с ней панель на этой ширине не занимает места, пока скрипт не перенесет ее и не поставит psf-ready.
     * Без JS метки нет — панель на месте и работает ссылками. Скрипт не запустился (ошибка, отложенная загрузка
     * оптимизатором) — psf-ready ставится по load, панель возвращается в колонку, как было до 0.7.8.
     * Выводится перед панелью, а не в шаблоне: переопределения panel.twig в темах получают это без правок.
     *
     * @return string
     */
    private function early($breakpoint)
    {
        if ($breakpoint <= 0) {
            return ''; // шторка выключена — панель остается там, где ее вывела тема
        }
        return '<script>(function(c){c.add("psf-js");window.addEventListener("load",function(){setTimeout(function(){c.add("psf-ready")},0)})})(document.documentElement.classList)</script>'
            . '<style>@media (max-width:' . ($breakpoint - 1) . 'px){html.psf-js:not(.psf-ready) [data-psf]{display:none}}</style>';
    }

    /**
     * Форма слова для числа: 1 товар, 2 товара, 5 товаров (для английского 2 и 5 совпадают).
     *
     * @return string
     */
    private function plural($n, $one, $few, $many)
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        return $n1 === 1 ? $one : $many;
    }

    /**
     * Cron по HTTP: index.php?route=<модуль>/cron&key=…&task=reindex|reconcile|pages|prices|warm|manticore|gc
     * (OC3 — extension/module/ps_filter/cron, OC4 — extension/ps_filter/module/ps_filter.cron)
     *
     * reindex   — продолжает (или начинает) полную переиндексацию, работает до ~50 с;
     *             незаконченный job продолжится следующим вызовом.
     * reconcile — догоняет товары, измененные в обход админки (импорт): новые, удаленные,
     *             с date_modified, отличным от запомненного при индексации. Раз в час или после импорта.
     * prices    — пересчет цен по датам акций и скидок (раз в сутки ночью).
     * pages     — пересчет посадочных и автостраниц SEO-шаблонов.
     * warm      — прогрев кеша крупных разделов (после reindex/prices).
     * manticore — пересборка таблицы Manticore (после сбоя сервера; при включенном backend = manticore).
     * gc        — удаление просроченных записей кеша.
     */
    public function cron()
    {
        $kernel = $this->kernel();
        $key = (string)$kernel->settings()->get('cron_key');
        $given = isset($this->request->get['key']) ? (string)$this->request->get['key'] : '';
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('X-Robots-Tag: noindex');

        if ($key === '' || !hash_equals($key, $given)) {
            $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
            $this->response->setOutput("forbidden\n");
            return;
        }
        @set_time_limit(120);
        if ((int)$this->config->get('module_ps_filter_db_version') < \PsFilter\Schema::DB_VERSION) {
            $kernel->schema()->upgrade(); // файлы модуля обновили, а в админку еще не заходили
        }
        $task = isset($this->request->get['task']) ? (string)$this->request->get['task'] : 'reindex';
        $out = [];
        switch ($task) {
            case 'reindex':
                $job = $kernel->job()->running() ?: $kernel->job()->start();
                $until = microtime(true) + 50;
                while ($job['status'] === 'running' && microtime(true) < $until) {
                    $job = $kernel->job()->step(10.0);
                    if ($kernel->job()->lockBusy()) {
                        $out[] = 'job busy: шаг уже идет в другом процессе';
                        break;
                    }
                }
                $out[] = sprintf('job %d: %s, %d/%d', $job['job_id'], $job['status'], $job['done'], $job['total']);
                break;
            case 'pages':
                // Число товаров посадочных и автостраницы SEO-шаблонов (без полной переиндексации).
                $kernel->autoPages()->runAll();
                $out[] = 'pages: ok';
                break;
            case 'prices':
                $kernel->indexer()->writePrices('1');
                $kernel->cache()->flush();
                $out[] = 'prices: ok';
                // «Популярные» по продажам — здесь же, ночью, а не в запросе первого посетителя за сутки.
                if ($kernel->sales()->enabled()) {
                    $sold = $kernel->sales()->refresh(true, true);
                    $out[] = $sold ? sprintf('sold: %d товаров, %d мс', $sold['products'], $sold['ms']) : 'sold: занято другим процессом';
                }
                break;
            case 'gc':
                $kernel->cache()->gc();
                $out[] = 'gc: ok';
                break;
            case 'reconcile':
                $r = $kernel->indexer()->reconcile(50.0);
                $out[] = sprintf('reconcile: indexed %d, deleted %d%s', $r['indexed'], $r['deleted'], $r['more'] ? ', more — run again' : '');
                break;
            case 'warm':
                $out[] = $this->warm($kernel, 50.0);
                break;
            case 'manticore':
                // Пересборка таблицы Manticore (если включен): до ~50 с, следующий вызов продолжит.
                if (!$kernel->manticore()->enabled()) {
                    $out[] = 'manticore: disabled';
                    break;
                }
                try {
                    $cursor = $kernel->manticore()->rebuildFor(50.0);
                    $out[] = $cursor === null ? 'manticore: ready, docs ' . $kernel->manticore()->index()->count() : 'manticore: building, at product ' . $cursor . ' — run again';
                } catch (\RuntimeException $e) {
                    $kernel->manticore()->setState('stale');
                    $out[] = 'manticore: error — ' . $e->getMessage();
                }
                break;
            default:
                $out[] = 'unknown task';
        }
        $this->response->setOutput(implode("\n", $out) . "\n");
    }

    /**
     * Прогрев: базовая статистика (счетчики без фильтра) крупных категорий и брендов для гостя.
     * После переиндексации или сброса кеша первый посетитель раздела не ждет пересчета.
     *
     * @return string
     */
    private function warm(\PsFilter\Kernel $kernel, $timeLimit)
    {
        $until = microtime(true) + $timeLimit;
        $front = $kernel->storefront();
        $db = $kernel->db();
        $direct = $kernel->settings()->get('scope_subtree') ? '' : ' WHERE is_direct = 1';
        $scopes = [];
        foreach ($db->query("SELECT category_id AS id FROM `" . $kernel->table('category_product') . "`" . $direct . " GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 300")->rows as $r) {
            $scopes[] = [\PsFilter\Seo\PageRepository::SCOPE_CATEGORY, (int)$r['id']];
        }
        foreach ($db->query("SELECT manufacturer_id AS id FROM `" . $kernel->table('product') . "` WHERE manufacturer_id > 0 AND active = 1 GROUP BY manufacturer_id ORDER BY COUNT(*) DESC LIMIT 100")->rows as $r) {
            $scopes[] = [\PsFilter\Seo\PageRepository::SCOPE_MANUFACTURER, (int)$r['id']];
        }
        $done = 0;
        foreach ($scopes as $s) {
            if (microtime(true) > $until) {
                break;
            }
            $kernel->engine()->search($front->guestContext($s[0], $s[1]), new \PsFilter\State());
            $kernel->engine()->release();
            $done++;
        }
        return sprintf('warm: %d/%d', $done, count($scopes));
    }

    /**
     * Карта сайта посадочных: index.php?route=<модуль>/sitemap[&part=N]
     * До 10 000 адресов — обычный urlset; больше — sitemapindex со ссылками на части.
     */
    public function sitemap()
    {
        $kernel = $this->kernel();
        $this->response->addHeader('Content-Type: application/xml; charset=utf-8');
        $ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        if (!$this->enabled()) {
            $this->response->setOutput($xml . '<urlset xmlns="' . $ns . '"></urlset>');
            return;
        }
        $per = 10000;
        $total = $kernel->sitemap()->count();
        $part = isset($this->request->get['part']) ? max(1, (int)$this->request->get['part']) : 0;

        if (!$part && $total > $per) {
            $xml .= '<sitemapindex xmlns="' . $ns . '">';
            for ($i = 1, $n = (int)ceil($total / $per); $i <= $n; $i++) {
                $loc = str_replace('&amp;', '&', $this->url->link($this->route('base', 'sitemap'), 'part=' . $i));
                $xml .= '<sitemap><loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc></sitemap>';
            }
            $this->response->setOutput($xml . '</sitemapindex>');
            return;
        }

        $xml .= '<urlset xmlns="' . $ns . '">';
        foreach ($kernel->sitemap()->entries(($part ? $part - 1 : 0) * $per, $per) as $e) {
            $xml .= '<url><loc>' . htmlspecialchars($e['loc'], ENT_XML1, 'UTF-8') . '</loc><lastmod>' . $e['lastmod']
                . '</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>';
        }
        $this->response->setOutput($xml . '</urlset>');
    }
}
