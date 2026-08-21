<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once DIR_APPLICATION . 'model/extension/module/activeshop_importer.php';

function invalidCandidateAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function invalidCandidateAssertSame($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
	}
}

class InvalidCandidateQueryResult {
	public $num_rows;
	public $row;
	public $rows;

	public function __construct($row = array(), $rows = array()) {
		$this->row = $row;
		$this->rows = $rows;
		$this->num_rows = $row ? 1 : count($rows);
	}
}

class InvalidCandidateFakeDb {
	public $queries = array();

	public function escape($value) {
		return addslashes((string)$value);
	}

	public function query($sql) {
		$this->queries[] = $sql;

		if (strpos($sql, 'SELECT `supplier_id`') === 0) {
			return new InvalidCandidateQueryResult(array('supplier_id' => 77));
		}
		if (strpos($sql, 'SUM(TRIM(sp.sku)') !== false) {
			return new InvalidCandidateQueryResult(array('staged' => 4, 'importable' => 1));
		}
		if (strpos($sql, 'COUNT(*) AS `total`') !== false || strpos($sql, 'COUNT(DISTINCT') !== false) {
			return new InvalidCandidateQueryResult(array('total' => 0));
		}

		return new InvalidCandidateQueryResult();
	}
}

class InvalidCandidateFakeConfig {
	public function get($key) {
		return $key === 'config_language_id' ? 1 : null;
	}
}

function invalidCandidateLastQuery(array $queries, $needle) {
	for ($index = count($queries) - 1; $index >= 0; $index--) {
		if (strpos($queries[$index], $needle) !== false) {
			return $queries[$index];
		}
	}

	return '';
}

function invalidCandidateAssertPredicate($sql, $context) {
	invalidCandidateAssert(strpos($sql, "TRIM(sp.sku) <> ''") !== false, $context . ' must exclude an empty SKU.');
	invalidCandidateAssert(strpos($sql, "TRIM(sp.name) <> ''") !== false, $context . ' must exclude an empty name.');
	invalidCandidateAssert(strpos($sql, "sp.feed_price > '0.0000'") !== false, $context . ' must exclude a non-positive feed price.');
}

$db = new InvalidCandidateFakeDb();
$registry = new Registry();
$registry->set('db', $db);
$registry->set('config', new InvalidCandidateFakeConfig());
$model = new ModelExtensionModuleActiveshopImporter($registry);
$token = str_repeat('a', 64);
$base = array(
	'sku' => 'VALID-1',
	'name' => 'Valid item',
	'feed_price' => 12.50,
	'quantity' => 1,
	'weight' => 0,
	'category_path' => array('Test'),
	'images' => array(),
	'dimensions' => array(),
	'source_hash' => str_repeat('b', 64)
);
$empty_name = $base;
$empty_name['sku'] = '138306';
$empty_name['name'] = '';
$zero_price = $base;
$zero_price['sku'] = 'ZERO-PRICE';
$zero_price['feed_price'] = 0;

invalidCandidateAssertSame(3, $model->stageFeedItems(array($base, $empty_name, $zero_price), $token), 'Raw supplier rows must remain staged for source history.');
$stage_sql = invalidCandidateLastQuery($db->queries, 'INSERT INTO `' . DB_PREFIX . 'wob_supplier_product`');
invalidCandidateAssert(strpos($stage_sql, "'138306'") !== false, 'The live empty-name SKU must remain in raw staging.');
invalidCandidateAssert(strpos($stage_sql, "'ZERO-PRICE'") !== false && strpos($stage_sql, "'0.0000'") !== false, 'A zero-price row must remain in raw staging.');

$model->getProducts(array('is_current' => 1));
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'SELECT sp.*'), 'Product list');
$model->getTotalProducts(array('is_current' => 1));
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'COUNT(*) AS `total`'), 'Product total');
$model->getStatusCounts();
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'AS `ui_status`'), 'Status counts');
$model->getSupplierCategories(array('is_current' => 1));
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'product_count'), 'Category list');
$model->getTotalSupplierCategories(array('is_current' => 1));
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'COUNT(DISTINCT'), 'Category total');
$model->getStagedProductsByIds(array(1, 2, 3));
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'FIELD(sp.supplier_product_id'), 'Import selection');
$model->reconcileExistingProducts();
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'sp.`last_imported`'), 'Existing-product reconciliation');
$model->autoMapSupplierCategories();
invalidCandidateAssertPredicate(invalidCandidateLastQuery($db->queries, 'SELECT DISTINCT sp.`category_path`'), 'Automatic category mapping');

$eligibility = $model->getCurrentFeedEligibilityCounts();
invalidCandidateAssertSame(array('staged' => 4, 'importable' => 1, 'excluded_invalid' => 3), $eligibility, 'Refresh audit counts must distinguish raw staging from excluded import candidates.');

$model->finishFeedRefresh($token);
$finish_sql = invalidCandidateLastQuery($db->queries, 'SET `is_current`');
invalidCandidateAssert(strpos($finish_sql, '`feed_token` <>') !== false, 'Refresh completion must retire only rows absent from the new feed generation.');
invalidCandidateAssert(strpos($finish_sql, 'TRIM(`name`)') === false && strpos($finish_sql, '`feed_price` <=') === false, 'Excluded import candidates must remain current supplier-source rows.');

$controller_source = file_get_contents(DIR_APPLICATION . 'controller/extension/module/activeshop_importer.php');
$template_source = file_get_contents(DIR_TEMPLATE . 'extension/module/activeshop_importer.twig');
invalidCandidateAssert(strpos($controller_source, 'getCurrentFeedEligibilityCounts()') !== false, 'Refresh must snapshot eligibility counts after staging.');
invalidCandidateAssert(strpos($controller_source, "'excluded_invalid' => \$excluded_invalid") !== false, 'Refresh audit must persist the excluded-invalid count.');
invalidCandidateAssert(strpos($controller_source, "\$sku === '' || \$name === '' || \$feed_price <= 0") !== false, 'The final import gate must independently reject invalid required fields.');
invalidCandidateAssert(strpos($template_source, 'counts.excluded_invalid') !== false, 'Recent-run UI must display the excluded-invalid count.');

echo "ActiveShop invalid-candidate filtering tests passed; raw rows stay current while importer candidates remain safe.\n";
