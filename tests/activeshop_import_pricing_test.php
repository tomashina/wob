<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once DIR_SYSTEM . 'library/wob_supplier/activeshop_feed.php';
require_once DIR_APPLICATION . 'controller/extension/module/activeshop_importer.php';
require_once DIR_APPLICATION . 'model/extension/module/activeshop_importer.php';

function activeShopPricingAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function activeShopPricingAssertSame($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
		);
	}
}

class ActiveShopPricingQueryResult {
	public $num_rows;
	public $row;
	public $rows;

	public function __construct($row = array(), $rows = array()) {
		$this->row = $row;
		$this->rows = $rows;
		$this->num_rows = $row ? 1 : count($rows);
	}
}

class ActiveShopPricingFakeDb {
	public $queries = array();

	public function escape($value) {
		return addslashes((string)$value);
	}

	public function query($sql) {
		$this->queries[] = $sql;

		if (strpos($sql, 'SELECT `supplier_id`') === 0) {
			return new ActiveShopPricingQueryResult(array('supplier_id' => 77));
		}

		if (strpos($sql, ' AS id FROM ') !== false) {
			return new ActiveShopPricingQueryResult(array('id' => 909));
		}

		return new ActiveShopPricingQueryResult();
	}

	public function getLastId() {
		return 801;
	}

	public function lastQueryContaining($needle) {
		for ($index = count($this->queries) - 1; $index >= 0; $index--) {
			if (strpos($this->queries[$index], $needle) !== false) {
				return $this->queries[$index];
			}
		}

		return '';
	}
}

class ActiveShopPricingFakeLanguage {
	public function get($key) {
		return $key;
	}
}

class ActiveShopPricingFakeTranslator {
	public function translateProduct($name, $description, $source_language, $target_language) {
		return array('name' => 'Testni artikl 125408', 'description' => $description);
	}
}

class ActiveShopPricingFakeImporterModel {
	public $updates = array();
	public $links = array();

	public function getCategoryMappings(array $paths) {
		return array($paths[0] => array('category_id' => 909));
	}

	public function updateExistingProductTargeted($product_id, array $values) {
		$this->updates[] = array('product_id' => $product_id, 'values' => $values);
	}

	public function linkProduct($supplier_product_id, $product_id, array $source) {
		$this->links[] = array(
			'supplier_product_id' => $supplier_product_id,
			'product_id' => $product_id,
			'source' => $source
		);
	}

	public function ensureManufacturer($name) {
		return 33;
	}

	public function getLanguages() {
		return array(
			array('language_id' => 1, 'code' => 'en-gb'),
			array('language_id' => 3, 'code' => 'hr-hr')
		);
	}

	public function buildUniqueSeoUrls($name, $sku) {
		return array();
	}
}

class ActiveShopPricingFakeCatalogModel {
	public $products = array();

	public function addProduct(array $data) {
		$this->products[] = $data;
		return 125408;
	}
}

$feed_file = tempnam(sys_get_temp_dir(), 'activeshop-pricing-');
if ($feed_file === false) {
	throw new RuntimeException('Unable to create the ActiveShop pricing fixture.');
}

$feed_xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<offers version="1">
  <item>
    <sku>125408</sku>
    <name>ActiveShop pricing regression product</name>
    <description></description>
    <price>315.24</price>
    <category_subcategory>Equipment &gt; Test</category_subcategory>
    <qty>7</qty>
    <weight>1.2500</weight>
    <brand>ACTIVESHOP</brand>
    <tax>23%</tax>
    <EAN>5900000125408</EAN>
    <images/>
    <dimensions><length>10</length><width>20</width><height>30</height></dimensions>
    <gpsr><gpsr_manufacturer/><gpsr_brand/><gpsr_contact/><gpsr_ean/></gpsr>
    <volume>0.001</volume>
    <pack_type>Package</pack_type>
  </item>
</offers>
XML;

file_put_contents($feed_file, $feed_xml);

