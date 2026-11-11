<?php
namespace PsFilter\Index;

/**
 * Нормализация текстов значений: ключ, slug для URL, разбиение составных значений, числа.
 *
 * Реальные выгрузки «грязные»: «Красный, Синий», «S / M / L», «1,5 кг», кавычки-елочки,
 * html-сущности. Здесь все это приводится к стабильному ключу, по которому значение
 * узнается при каждой переиндексации (value_id не меняется → URL не ломаются).
 */
class Normalizer
{
    private static $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', "\u{0451}" => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'і' => 'i', 'ї' => 'yi', 'є' => 'e', 'ґ' => 'g', '+' => '-plus',
    ];

    /**
     * Очищенный текст: без html, сущностей и лишних пробелов.
     *
     * @param string $text
     * @return string
     */
    public static function clean($text)
    {
        $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Разбивает составное значение по разделителям.
     * Запятая между цифрами («1,5») разделителем не считается.
     *
     * @param string   $text
     * @param string[] $separators
     * @return string[] части в исходном регистре, без пустых и дублей
     */
    public static function split($text, array $separators)
    {
        $text = self::clean($text);
        if ($text === '') {
            return [];
        }
        $separators = array_values(array_filter($separators, 'strlen'));
        if (!$separators) {
            return [$text];
        }
        // Защищаем десятичную запятую на время разбиения.
        $text = preg_replace('/(\d),(\d)/u', "$1\x01$2", $text);
        $parts = preg_split('/' . implode('|', array_map(function ($s) {
            return preg_quote($s, '/');
        }, $separators)) . '/u', $text);

        $out = [];
        foreach ($parts as $part) {
            $part = trim(str_replace("\x01", ',', $part));
            if ($part === '') {
                continue;
            }
            $key = self::key($part);
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = $part;
            }
        }
        return array_values($out);
    }

    /**
     * Стабильный ключ значения: по нему значение узнается между переиндексациями.
     *
     * @param string $text
     * @return string
     */
    public static function key($text)
    {
        $text = mb_strtolower(self::clean($text), 'UTF-8');
        $text = str_replace(['«', '»', '"', "'", '`', '„', '“', '”'], '', $text);
        $text = preg_replace('/(\d),(\d)/u', '$1.$2', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_substr(trim($text, " .\t"), 0, 191, 'UTF-8');
    }

    /**
     * Slug для URL: латиница, цифры и дефис.
     *
     * @param string $text
     * @param int    $max
     * @return string
     */
    public static function slug($text, $max = 100)
    {
        $text = mb_strtolower(self::clean($text), 'UTF-8');
        $text = preg_replace('/(\d)[.,](\d)/u', '$1-$2', $text);
        $text = strtr($text, self::$translit);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = strtolower($converted);
            }
        }
        $text = trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
        $text = rtrim(substr($text, 0, $max), '-');
        return $text === '' ? 'v' : $text;
    }

    /**
     * Первое число в тексте («1,5 кг» → 1.5, «-20…+40 °C» → -20).
     *
     * @param string $text
     * @return float|null
     */
    public static function number($text)
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/u', self::clean($text), $m)) {
            return (float)str_replace(',', '.', $m[0]);
        }
        return null;
    }
}
