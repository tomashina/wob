<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

function categoryMapAssert($condition, $message) {
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

require_once DIR_APPLICATION . 'model/extension/module/activeshop_importer.php';
$model = new ModelExtensionModuleActiveshopImporter($registry);

$normalize = new ReflectionMethod($model, 'normalizeCategoryMatch');
$normalize->setAccessible(true);
$resolve = new ReflectionMethod($model, 'resolveAutomaticCategoryMatch');
$resolve->setAccessible(true);
$resolve_evidence = new ReflectionMethod($model, 'resolveCategoryEvidenceSets');
$resolve_evidence->setAccessible(true);
$get_evidence = new ReflectionMethod($model, 'getExistingProductCategoryEvidence');
$get_evidence->setAccessible(true);
$get_indexes = new ReflectionMethod($model, 'getLocalCategoryMatchIndexes');
$get_indexes->setAccessible(true);

$key = function ($value) use ($normalize, $model) {
	return $normalize->invoke($model, $value);
};
$indexes = array(
	'full' => array(
		$key('Equipment > Chairs') => array(101 => true),
		$key('Duplicate > Full path') => array(201 => true, 202 => true)
	),
	'leaf' => array(
		$key('Chairs') => array(101 => true, 102 => true),
		$key('Unique leaf') => array(301 => true),
		$key('Full path') => array(201 => true)
	)
);

$full = $resolve->invoke($model, '  EQUIPMENT  >  chairs ', $indexes);
categoryMapAssert((int)$full['category_id'] === 101 && $full['type'] === 'full_path', 'An exact normalized full path must win, even when its leaf is ambiguous.');
$leaf = $resolve->invoke($model, 'Unknown parent > Unique leaf', $indexes);
categoryMapAssert((int)$leaf['category_id'] === 301 && $leaf['type'] === 'leaf', 'A unique exact normalized leaf must be used when no full path matches.');
$ambiguous_leaf = $resolve->invoke($model, 'Unknown parent > Chairs', $indexes);
categoryMapAssert(!empty($ambiguous_leaf['ambiguous']) && !(int)$ambiguous_leaf['category_id'], 'An ambiguous leaf must remain unmapped.');
$ambiguous_full = $resolve->invoke($model, 'Duplicate > Full path', $indexes);
categoryMapAssert(!empty($ambiguous_full['ambiguous']) && !(int)$ambiguous_full['category_id'], 'An ambiguous full path must remain unmapped instead of falling back to its leaf.');

$ancestor_paths = array(
	10 => array(10 => true),
	20 => array(10 => true, 20 => true),
	30 => array(30 => true)
);
$deep_consensus = $resolve_evidence->invoke($model, array(
	array(10 => true, 20 => true),
	array(20 => true, 30 => true)
), $ancestor_paths);
categoryMapAssert((int)$deep_consensus['category_id'] === 20 && (int)$deep_consensus['product_count'] === 2, 'Existing products must agree on one deepest common category; an assigned ancestor must not compete with its descendant.');
$conflicting_evidence = $resolve_evidence->invoke($model, array(array(20 => true), array(30 => true)), $ancestor_paths);
categoryMapAssert(!empty($conflicting_evidence['ambiguous']) && !(int)$conflicting_evidence['category_id'], 'Conflicting existing-product categories must remain ambiguous.');
$single_multicategory = $resolve_evidence->invoke($model, array(array(20 => true, 30 => true)), $ancestor_paths);
categoryMapAssert(!empty($single_multicategory['ambiguous']) && !(int)$single_multicategory['category_id'], 'One product assigned to unrelated categories must not select either category arbitrarily.');

$fingerprint_sql = "SELECT COUNT(*) AS `products`, COALESCE(SUM(CRC32(CONCAT(`product_id`, ':', `price`, ':', `quantity`, ':', `status`))), 0) AS `fingerprint` FROM `" . DB_PREFIX . "product`";
$products_before = $db->query($fingerprint_sql)->row;
$db->query('START TRANSACTION');

try {
	$supplier_id = (int)$model->getSupplierId();
	$db->query("DELETE FROM `" . DB_PREFIX . "wob_supplier_category_map` WHERE `supplier_id` = '" . $supplier_id . "'");
	$live_evidence = $get_evidence->invoke($model);
	$live_indexes = $get_indexes->invoke($model);
	$uncorroborated_single_path = '';
	$corroborated_single_path = '';
	foreach ($live_evidence as $evidence_path => $evidence_row) {
		if (!empty($evidence_row['ambiguous']) || (int)$evidence_row['product_count'] !== 1) {
			continue;
		}
		$name_match = $resolve->invoke($model, $evidence_path, $live_indexes);
		if (!empty($name_match['category_id']) && (int)$name_match['category_id'] === (int)$evidence_row['category_id']) {
			$corroborated_single_path = $evidence_path;
		} elseif (empty($name_match['category_id'])) {
			$uncorroborated_single_path = $evidence_path;
		}
	}
	categoryMapAssert($uncorroborated_single_path !== '' && $corroborated_single_path !== '', 'The staged data must exercise both corroborated and uncorroborated single-product evidence.');

	$first = $model->autoMapSupplierCategories();
	categoryMapAssert((int)$first['considered'] > 0, 'The staged ActiveShop feed must expose category paths for automatic mapping.');
	categoryMapAssert((int)$first['mapped_existing_products'] > 0, 'Reconciled existing products must provide safe category mappings on the staged feed.');
	categoryMapAssert((int)$first['evidence_ambiguous'] > 0, 'Conflicting existing-product evidence must be counted and left for manual review.');
	categoryMapAssert((int)$first['evidence_insufficient'] > 0, 'Uncorroborated single-product evidence must be counted as insufficient.');
	$single_mappings = $model->getCategoryMappings(array($uncorroborated_single_path, $corroborated_single_path));
	categoryMapAssert(!isset($single_mappings[$uncorroborated_single_path]), 'One historical product without an independent name match must not map an entire supplier path.');
	categoryMapAssert(!empty($single_mappings[$corroborated_single_path]['category_id']), 'One historical product may map a path only when an exact normalized name independently confirms the same category.');

	$mapped_query = $db->query("SELECT `category_path`, `category_id` FROM `" . DB_PREFIX . "wob_supplier_category_map` WHERE `supplier_id` = '" . $supplier_id . "' AND `category_id` > 0 ORDER BY `supplier_category_map_id` ASC LIMIT 1");
	categoryMapAssert($mapped_query->num_rows === 1, 'At least one staged ActiveShop category should safely match a local category.');

	$path = $mapped_query->row['category_path'];
	$original_category_id = (int)$mapped_query->row['category_id'];
	$replacement_query = $db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category` WHERE `category_id` <> '" . $original_category_id . "' ORDER BY `category_id` ASC LIMIT 1");
	categoryMapAssert($replacement_query->num_rows === 1, 'The manual-preservation check needs a second local category.');
	$replacement_category_id = (int)$replacement_query->row['category_id'];

	$model->saveCategoryMappings(array($path => $replacement_category_id));
	$model->autoMapSupplierCategories();
	$preserved = $model->getCategoryMappings(array($path));
	categoryMapAssert((int)$preserved[$path]['category_id'] === $replacement_category_id, 'Automatic mapping must never overwrite a manual category choice.');

	$model->saveCategoryMappings(array($path => 0));
	$model->autoMapSupplierCategories();
	$cleared = $model->getCategoryMappings(array($path));
	categoryMapAssert(isset($cleared[$path]) && (int)$cleared[$path]['category_id'] === 0, 'An explicitly cleared mapping must remain cleared after automatic mapping.');

	$products_during = $db->query($fingerprint_sql)->row;
	categoryMapAssert($products_before === $products_during, 'Automatic category mapping must not mutate catalog products.');
} finally {
	$db->query('ROLLBACK');
}

$products_after = $db->query($fingerprint_sql)->row;
categoryMapAssert($products_before === $products_after, 'The automatic category mapping test must leave catalog products unchanged.');

echo "ActiveShop category auto-mapping tests passed; catalog products were not changed.\n";
