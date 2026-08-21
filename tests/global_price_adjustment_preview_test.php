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
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `excluded_total`,
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'emovex_%')), 0) AS `excluded_emovex`,
	COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'manuela_picard_%')), 0) AS `excluded_manuela_picard`
	FROM `" . DB_PREFIX . "product` p")->row;
$legacy_bundles = $db->query("SELECT COUNT(DISTINCT `product_id`) AS `total` FROM `" . DB_PREFIX . "wob_price_exclusion` WHERE `product_id` IN (5164,5168) AND `rule_code` LIKE 'manuela_picard_%'")->row;
$fingerprintSql = "SELECT COUNT(*) AS `products`, COALESCE(SUM(`price`), 0) AS `price_total`, COALESCE(SUM(CRC32(CONCAT(`product_id`, ':', `price`))), 0) AS `price_fingerprint` FROM `" . DB_PREFIX . "product`";
$before = $db->query($fingerprintSql)->row;
$supplierFingerprintSql = "SELECT COUNT(*) AS `rows`, COALESCE(SUM(CRC32(CONCAT(`supplier_product_id`, ':', COALESCE(`last_markup`, 'NULL'), ':', COALESCE(`last_calculated_price`, 'NULL')))), 0) AS `pricing_fingerprint` FROM `" . DB_PREFIX . "wob_supplier_product`";
$supplier_before = $db->query($supplierFingerprintSql)->row;
$run_id = 0;
$drift_run_id = 0;
$legacy_run_id = 0;
$test_user_id = random_int(1000000000, 2000000000);

