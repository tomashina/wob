<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

function priceBatchAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$registry = new Registry();
$config = new Config();
$config->set('config_language_id', 3);
$registry->set('config', $config);
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);
$registry->set('cache', new Cache('file', 3600));

require_once DIR_APPLICATION . 'model/extension/module/global_price_adjustment.php';
$model = new ModelExtensionModuleGlobalPriceAdjustment($registry);
$model->install();

$test_user_id = random_int(1000000000, 2000000000);
$run_ids = array();
$product_fingerprint_sql = "SELECT COUNT(*) AS `products`, COALESCE(SUM(`price`), 0) AS `price_total`, COALESCE(SUM(CRC32(CONCAT(`product_id`, ':', `price`))), 0) AS `price_fingerprint` FROM `" . DB_PREFIX . "product`";
$product_before = $db->query($product_fingerprint_sql)->row;
$max_product = $db->query("SELECT COALESCE(MAX(`product_id`), 0) AS `max_id` FROM `" . DB_PREFIX . "product`")->row;
$synthetic_product_start = (int)$max_product['max_id'] + 100000;

$create_run = function ($status, $item_status, $offset) use ($db, $test_user_id, $synthetic_product_start, &$run_ids) {
	$db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_run` SET
		`user_id` = '" . (int)$test_user_id . "', `percent` = '10.0000', `basis_version` = '1', `status` = '" . $db->escape($status) . "',
		`total_products` = '26', `eligible_count` = '26', `updated_count` = '" . ($item_status === 'updated' || $item_status === 'rolling_back' ? 26 : 0) . "',
		`exclusion_snapshot` = '{}', `error` = '', `date_created` = NOW()");
	$run_id = (int)$db->getLastId();
	$run_ids[] = $run_id;

	for ($index = 0; $index < 26; $index++) {
		$status_for_item = $index === 0 && $item_status === 'preview' ? 'applying' : $item_status;
		$product_id = $synthetic_product_start + $offset + $index;
		$db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_item` SET
			`run_id` = '" . $run_id . "', `product_id` = '" . $product_id . "', `model` = 'batch-" . $product_id . "', `sku` = 'batch-" . $product_id . "',
			`before_price` = '1.0000', `base_price` = '1.0000', `price_source` = 'catalog_regular', `target_price` = '1.1000',
			`status` = '" . $db->escape($status_for_item) . "', `message` = '', `date_created` = NOW(), `date_modified` = NOW()");
	}

	return $run_id;
};

try {
	$apply_run_id = $create_run('running', 'preview', 0);
	$first_apply = $model->applyRun($apply_run_id, $test_user_id);
	priceBatchAssert(empty($first_apply['batch_done']), 'The first apply request must leave a 26-item run resumable.');
	priceBatchAssert((int)$first_apply['batch_processed'] === 25, 'The first apply request must process exactly 25 items.');
	priceBatchAssert($first_apply['status'] === 'running' && (int)$first_apply['operation_processed'] === 25 && (int)$first_apply['operation_remaining'] === 1, 'Apply progress must be durably audited after the first batch.');
	$first_apply_statuses = $db->query("SELECT `status`, COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . $apply_run_id . "' GROUP BY `status`")->rows;
	priceBatchAssert(count($first_apply_statuses) === 2, 'An interrupted applying item and ordinary preview items must share the same bounded recovery path.');

	$second_apply = $model->applyRun($apply_run_id, $test_user_id);
	priceBatchAssert(!empty($second_apply['batch_done']) && (int)$second_apply['batch_processed'] === 1, 'The second apply request must finish the final item only.');
	priceBatchAssert($second_apply['status'] === 'completed_with_conflicts' && (int)$second_apply['conflict_count'] === 26, 'The resumed apply must finalize its complete audit counts.');

	$rollback_run_id = $create_run('completed', 'updated', 1000);
	$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = 'rolling_back' WHERE `run_id` = '" . $rollback_run_id . "' ORDER BY `item_id` ASC LIMIT 1");
	$first_rollback = $model->rollbackRun($rollback_run_id, $test_user_id);
	priceBatchAssert(empty($first_rollback['batch_done']) && (int)$first_rollback['batch_processed'] === 25, 'The first rollback request must process exactly 25 items and remain resumable.');
	priceBatchAssert($first_rollback['status'] === 'running' && (int)$first_rollback['operation_processed'] === 25 && (int)$first_rollback['operation_remaining'] === 1, 'Rollback progress must be durably audited after the first batch.');

	$second_rollback = $model->rollbackRun($rollback_run_id, $test_user_id);
	priceBatchAssert(!empty($second_rollback['batch_done']) && (int)$second_rollback['batch_processed'] === 1, 'The second rollback request must finish the final item only.');
	priceBatchAssert($second_rollback['status'] === 'rollback_partial' && (int)$second_rollback['rollback_conflict_count'] === 26, 'The resumed rollback must finalize its complete audit counts.');

	$product_after = $db->query($product_fingerprint_sql)->row;
	priceBatchAssert($product_before === $product_after, 'Synthetic batch regression must not modify any catalog product.');
} finally {
	foreach ($run_ids as $run_id) {
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . (int)$run_id . "'");
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . (int)$run_id . "' AND `user_id` = '" . (int)$test_user_id . "'");
	}
}

echo "Global price bounded apply/rollback batch tests passed; no catalog product was changed.\n";
