<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

function priceTestAssert($condition, $message) {
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

$expected = $db->query("SELECT
	COUNT(*) AS `total_products`,
	COALESCE(SUM(p.price > '0.0000' AND ROUND(p.price * 1.10, 4) <> p.price AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `eligible_count`,
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `excluded_total`,
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'emovex_%')), 0) AS `excluded_emovex`,
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'manuela_picard_%')), 0) AS `excluded_manuela_picard`,
	COALESCE(SUM(p.price > '0.0000' AND ROUND(p.price * 1.10, 4) <> p.price AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1)), 0) AS `special_count`
	FROM `" . DB_PREFIX . "product` p")->row;
$legacy_bundles = $db->query("SELECT COUNT(DISTINCT `product_id`) AS `total` FROM `" . DB_PREFIX . "wob_price_exclusion` WHERE `product_id` IN (5164,5168) AND `rule_code` LIKE 'manuela_picard_%'")->row;
$fingerprintSql = "SELECT COUNT(*) AS `products`, COALESCE(SUM(`price`), 0) AS `price_total`, COALESCE(SUM(CRC32(CONCAT(`product_id`, ':', `price`))), 0) AS `price_fingerprint` FROM `" . DB_PREFIX . "product`";
$before = $db->query($fingerprintSql)->row;
$run_id = 0;
$test_user_id = random_int(1000000000, 2000000000);

try {
	$run_id = $model->createPreview($test_user_id, 10);
	$run = $model->getRun($run_id, $test_user_id);

	foreach (array('total_products', 'eligible_count', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard', 'special_count') as $summary_key) {
		priceTestAssert((int)$run[$summary_key] === (int)$expected[$summary_key], 'Preview summary mismatch for ' . $summary_key . '.');
	}
	priceTestAssert((int)$run['excluded_total'] >= 42, 'The known permanent exclusion baseline must not shrink below 42 products.');
	priceTestAssert((int)$run['excluded_emovex'] >= 19, 'The known Emovex exclusion baseline must not shrink below 19 products.');
	priceTestAssert((int)$run['excluded_manuela_picard'] >= 23, 'The known Manuela Picard exclusion baseline must not shrink below 23 products.');
	priceTestAssert((int)$legacy_bundles['total'] === 2, 'Both untagged Manuela Picard bundles must remain permanently excluded.');
	priceTestAssert(!empty($run['can_apply']) && empty($run['can_rollback']), 'A preview must be applicable but not rollbackable.');

	$own_history = $model->getRecentRuns(10, $test_user_id);
	$other_history = $model->getRecentRuns(10, $test_user_id - 1);
	priceTestAssert(count($own_history) === 1 && (int)$own_history[0]['price_run_id'] === $run_id, 'The creator must see the preview in their history.');
	foreach ($other_history as $other_run) {
		priceTestAssert((int)$other_run['price_run_id'] !== $run_id, 'Another admin must not receive a dead history link for this run.');
	}

	$invalid_formula = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . (int)$run_id . "' AND (`target_price` <> ROUND(`before_price` * 1.10, 4) OR `target_price` <= `before_price`)")->row;
	priceTestAssert((int)$invalid_formula['total'] === 0, 'Every preview target must use current regular price × 1.10 rounded to four decimals.');

	$excluded_items = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` i INNER JOIN `" . DB_PREFIX . "wob_price_exclusion` e ON (e.product_id = i.product_id) WHERE i.run_id = '" . (int)$run_id . "'")->row;
	priceTestAssert((int)$excluded_items['total'] === 0, 'No permanently excluded product may enter the preview snapshot.');

	$after = $db->query($fingerprintSql)->row;
	priceTestAssert($before === $after, 'Creating a preview must not modify any catalog product price.');

	$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = 'completed', `updated_count` = '1' WHERE `run_id` = '" . (int)$run_id . "'");
	priceTestAssert(!empty($model->getRun($run_id, $test_user_id)['can_rollback']), 'A completed run with an applied item must be rollbackable.');
	$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = 'running', `rollback_started` = NOW(), `rollback_finished` = NULL WHERE `run_id` = '" . (int)$run_id . "'");
	priceTestAssert(!empty($model->getRun($run_id, $test_user_id)['can_rollback']), 'An interrupted rollback must remain resumable.');
	$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = 'rollback_partial', `rollback_finished` = NULL WHERE `run_id` = '" . (int)$run_id . "'");
	priceTestAssert(!empty($model->getRun($run_id, $test_user_id)['can_rollback']), 'A failed partial rollback without a finish timestamp must remain resumable.');
	$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `rollback_finished` = NOW() WHERE `run_id` = '" . (int)$run_id . "'");
	priceTestAssert(empty($model->getRun($run_id, $test_user_id)['can_rollback']), 'A completed partial rollback with terminal conflicts must not offer an endless retry.');
} finally {
	if ($run_id > 0) {
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . (int)$run_id . "'");
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . (int)$run_id . "' AND `user_id` = '" . (int)$test_user_id . "'");
	}
}

echo "Global price preview tests passed; no product price was changed.\n";