try {
	$run_id = $model->createPreview($test_user_id, 63);
	$run = $model->getRun($run_id, $test_user_id);

	foreach (array('total_products', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard') as $summary_key) {
		priceTestAssert((int)$run[$summary_key] === (int)$expected[$summary_key], 'Preview summary mismatch for ' . $summary_key . '.');
	}
	priceTestAssert((int)$run['basis_version'] === 1, 'New previews must use the feed-aware audit basis version.');
	priceTestAssert((int)$run['feed_source_count'] + (int)$run['catalog_source_count'] === (int)$run['eligible_count'], 'Every preview item must have exactly one audited price source.');
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

	$invalid_formula = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . (int)$run_id . "' AND (
		(`price_source` = 'activeshop_feed' AND (`base_price` <> `feed_price` OR `target_price` <> ROUND(`feed_price` * 1.63, 2) OR `target_markup` <> '63.0000' OR `target_calculated_price` <> `target_price` OR `supplier_product_id` = '0' OR CHAR_LENGTH(`source_hash`) <> 64 OR CHAR_LENGTH(`feed_token`) <> 64))
		OR (`price_source` = 'catalog_regular' AND (`base_price` <> `before_price` OR `target_price` <> ROUND(`before_price` * 1.63, 4) OR `feed_price` IS NOT NULL OR `supplier_product_id` <> '0' OR `target_markup` IS NOT NULL OR `target_calculated_price` IS NOT NULL))
		OR `price_source` NOT IN ('activeshop_feed','catalog_regular')
	)")->row;
	priceTestAssert((int)$invalid_formula['total'] === 0, 'Every preview target must use the audited feed or catalog basis with source-specific rounding.');

	$catalog_with_link = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` i WHERE i.run_id = '" . (int)$run_id . "' AND i.price_source = 'catalog_regular' AND EXISTS(
		SELECT 1 FROM `" . DB_PREFIX . "wob_supplier_product` sp INNER JOIN `" . DB_PREFIX . "wob_supplier` s ON (s.supplier_id = sp.supplier_id AND s.code = 'activeshop') WHERE sp.product_id = i.product_id
	)")->row;
	priceTestAssert((int)$catalog_with_link['total'] === 0, 'A stale, invalid, or ambiguous ActiveShop link must never silently fall back to the catalog price.');

	$sku_125408 = $db->query("SELECT i.* FROM `" . DB_PREFIX . "wob_price_adjustment_item` i WHERE i.run_id = '" . (int)$run_id . "' AND i.sku = '125408' LIMIT 1");
	priceTestAssert($sku_125408->num_rows === 1, 'SKU 125408 must be present in the 63% preview.');
	priceTestAssert($sku_125408->row['price_source'] === 'activeshop_feed', 'SKU 125408 must use its unique current ActiveShop feed link.');
	priceTestAssert((float)$sku_125408->row['feed_price'] === 315.24 && (float)$sku_125408->row['base_price'] === 315.24, 'SKU 125408 must snapshot the 315.24 feed price as its basis.');
	priceTestAssert((float)$sku_125408->row['target_price'] === 513.84, 'SKU 125408 at +63% must target 513.84 with importer-compatible two-decimal rounding.');
	$state_method = new ReflectionMethod($model, 'getCurrentItemState');
	$state_method->setAccessible(true);
	$sku_state = $state_method->invoke($model, (int)$sku_125408->row['item_id']);
	priceTestAssert(!empty($sku_state['is_same_price_source']), 'The feed source generation, identity, and price CAS must match immediately after preview.');
	priceTestAssert(!empty($sku_state['is_supplier_before']) && empty($sku_state['is_supplier_target']), 'The managed markup CAS must snapshot the supplier state without changing it during preview.');
	$supplier_update_method = new ReflectionMethod($model, 'updateSupplierPricingToTarget');
	$supplier_update_method->setAccessible(true);
	$db->query('START TRANSACTION');
	try {
		$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = 'applying', `target_price` = `before_price` WHERE `item_id` = '" . (int)$sku_125408->row['item_id'] . "' LIMIT 1");
		$supplier_update_method->invoke($model, (int)$sku_125408->row['item_id']);
		$managed_state = $db->query("SELECT `last_markup`, `last_calculated_price` FROM `" . DB_PREFIX . "wob_supplier_product` WHERE `supplier_product_id` = '" . (int)$sku_125408->row['supplier_product_id'] . "' LIMIT 1")->row;
		priceTestAssert((float)$managed_state['last_markup'] === 63.0 && (float)$managed_state['last_calculated_price'] === 513.84, 'The supplier CAS must store the run markup and calculated feed price.');
		$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = 'rolling_back' WHERE `item_id` = '" . (int)$sku_125408->row['item_id'] . "' LIMIT 1");
		$supplier_restore_method = new ReflectionMethod($model, 'restoreSupplierPricingToBefore');
		$supplier_restore_method->setAccessible(true);
		$supplier_restore_method->invoke($model, (int)$sku_125408->row['item_id'], array('rolling_back'));
		$restored_managed_state = $db->query("SELECT `last_markup`, `last_calculated_price` FROM `" . DB_PREFIX . "wob_supplier_product` WHERE `supplier_product_id` = '" . (int)$sku_125408->row['supplier_product_id'] . "' LIMIT 1")->row;
		priceTestAssert($restored_managed_state['last_markup'] === null && $restored_managed_state['last_calculated_price'] === null, 'Rollback CAS must restore the supplier pricing state that existed before preview.');
		$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = 'applying', `target_price` = '" . $db->escape($sku_125408->row['target_price']) . "' WHERE `item_id` = '" . (int)$sku_125408->row['item_id'] . "' LIMIT 1");
		$db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` SET `last_markup` = '63.0000', `last_calculated_price` = '513.8400' WHERE `supplier_product_id` = '" . (int)$sku_125408->row['supplier_product_id'] . "' LIMIT 1");
		$reconcile_method = new ReflectionMethod($model, 'reconcileApplyingState');
		$reconcile_method->setAccessible(true);
		$partial_state = $state_method->invoke($model, (int)$sku_125408->row['item_id']);
		$partial_recovery = $reconcile_method->invoke($model, (int)$sku_125408->row['item_id'], $partial_state);
		$compensated_state = $state_method->invoke($model, (int)$sku_125408->row['item_id']);
		priceTestAssert(empty($partial_recovery['terminal']) && !empty($compensated_state['is_supplier_before']), 'Interrupted supplier-only apply must be compensated before ordinary conflict preflight.');
		$db->query('ROLLBACK');
	} catch (Throwable $exception) {
		$db->query('ROLLBACK');
		throw $exception;
	}

	$drift_run_id = $model->createPreview($test_user_id, 20);
	$markup_only = $db->query("SELECT i.* FROM `" . DB_PREFIX . "wob_price_adjustment_item` i WHERE i.run_id = '" . (int)$drift_run_id . "' AND i.sku = '144142' LIMIT 1");
	priceTestAssert($markup_only->num_rows === 1, 'A feed item whose shop price already equals the target must remain in preview when managed markup is stale.');
	priceTestAssert((float)$markup_only->row['before_price'] === (float)$markup_only->row['target_price'] && (float)$markup_only->row['target_markup'] === 20.0, 'Markup-only preview must preserve the shop price and audit the new cron markup.');
	$db->query('START TRANSACTION');
	try {
		$db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = 'applying' WHERE `item_id` = '" . (int)$markup_only->row['item_id'] . "' LIMIT 1");
		$supplier_update_method->invoke($model, (int)$markup_only->row['item_id']);
		$markup_only_state = $db->query("SELECT `last_markup`, `last_calculated_price` FROM `" . DB_PREFIX . "wob_supplier_product` WHERE `supplier_product_id` = '" . (int)$markup_only->row['supplier_product_id'] . "' LIMIT 1")->row;
		priceTestAssert((float)$markup_only_state['last_markup'] === 20.0 && (float)$markup_only_state['last_calculated_price'] === (float)$markup_only->row['target_price'], 'Markup-only apply must persist cron pricing without changing product.price.');
		$markup_only_current = $state_method->invoke($model, (int)$markup_only->row['item_id']);
		$markup_only_recovery = $reconcile_method->invoke($model, (int)$markup_only->row['item_id'], $markup_only_current);
		priceTestAssert(!empty($markup_only_recovery['terminal']), 'A completed markup-only apply must recover as updated before exclusion/source preflight.');
		$db->query('ROLLBACK');
	} catch (Throwable $exception) {
		$db->query('ROLLBACK');
		throw $exception;
	}

	$excluded_items = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` i INNER JOIN `" . DB_PREFIX . "wob_price_exclusion` e ON (e.product_id = i.product_id) WHERE i.run_id = '" . (int)$run_id . "'")->row;
	priceTestAssert((int)$excluded_items['total'] === 0, 'No permanently excluded product may enter the preview snapshot.');

	$after = $db->query($fingerprintSql)->row;
	priceTestAssert($before === $after, 'Creating a preview must not modify any catalog product price.');
	$supplier_after = $db->query($supplierFingerprintSql)->row;
	priceTestAssert($supplier_before === $supplier_after, 'Creating a preview must not modify managed supplier markup or calculated-price state.');

	$db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_run` SET `user_id` = '" . (int)$test_user_id . "', `percent` = '10.0000', `basis_version` = '0', `status` = 'preview', `exclusion_snapshot` = '{}', `error` = '', `date_created` = NOW()");
	$legacy_run_id = (int)$db->getLastId();
	$legacy_run = $model->getRun($legacy_run_id, $test_user_id);
	priceTestAssert(empty($legacy_run['can_apply']) && !empty($legacy_run['legacy_preview']), 'A legacy preview must be visibly invalidated and impossible to apply.');

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
	if ($drift_run_id > 0) {
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . (int)$drift_run_id . "'");
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . (int)$drift_run_id . "' AND `user_id` = '" . (int)$test_user_id . "'");
	}
	if ($legacy_run_id > 0) {
		$db->query("DELETE FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . (int)$legacy_run_id . "' AND `user_id` = '" . (int)$test_user_id . "'");
	}
}

echo "Global price preview tests passed; no product price was changed.\n";