try {
	$feed = new WobSupplierActiveShopFeed();
	$items = iterator_to_array($feed->iterate($feed_file), false);
	activeShopPricingAssertSame(1, count($items), 'The focused feed fixture must contain exactly one item.');
	$payload = $items[0];
	activeShopPricingAssertSame('125408', $payload['sku'], 'SKU 125408 must survive feed parsing unchanged.');
	activeShopPricingAssertSame(315.24, $payload['feed_price'], 'The supplier <price> must become feed_price without using a catalog price.');

	$markup = 63.0;
	$expected_price = 513.84;
	activeShopPricingAssertSame($expected_price, $feed->calculatePrice($payload['feed_price'], $markup), '315.24 plus 63% must equal 513.84.');

	$db = new ActiveShopPricingFakeDb();
	$importer_model = new ActiveShopPricingFakeImporterModel();
	$catalog_model = new ActiveShopPricingFakeCatalogModel();
	$registry = new Registry();
	$registry->set('db', $db);
	$registry->set('language', new ActiveShopPricingFakeLanguage());
	$registry->set('model_extension_module_activeshop_importer', $importer_model);
	$registry->set('model_catalog_product', $catalog_model);
	$controller = new ControllerExtensionModuleActiveshopImporter($registry);

	$translator = new ReflectionProperty($controller, 'translator');
	$translator->setAccessible(true);
	$translator->setValue($controller, new ActiveShopPricingFakeTranslator());
	$preview_price = new ReflectionMethod($controller, 'calculatePrice');
	$preview_price->setAccessible(true);
	activeShopPricingAssertSame($expected_price, $preview_price->invoke($controller, $payload['feed_price'], $markup), 'Importer preview must calculate from feed_price.');
	$import_one = new ReflectionMethod($controller, 'importOneProduct');
	$import_one->setAccessible(true);

	$settings = array(
		'stock_status_id' => 5,
		'import_images' => 0,
		'weight_class_id' => 1,
		'new_product_status' => 0,
		'tax_class_id' => 11
	);
	$existing_catalog_price = 999.99;
	$existing_row = array(
		'supplier_product_id' => 41,
		'product_id' => 7001,
		'is_current' => 1,
		'match_status' => 'matched',
		'last_imported' => null,
		'local_price' => $existing_catalog_price,
		'local_quantity' => 2,
		'payload' => json_encode($payload)
	);
	$existing_result = $import_one->invoke($controller, $existing_row, $markup, 'price_quantity', 909, $settings, $feed);

	activeShopPricingAssertSame($existing_catalog_price, $existing_result['before']['price'], 'The existing catalog price belongs only in the audit before snapshot.');
	activeShopPricingAssertSame($expected_price, $existing_result['after']['price'], 'Existing-product import must apply markup to feed_price.');
	activeShopPricingAssertSame($expected_price, $importer_model->updates[0]['values']['price'], 'The targeted existing-product update must receive the feed-based result.');
	activeShopPricingAssert($expected_price !== round($existing_catalog_price * 1.63, 2), 'The regression fixture must distinguish feed-based pricing from current-price compounding.');
	activeShopPricingAssertSame(315.24, $importer_model->links[0]['source']['feed_price'], 'Existing-product audit source must retain feed_price.');
	activeShopPricingAssertSame($markup, $importer_model->links[0]['source']['markup'], 'Existing-product audit source must retain markup.');
	activeShopPricingAssertSame($expected_price, $importer_model->links[0]['source']['calculated_price'], 'Existing-product audit source must retain the calculated price.');

	$new_row = array(
		'supplier_product_id' => 42,
		'product_id' => 0,
		'is_current' => 1,
		'match_status' => 'new',
		'last_imported' => null,
		'local_price' => $existing_catalog_price,
		'payload' => json_encode($payload)
	);
	$new_result = $import_one->invoke($controller, $new_row, $markup, 'price_quantity', 909, $settings, $feed);

	activeShopPricingAssertSame('created', $new_result['count_key'], 'The new-product path must create the product.');
	activeShopPricingAssertSame($expected_price, $catalog_model->products[0]['price'], 'New-product import must save the feed-based result.');
	activeShopPricingAssertSame(315.24, $importer_model->links[1]['source']['feed_price'], 'New-product audit source must retain feed_price.');
	activeShopPricingAssertSame($markup, $importer_model->links[1]['source']['markup'], 'New-product audit source must retain markup.');
	activeShopPricingAssertSame($expected_price, $importer_model->links[1]['source']['calculated_price'], 'New-product audit source must retain the calculated price.');

	$audit_db = new ActiveShopPricingFakeDb();
	$audit_registry = new Registry();
	$audit_registry->set('db', $audit_db);
	$audit_registry->set('cache', new stdClass());
	$audit_model = new ModelExtensionModuleActiveshopImporter($audit_registry);
	$audit_model->stageFeedItem($payload, str_repeat('a', 64));
	$stage_sql = $audit_db->lastQueryContaining('INSERT INTO `' . DB_PREFIX . 'wob_supplier_product`');
	activeShopPricingAssert(strpos($stage_sql, '`feed_price`') !== false && strpos($stage_sql, "'315.2400'") !== false, 'Staging must persist the supplier price in the dedicated feed_price column.');

	$audit_source = $importer_model->links[1]['source'];
	$audit_model->linkProduct(42, 125408, $audit_source);
	$link_sql = $audit_db->lastQueryContaining('UPDATE `' . DB_PREFIX . 'wob_supplier_product`');
	activeShopPricingAssert(strpos($link_sql, "`feed_price` = '315.2400'") !== false, 'Import audit must persist feed_price.');
	activeShopPricingAssert(strpos($link_sql, "`last_markup` = '63.0000'") !== false, 'Import audit must persist the applied markup.');
	activeShopPricingAssert(strpos($link_sql, "`last_calculated_price` = '513.8400'") !== false, 'Import audit must persist the calculated result.');

	$run_id = $audit_model->beginRun(array('type' => 'import', 'user_id' => 1, 'markup' => $markup, 'settings' => array()));
	$run_sql = $audit_db->lastQueryContaining('INSERT INTO `' . DB_PREFIX . 'wob_import_run`');
	activeShopPricingAssertSame(801, $run_id, 'The focused audit harness must return its fake run ID.');
	activeShopPricingAssert(strpos($run_sql, "`markup` = '63.0000'") !== false, 'The import run audit must retain the applied markup.');
} finally {
	@unlink($feed_file);
}

echo "ActiveShop import pricing tests passed; SKU 125408 always uses feed price 315.24.\n";
