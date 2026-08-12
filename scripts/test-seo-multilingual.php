<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('HABYS_IMPORT_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/upload/import_habys_new_offer.php';
define('MULTILINGUAL_SEO_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/scripts/repair-multilingual-seo.php';
define('PRODUCT_DESCRIPTION_IMAGE_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/scripts/repair-product-description-images.php';

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

function seoTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function seoTestContains(string $needle, array $haystack, string $message): void
{
    foreach ($haystack as $value) {
        if (strpos($value, $needle) !== false) {
            return;
        }
    }

    throw new RuntimeException($message . ' Missing: ' . $needle);
}

$translationProduct = array(
    'name' => 'English fallback',
    'description' => '<p>English fallback description</p>',
    'translations' => array(
        'eng' => array('name' => 'Massage table', 'description' => '<p>English description</p>'),
        'hrv' => array('name' => 'Stol za masažu', 'description' => '<p>Hrvatski opis</p>')
    )
);
$english = resolveProductTranslation($translationProduct, array('code' => 'en-gb'));
$croatian = resolveProductTranslation($translationProduct, array('code' => 'hr-hr'));

seoTestAssert($english['name'] === 'Massage table', 'English feed translation was not selected.');
seoTestAssert($croatian['name'] === 'Stol za masažu', 'Croatian feed translation was not selected.');
seoTestAssert(strlen(buildImporterMetaDescription('Name', str_repeat('Long description ', 30), 'en-gb')) <= 163, 'Importer meta description was not truncated.');
seoTestAssert(mseoSlugify('Stol za masažu – čokolada') === 'stol-za-masazu-cokolada', 'Croatian slug transliteration failed.');

$occupied = array('massage-table' => array(1 => true), 'massage-table-en' => array(2 => true));
$unique = mseoUniqueKeyword($occupied, 'Massage table', 'en', 42);
seoTestAssert($unique === 'massage-table-en-42', 'Unique language slug fallback is incorrect.');
$longBase = str_repeat('a', 240);
$longOccupied = array($longBase => array(1 => true));
$longUnique = mseoUniqueKeyword($longOccupied, $longBase, 'en', 42);
seoTestAssert(strlen($longUnique) <= 240 && substr($longUnique, -3) === '-en', 'Long unique slug lost its language suffix.');
seoTestAssert(strlen(composeSeoKeyword($longBase, 'en-2')) <= 240, 'Importer slug candidate exceeded its limit.');
seoTestAssert(mseoTextLength(mseoProductTitle(str_repeat('Dugi naziv ', 20), 'Habys')) <= 65, 'Product meta title exceeded 65 characters.');
seoTestAssert(strpos(mseoProductTitle('Stol za masažu', 'Habys'), 'Stol za masažu | Habys') === 0, 'Product meta title lost product and brand identity.');

$imageRepair = pdiTransformDescription(
    '<p><img src="https://cdn.example.com/product.jpg" alt=""></p>',
    'Stol za masažu',
    array('https://cdn.example.com/product.jpg' => 'image/catalog/seo-description/image.jpg')
);
seoTestAssert(strpos($imageRepair['html'], 'src="image/catalog/seo-description/image.jpg"') !== false, 'External description image was not localized.');
seoTestAssert(strpos($imageRepair['html'], 'alt="Stol za masažu"') !== false, 'Description image alt text was not repaired.');
seoTestAssert(strpos($imageRepair['html'], 'loading="lazy"') !== false, 'Description image lazy loading was not added.');
seoTestAssert(strpos($imageRepair['html'], 'decoding="async"') !== false, 'Description image async decoding was not added.');
$imageRepairAgain = pdiTransformDescription($imageRepair['html'], 'Stol za masažu');
seoTestAssert(!$imageRepairAgain['changed'] && $imageRepairAgain['external_images'] === 0, 'Description image repair is not idempotent.');

if (!class_exists('Model', false)) {
    class Model {
        public $config;
        public $db;
        public $load;
        public $model_localisation_language;
    }
}

class SeoTestResult {
    public $num_rows;
    public $row;

    public function __construct(array $row = array()) {
        $this->row = $row;
        $this->num_rows = $row ? 1 : 0;
    }
}

class SeoTestConfig {
    private $values = array(
        'hb_seourl_hreflang' => 1,
        'config_store_id' => 0,
        'config_ssl' => 'https://www.worldofbeauty.hr/',
        'config_url' => 'http://www.worldofbeauty.hr/',
        'config_language' => 'hr-hr'
    );

    public function get(string $key) {
        return isset($this->values[$key]) ? $this->values[$key] : null;
    }
}

class SeoTestDatabase {
    private $aliases = array(
        'product_id=42:3' => 'hr-proizvod',
        'product_id=42:1' => 'en-product',
        'category_id=7:3' => 'hr-kategorija',
        'category_id=7:1' => 'en-category'
    );

    public function escape(string $value): string {
        return addslashes($value);
    }

    public function query(string $sql): SeoTestResult {
        if (strpos($sql, "`key` = 'config_language'") !== false) {
            return new SeoTestResult(array('value' => 'hr-hr'));
        }

        if (strpos($sql, 'FROM `oc_hb_url`') !== false) {
            return new SeoTestResult();
        }

        if (
            preg_match("/`query` = '([^']+)'/", $sql, $queryMatch) &&
            preg_match("/`language_id` = '(\\d+)'/", $sql, $languageMatch)
        ) {
            $key = stripslashes($queryMatch[1]) . ':' . (int)$languageMatch[1];
            return isset($this->aliases[$key])
                ? new SeoTestResult(array('keyword' => $this->aliases[$key]))
                : new SeoTestResult();
        }

        return new SeoTestResult();
    }
}

class SeoTestLoader {
    public function model(string $route): void {
    }
}

class SeoTestLanguages {
    public function getLanguages(): array {
        return array(
            array('language_id' => 3, 'code' => 'hr-hr'),
            array('language_id' => 1, 'code' => 'en-gb')
        );
    }
}

class SeoTestGtinDatabase {
    private $duplicate;

    public function __construct(bool $duplicate) {
        $this->duplicate = $duplicate;
    }

    public function escape(string $value): string {
        return addslashes($value);
    }

    public function query(string $sql): SeoTestResult {
        return $this->duplicate
            ? new SeoTestResult(array('product_id' => 99))
            : new SeoTestResult();
    }
}

require_once dirname(__DIR__) . '/upload/catalog/model/extension/module/hb_seo_snippets.php';
$snippetModel = new ModelExtensionModuleHbSeoSnippets();
$normalizeGtin = new ReflectionMethod($snippetModel, 'normalizeGtin');
$normalizeGtin->setAccessible(true);
seoTestAssert($normalizeGtin->invoke($snippetModel, '4006381333931') === '4006381333931', 'Valid GTIN-13 was rejected.');
seoTestAssert($normalizeGtin->invoke($snippetModel, '4006381333932') === '', 'Invalid GTIN check digit was accepted.');
seoTestAssert($normalizeGtin->invoke($snippetModel, 'not-an-ean') === '', 'Non-numeric GTIN was accepted.');
seoTestAssert($normalizeGtin->invoke($snippetModel, '0000000000000') === '', 'Placeholder GTIN was accepted.');
$uniqueGtin = new ReflectionMethod($snippetModel, 'uniqueProductGtin');
$uniqueGtin->setAccessible(true);
$snippetModel->db = new SeoTestGtinDatabase(false);
seoTestAssert($uniqueGtin->invoke($snippetModel, '4006381333931', 42) === '4006381333931', 'Unique GTIN was rejected.');
$snippetModel->db = new SeoTestGtinDatabase(true);
seoTestAssert($uniqueGtin->invoke($snippetModel, '4006381333931', 42) === '', 'Duplicate GTIN was published.');

require_once dirname(__DIR__) . '/upload/catalog/model/extension/module/hb_seourl.php';
$hreflang = new ModelExtensionModuleHbSeourl();
$hreflang->config = new SeoTestConfig();
$hreflang->db = new SeoTestDatabase();
$hreflang->load = new SeoTestLoader();
$hreflang->model_localisation_language = new SeoTestLanguages();

$productAlternates = $hreflang->hreflang('product/product', array('product_id' => 42));
seoTestAssert(count($productAlternates) === 3, 'Product hreflang must include HR, EN and x-default.');
seoTestContains('hreflang="hr-hr" href="https://www.worldofbeauty.hr/hr-proizvod"', $productAlternates, 'Croatian product alternate is wrong.');
seoTestContains('hreflang="en-gb" href="https://www.worldofbeauty.hr/en-product"', $productAlternates, 'English product alternate is wrong.');
seoTestContains('hreflang="x-default" href="https://www.worldofbeauty.hr/hr-proizvod"', $productAlternates, 'x-default must point to Croatian.');

$categoryAlternates = $hreflang->hreflang('product/category', array('path' => '2_7', 'page' => 2));
seoTestContains('href="https://www.worldofbeauty.hr/hr-kategorija?page=2"', $categoryAlternates, 'Category hreflang lost pagination.');
seoTestContains('href="https://www.worldofbeauty.hr/en-category?page=2"', $categoryAlternates, 'English category hreflang lost pagination.');

echo 'All SEO integrity tests passed.' . PHP_EOL;
