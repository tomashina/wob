<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once DIR_APPLICATION . 'controller/extension/module/activeshop_importer.php';
require_once DIR_APPLICATION . 'model/extension/module/activeshop_importer.php';

function activeShopBatchAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function activeShopBatchAssertSame($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
		);
	}
}

class ActiveShopBatchQueryResult {
	public $num_rows;
	public $row;
	public $rows;

	public function __construct($row = array(), $rows = array()) {
		$this->row = $row;
		$this->rows = $rows;
		$this->num_rows = $row ? 1 : count($rows);
	}
}

class ActiveShopBatchFakeDb {
	public $queries = array();

	public function escape($value) {
		return addslashes((string)$value);
	}

	public function query($sql) {
		$this->queries[] = $sql;

		if (strpos($sql, 'SELECT `supplier_id`') === 0) {
			return new ActiveShopBatchQueryResult(array('supplier_id' => 77));
		}

		return new ActiveShopBatchQueryResult();
	}
}

function activeShopBatchInsertQueries(array $queries) {
	$needle = 'INSERT INTO `' . DB_PREFIX . 'wob_supplier_product`';
	return array_values(array_filter($queries, function ($sql) use ($needle) {
		return strpos($sql, $needle) === 0;
	}));
}

function activeShopBatchValueRows(array $queries) {
	$rows = array();

	foreach (activeShopBatchInsertQueries($queries) as $sql) {
		$start_marker = ") VALUES\n";
		$end_marker = "\n\t\t\tON DUPLICATE KEY UPDATE";
		$start = strpos($sql, $start_marker);
		$end = strpos($sql, $end_marker, $start === false ? 0 : $start);
		activeShopBatchAssert($start !== false && $end !== false, 'The staging INSERT must retain a recognizable VALUES/upsert boundary.');
		$values = trim(substr($sql, $start + strlen($start_marker), $end - $start - strlen($start_marker)));
		$statement_rows = explode(",\n\t\t\t(", $values);

		foreach ($statement_rows as $index => $row) {
			$rows[] = $index ? '(' . $row : $row;
		}
	}

	return $rows;
}

function activeShopBatchModel(ActiveShopBatchFakeDb $db) {
	$registry = new Registry();
	$registry->set('db', $db);
	return new ModelExtensionModuleActiveshopImporter($registry);
}

$items = array();
for ($index = 1; $index <= 123; $index++) {
	$items[] = array(
		'sku' => sprintf('BATCH-%04d', $index),
		'ean' => sprintf('5900000%06d', $index),
		'name' => "Batch item " . $index . " O'Reilly",
		'brand' => $index % 2 ? 'ActiveShop' : 'Test brand',
		'category_path' => array('Equipment', 'Batch ' . ($index % 7)),
		'feed_price' => 100 + ($index / 100),
		'quantity' => $index - 3,
		'weight' => $index / 10,
		'dimensions' => array('length' => $index, 'width' => $index + 1, 'height' => $index + 2),
		'images' => array('https://b2b.activeshop.com.pl/media/batch-' . $index . '.jpg'),
		'source_hash' => hash('sha256', 'batch-source-' . $index),
		'payload' => array('note' => 'Exact staged payload ' . $index)
	);
}
$feed_token = hash('sha256', 'batch-feed-token');

$single_db = new ActiveShopBatchFakeDb();
$single_model = activeShopBatchModel($single_db);
foreach ($items as $item) {
	$single_model->stageFeedItem($item, $feed_token);
}

$batch_db = new ActiveShopBatchFakeDb();
$batch_model = activeShopBatchModel($batch_db);
$batch_registry = new Registry();
$batch_registry->set('db', $batch_db);
$batch_registry->set('model_extension_module_activeshop_importer', $batch_model);
$controller = new ControllerExtensionModuleActiveshopImporter($batch_registry);
$stage_batches = new ReflectionMethod($controller, 'stageFeedItemsInBatches');
$stage_batches->setAccessible(true);
$staged = $stage_batches->invoke($controller, new ArrayIterator($items), $feed_token);

$single_inserts = activeShopBatchInsertQueries($single_db->queries);
$batch_inserts = activeShopBatchInsertQueries($batch_db->queries);
activeShopBatchAssertSame(count($items), $staged, 'The controller batch path must count every staged feed item.');
activeShopBatchAssertSame(count($items), count($single_inserts), 'The compatibility wrapper should retain one INSERT per direct stageFeedItem call.');
activeShopBatchAssertSame(3, count($batch_inserts), '123 items at 50 items per batch must use exactly three staging INSERTs.');
activeShopBatchAssert(count($batch_inserts) < count($single_inserts) / 20, 'Batch staging must reduce supplier-product INSERT query count by more than 20x.');
activeShopBatchAssertSame(activeShopBatchValueRows($single_db->queries), activeShopBatchValueRows($batch_db->queries), 'Batch staging must generate exactly the same ordered supplier row values as stageFeedItem compatibility calls.');

foreach ($batch_db->queries as $sql) {
	activeShopBatchAssert(!preg_match('/^(INSERT INTO|UPDATE|DELETE FROM)\s+`' . preg_quote(DB_PREFIX, '/') . 'product`/i', trim($sql)), 'Feed staging must never mutate the catalog product table.');
}

$large_items = array($items[0], $items[1]);
$large_items[0]['payload']['description'] = str_repeat('A', 300000);
$large_items[1]['payload']['description'] = str_repeat('B', 300000);
$large_db = new ActiveShopBatchFakeDb();
$large_model = activeShopBatchModel($large_db);
$large_registry = new Registry();
$large_registry->set('db', $large_db);
$large_registry->set('model_extension_module_activeshop_importer', $large_model);
$large_controller = new ControllerExtensionModuleActiveshopImporter($large_registry);
$large_stage_batches = new ReflectionMethod($large_controller, 'stageFeedItemsInBatches');
$large_stage_batches->setAccessible(true);
activeShopBatchAssertSame(2, $large_stage_batches->invoke($large_controller, $large_items, $feed_token), 'Byte-capped batching must still stage every large item.');
activeShopBatchAssertSame(2, count(activeShopBatchInsertQueries($large_db->queries)), 'The 1 MiB safety cap must split large payloads even below the 50-item count cap.');

echo "ActiveShop batch staging tests passed; 123 supplier rows use 3 identical upserts and no catalog writes.\n";
