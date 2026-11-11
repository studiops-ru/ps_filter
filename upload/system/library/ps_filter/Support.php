<?php
namespace PsFilter;

class Support
{
    const MAIL = 'info@studiops.ru';
    const SITE = 'https://studiops.ru/modules/';
    const SITE_TEXT = 'studiops.ru/modules/';
    const RECENT = 5;

    // Подписи сведений для поддержки, если языкового файла набора нет (вызов из cli, другой язык без файла)
    const LABELS = [
        'support_info_theme'   => 'Тема',
        'support_info_site'    => 'Сайт',
        'support_info_modules' => 'Модули студии',
        'support_info_none'    => 'нет',
        'support_info_state'   => 'Состояние модуля',
        'support_info_errors'  => 'Последние ошибки модуля',
    ];

    private $db;
    private $prefix;
    private $module;
    private $language = 'ru-ru';
    private $labels = null;
    private $list = null;

    public function __construct($db, string $prefix, array $module)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->module = $module + ['code' => '', 'name' => '', 'version' => '', 'dir' => '', 'state' => '', 'errors' => ''];
    }

    public function brief(): array
    {
        $m = $this->module;
        return [
            'code'    => $m['code'],
            'name'    => $m['name'],
            'version' => $m['version'],
            'title'   => trim($m['name'] . ' ' . $m['version']),
            'license' => $this->license(),
            'mail'    => self::MAIL,
            'site'    => self::SITE_TEXT,
            'url'     => [
                'support' => $this->url('support'),
                'custom'  => $this->url('custom'),
                'partner' => $this->url('partner'),
                'modules' => $this->url('modules'),
                'footer'  => $this->url('footer'),
            ],
        ];
    }

    public function data(string $language = 'ru-ru', array $extra = []): array
    {
        $this->module = array_replace($this->module, array_intersect_key($extra, ['state' => 1, 'errors' => 1]));
        if ($language !== $this->language) {
            $this->language = $language;
            $this->labels = null;
        }
        $changes = $this->changes();
        $date = '';
        foreach ($changes as $c) {
            if ($c['version'] === $this->module['version']) {
                $date = $c['date'];
                break;
            }
        }
        $modules = $this->modules($language);
        return $this->brief() + [
            'date'    => $date,
            'recent'  => array_slice($changes, 0, self::RECENT),
            'older'   => array_slice($changes, self::RECENT),
            'modules' => $modules,
            'info'    => $this->info($date, $modules),
        ];
    }

    public function changes(): array
    {
        $file = $this->file('CHANGELOG.md');
        if ($file === '') {
            return [];
        }
        $out = [];
        $current = null;
        foreach (preg_split('/\r\n|\r|\n/', (string)file_get_contents($file)) as $line) {
            if (preg_match('/^##\s+v?([0-9][0-9A-Za-z.\-]*)\s*(?:\(([^)]*)\))?\s*$/u', $line, $m)) {
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = ['version' => $m[1], 'date' => isset($m[2]) ? trim($m[2]) : '', 'items' => []];
                continue;
            }
            if ($current !== null && preg_match('/^\s*(?:[-*]|•)\s+(.+)$/u', $line, $m)) {
                $current['items'][] = trim($m[1]);
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }
        return $out;
    }

    /* Лицензия модуля из modules.json набора (поле license), по умолчанию MIT; у PS Russian — GPL-3.0. */
    public function license(): string
    {
        $list = $this->catalog();
        foreach (isset($list['modules']) && is_array($list['modules']) ? $list['modules'] : [] as $m) {
            if (isset($m['code']) && $m['code'] === $this->module['code'] && !empty($m['license'])) {
                return (string)$m['license'];
            }
        }
        return 'MIT';
    }

    private function catalog()
    {
        if ($this->list === null) {
            $file = $this->file('modules.json');
            $this->list = $file !== '' ? json_decode((string)file_get_contents($file), true) : false;
        }
        return $this->list;
    }

    public function modules(string $language = 'ru-ru'): array
    {
        $list = $this->catalog();
        if (!is_array($list) || !isset($list['modules']) || !is_array($list['modules'])) {
            return [];
        }
        $codes = [];
        foreach ($list['modules'] as $m) {
            if (!isset($m['code'], $m['name'], $m['title'])) {
                continue;
            }
            foreach (array_merge([$m['code']], isset($m['aliases']) ? (array)$m['aliases'] : []) as $c) {
                $codes[(string)$c] = (string)$m['code'];
            }
        }
        $installed = $this->installed($codes);
        $english = strncmp($language, 'en', 2) === 0;
        $out = [];
        foreach ($list['modules'] as $m) {
            if (!isset($m['code'], $m['name'], $m['title']) || $m['code'] === $this->module['code']) {
                continue;
            }
            $out[] = [
                'code'      => (string)$m['code'],
                'name'      => (string)$m['name'],
                'title'     => $english && !empty($m['title_en']) ? (string)$m['title_en'] : (string)$m['title'],
                'installed' => array_key_exists($m['code'], $installed),
                'version'   => isset($installed[$m['code']]) ? $installed[$m['code']] : '',
            ];
        }
        return $out;
    }

    public function info(string $date = '', ?array $modules = null): string
    {
        $m = $this->module;
        $lines = [trim($m['name'] . ' ' . $m['version'] . ($date !== '' ? ' (' . $date . ')' : ''))];
        $lines[] = 'OpenCart ' . (defined('VERSION') ? VERSION : '?') . ', PHP ' . PHP_VERSION . $this->database();
        $theme = $this->theme();
        if ($theme !== '') {
            $lines[] = $this->label('support_info_theme') . ': ' . $theme;
        }
        $host = $this->host();
        if ($host !== '') {
            $lines[] = $this->label('support_info_site') . ': ' . $host;
        }
        $own = [];
        foreach ($modules === null ? $this->modules() : $modules as $x) {
            if ($x['installed']) {
                $own[] = $x['code'] . ($x['version'] !== '' ? ' ' . $x['version'] : '');
            }
        }
        $lines[] = $this->label('support_info_modules') . ': ' . ($own ? implode(', ', $own) : $this->label('support_info_none'));
        if ($m['state'] !== '') {
            $lines[] = $this->label('support_info_state') . ': ' . $m['state'];
        }
        if ($m['errors'] !== '') {
            $lines[] = $this->label('support_info_errors') . ': ' . $m['errors'];
        }
        return implode("\n", $lines);
    }

    /* Подпись строки сведений на языке админки — из языкового файла набора extension/<код>/support
       (тот же, что у вкладки), без участия контроллера; нет файла или строки — по-русски. */
    private function label(string $key): string
    {
        if ($this->labels === null) {
            $this->labels = self::LABELS;
            $lang = preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/i', $this->language) ? $this->language : 'ru-ru';
            $code = basename($this->module['code']);
            // OC4: файл расширения — extension/<код>/admin/language/<яз>/support.php (так раскладывает oc4-kit);
            // OC3: admin/language/<яз>/extension/<код>/support.php
            $files = [];
            if (defined('DIR_EXTENSION')) {
                $files[] = DIR_EXTENSION . $code . '/admin/language/' . $lang . '/support.php';
            }
            if (defined('DIR_LANGUAGE')) {
                $files[] = DIR_LANGUAGE . $lang . '/extension/' . $code . '/support.php';
            }
            $file = '';
            foreach ($files as $f) {
                if (is_file($f)) {
                    $file = $f;
                    break;
                }
            }
            if ($file !== '') {
                $read = function ($file) {
                    $_ = [];
                    include $file;
                    return $_;
                };
                foreach ((array)$read($file) as $k => $v) {
                    if (isset(self::LABELS[$k]) && is_string($v) && $v !== '') {
                        $this->labels[$k] = $v;
                    }
                }
            }
        }
        return isset($this->labels[$key]) ? $this->labels[$key] : $key;
    }

    public function url(string $campaign): string
    {
        return self::SITE . '?utm_source=' . rawurlencode($this->module['code']) . '&utm_medium=admin&utm_campaign=' . rawurlencode($campaign);
    }

    private function installed(array $codes): array
    {
        $out = [];
        if (!$codes) {
            return $out;
        }
        $in = implode(',', array_map(function ($c) {
            return "'" . $this->db->escape((string)$c) . "'";
        }, array_keys($codes)));
        foreach ($this->db->query("SELECT DISTINCT `code` FROM `" . $this->prefix . "extension` WHERE `code` IN (" . $in . ")")->rows as $r) {
            $out[$codes[$r['code']]] = '';
        }
        $keys = implode(',', array_map(function ($c) {
            return "'" . $this->db->escape($c . '_version') . "'";
        }, array_keys($codes)));
        foreach ($this->db->query("SELECT `key`, `value` FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `key` IN (" . $keys . ")")->rows as $r) {
            $code = substr((string)$r['key'], 0, -strlen('_version'));
            if (isset($codes[$code])) {
                $out[$codes[$code]] = (string)$r['value'];
            }
        }
        return $out;
    }

    private function database(): string
    {
        try {
            $v = (string)$this->db->query("SELECT VERSION() AS v")->row['v'];
        } catch (\Throwable $e) {
            return '';
        }
        if ($v === '') {
            return '';
        }
        if (stripos($v, 'mariadb') !== false) {
            return ', MariaDB ' . preg_replace('/-.*$/', '', $v);
        }
        return ', MySQL ' . preg_replace('/-.*$/', '', $v);
    }

    private function theme(): string
    {
        $code = $this->setting('config_theme');
        if ($code === '') {
            return '';
        }
        $dir = $this->setting('theme_' . $code . '_directory');
        return $dir !== '' ? $dir : $code;
    }

    private function setting(string $key): string
    {
        $q = $this->db->query("SELECT `value` FROM `" . $this->prefix . "setting` WHERE store_id = 0 AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");
        return $q->num_rows ? (string)$q->row['value'] : '';
    }

    private function host(): string
    {
        $base = defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
        $host = $base !== '' ? parse_url($base, PHP_URL_HOST) : '';
        return is_string($host) ? $host : '';
    }

    private function file(string $name): string
    {
        $path = rtrim((string)$this->module['dir'], '/\\') . '/' . $name;
        return $this->module['dir'] !== '' && is_file($path) ? $path : '';
    }
}
