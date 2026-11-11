<?php
namespace PsFilter\Index;

use PsFilter\Kernel;

/**
 * Мастер «Создать фасеты из данных магазина».
 *
 * Создает стандартные фасеты (цена, производитель, наличие) и по фасету на каждый атрибут,
 * опцию (select/radio/checkbox/image) и группу штатных фильтров, у которых есть товары.
 * Уже существующие фасеты не трогает. Числовые атрибуты («1,5 кг», «1200 Вт») распознает
 * сам и делает слайдером-диапазоном.
 */
class FacetWizard
{
    /** @var Kernel */
    private $k;

    public function __construct(Kernel $kernel)
    {
        $this->k = $kernel;
    }

    /**
     * @param array $only какие группы создавать: standard, attribute, option, oc_filter
     * @return array созданные фасеты: [code => name]
     */
    public function run(array $only = ['standard', 'attribute', 'option', 'oc_filter'])
    {
        $created = [];
        $db = $this->k->db();
        $P = $this->k->prefix();
        $langs = array_map('intval', array_column($db->query("SELECT language_id FROM `{$P}language`")->rows, 'language_id'));

        if (in_array('standard', $only, true)) {
            $std = [
                ['price', 'price', 0, 1, 'range', 0, ['Цена', 'Price']],
                ['brand', 'manufacturer', 0, 0, 'checkbox', 1, ['Производитель', 'Brand']],
                ['stock', 'stock', 0, 2, 'checkbox', 2, ['Наличие', 'Availability']],
            ];
            foreach ($std as $s) {
                if ($this->exists($s[1], $s[2])) {
                    continue;
                }
                $names = [];
                foreach ($langs as $l) {
                    $names[$l] = $this->isRussian($l) ? $s[6][0] : $s[6][1];
                }
                $this->create($s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $names, []);
                $created[$s[0]] = $s[6][0];
            }
        }

        if (in_array('attribute', $only, true)) {
            $q = $db->query("SELECT a.attribute_id, a.sort_order, COUNT(DISTINCT pa.product_id) AS products
                FROM `{$P}attribute` a JOIN `{$P}product_attribute` pa ON pa.attribute_id = a.attribute_id
                GROUP BY a.attribute_id, a.sort_order ORDER BY a.sort_order, a.attribute_id");
            foreach ($q->rows as $i => $row) {
                $id = (int)$row['attribute_id'];
                if ($this->exists('attribute', $id)) {
                    continue;
                }
                $names = $this->names("SELECT language_id, name FROM `{$P}attribute_description` WHERE attribute_id = " . $id);
                $numeric = $this->looksNumeric($id);
                $code = $this->create($this->code(reset($names)), 'attribute', $id, $numeric ? 1 : 0, $numeric ? 'range' : 'checkbox', 10 + $i, $names, []);
                $created[$code] = reset($names) . ($numeric ? ' (диапазон)' : '');
            }
        }

        if (in_array('option', $only, true)) {
            $q = $db->query("SELECT o.option_id, o.sort_order FROM `{$P}option` o
                WHERE o.type IN ('select', 'radio', 'checkbox', 'image')
                  AND EXISTS (SELECT 1 FROM `{$P}product_option_value` pov WHERE pov.option_id = o.option_id)
                ORDER BY o.sort_order, o.option_id");
            foreach ($q->rows as $i => $row) {
                $id = (int)$row['option_id'];
                if ($this->exists('option', $id)) {
                    continue;
                }
                $names = $this->names("SELECT language_id, name FROM `{$P}option_description` WHERE option_id = " . $id);
                $code = $this->create($this->code(reset($names)), 'option', $id, 0, 'checkbox', 100 + $i, $names, []);
                $created[$code] = reset($names);
            }
        }

        if (in_array('oc_filter', $only, true)) {
            $q = $db->query("SELECT fg.filter_group_id, fg.sort_order FROM `{$P}filter_group` fg
                WHERE EXISTS (SELECT 1 FROM `{$P}filter` f JOIN `{$P}product_filter` pf ON pf.filter_id = f.filter_id WHERE f.filter_group_id = fg.filter_group_id)
                ORDER BY fg.sort_order, fg.filter_group_id");
            foreach ($q->rows as $i => $row) {
                $id = (int)$row['filter_group_id'];
                if ($this->exists('oc_filter', $id)) {
                    continue;
                }
                $names = $this->names("SELECT language_id, name FROM `{$P}filter_group_description` WHERE filter_group_id = " . $id);
                $code = $this->create($this->code(reset($names)), 'oc_filter', $id, 0, 'checkbox', 200 + $i, $names, []);
                $created[$code] = reset($names);
            }
        }

        $this->k->facets()->invalidate();
        return $created;
    }

    /**
     * Атрибут числовой, если не меньше 90 % значений в выборке начинаются с числа
     * и различных чисел достаточно, чтобы слайдер имел смысл.
     */
    private function looksNumeric($attributeId)
    {
        $q = $this->k->db()->query("SELECT `text` FROM `" . $this->k->prefix() . "product_attribute`
            WHERE attribute_id = " . (int)$attributeId . " AND language_id = " . $this->k->defaultLanguageId() . " LIMIT 500");
        if ($q->num_rows < 10) {
            return false;
        }
        $numeric = 0;
        $distinct = [];
        foreach ($q->rows as $r) {
            $text = Normalizer::clean($r['text']);
            if (preg_match('/^-?\d+(?:[.,]\d+)?(?:\s*[^\d,\/;]{0,12})?$/u', $text)) {
                $numeric++;
                $distinct[(string)Normalizer::number($text)] = true;
            }
        }
        return $numeric / $q->num_rows >= 0.9 && count($distinct) >= 8;
    }

    /** @return bool */
    private function exists($source, $refId)
    {
        return (bool)$this->k->db()->query("SELECT facet_id FROM `" . $this->k->table('facet') . "` WHERE source = '" . $this->k->db()->escape($source) . "' AND ref_id = " . (int)$refId . " LIMIT 1")->num_rows;
    }

    /**
     * Уникальный код фасета (он же сегмент URL): транслит названия.
     *
     * @param string $name
     * @return string
     */
    private function code($name)
    {
        $base = Normalizer::slug($name, 40);
        $code = $base;
        for ($i = 2; $this->k->db()->query("SELECT 1 FROM `" . $this->k->table('facet') . "` WHERE code = '" . $this->k->db()->escape($code) . "'")->num_rows; $i++) {
            $code = $base . '-' . $i;
        }
        return $code;
    }

    /**
     * @return string код созданного фасета
     */
    private function create($code, $source, $refId, $kind, $widget, $sort, array $names, array $settings)
    {
        $db = $this->k->db();
        $code = $this->code($code);
        $db->query("INSERT INTO `" . $this->k->table('facet') . "` SET code = '" . $db->escape($code) . "', source = '" . $db->escape($source)
            . "', ref_id = " . (int)$refId . ", kind = " . (int)$kind . ", widget = '" . $db->escape($widget) . "', settings = '"
            . $db->escape(json_encode((object)$settings)) . "', category_mode = 0, seo_index = 0, status = 1, sort_order = " . (int)$sort);
        $id = $db->getLastId();
        foreach ($names as $lang => $name) {
            $db->query("INSERT INTO `" . $this->k->table('facet_description') . "` SET facet_id = " . $id . ", language_id = " . (int)$lang . ", name = '" . $db->escape($name) . "'");
        }
        return $code;
    }

    /** @return array language_id => name */
    private function names($sql)
    {
        $out = [];
        foreach ($this->k->db()->query($sql)->rows as $r) {
            $out[(int)$r['language_id']] = $r['name'];
        }
        if (!$out) {
            $out[$this->k->defaultLanguageId()] = '—';
        }
        return $out;
    }

    /** @return bool */
    private function isRussian($languageId)
    {
        $q = $this->k->db()->query("SELECT code FROM `" . $this->k->prefix() . "language` WHERE language_id = " . (int)$languageId);
        return $q->num_rows && strpos($q->row['code'], 'ru') === 0;
    }
}
