<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once DIR_SYSTEM . 'library/wob_supplier/activeshop_feed.php';

const ACTIVESHOP_LEGACY_HEAVY_WEIGHT_KG = 2.0;
const ACTIVESHOP_LEGACY_LIGHT_MULTIPLIER = 1.2;
const ACTIVESHOP_LEGACY_HEAVY_MULTIPLIER = 1.4;

prepareActiveShopCronResponse();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);

if ($database->connect_errno) {
	throw new RuntimeException('Unable to connect to the OpenCart database.');
}

$database->set_charset('utf8mb4');
authorizeActiveShopCronRequest($database);
$dryRun = activeShopDryRunRequested();

$lockDirectory = rtrim(DIR_CACHE, '/\\') . '/activeshop-importer';

if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0755, true) && !is_dir($lockDirectory)) {
	throw new RuntimeException('Unable to create ActiveShop cron cache directory.');
}

$lockHandle = fopen($lockDirectory . '/operation.lock', 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
	activeShopCronOutput('ActiveShop update is already running.');
	exit;
}
$feed = new WobSupplierActiveShopFeed();
$cacheFile = $lockDirectory . '/cron-feed.xml';
$metadata = $feed->refreshCache($cacheFile);
$managedPricingAvailable = activeShopTableExists($database, DB_PREFIX . 'wob_supplier')
	&& activeShopTableExists($database, DB_PREFIX . 'wob_supplier_product');

$findProduct = $database->prepare('SELECT `product_id`, `status` FROM `' . DB_PREFIX . 'product` WHERE `sku` = ? ORDER BY `product_id`');
$updateManaged = $database->prepare('UPDATE `' . DB_PREFIX . 'product` SET `quantity` = ?, `price` = ?, `date_modified` = NOW() WHERE `product_id` = ?');
$updateLegacy = $database->prepare('UPDATE `' . DB_PREFIX . 'product` SET `quantity` = ?, `price` = ?, `status` = ?, `date_modified` = NOW() WHERE `product_id` = ?');
$findManagedMarkup = null;

if ($managedPricingAvailable) {
	$findManagedMarkup = $database->prepare(
		'SELECT sp.`last_markup` FROM `' . DB_PREFIX . 'wob_supplier_product` sp '
		. 'INNER JOIN `' . DB_PREFIX . 'wob_supplier` s ON (s.`supplier_id` = sp.`supplier_id`) '
		. 'WHERE s.`code` = \'activeshop\' AND sp.`product_id` = ? AND sp.`external_id` = ? '
		. 'AND sp.`last_imported` IS NOT NULL AND sp.`last_markup` IS NOT NULL LIMIT 1'
	);
}

if (!$findProduct || !$updateManaged || !$updateLegacy || ($managedPricingAvailable && !$findManagedMarkup)) {
	throw new RuntimeException('Unable to prepare ActiveShop update statements.');
}

$stats = array(
	'feed' => (int)$metadata['count'],
	'updated_managed' => 0,
	'updated_legacy' => 0,
	'not_found' => 0,
	'conflicts' => 0,
	'errors' => 0
);

foreach ($feed->iterate($cacheFile) as $item) {
	$sku = (string)$item['sku'];
	$quantity = max(0, (int)$item['quantity']);
	$feedPrice = (float)$item['feed_price'];
	$weight = max(0, (float)$item['weight']);

	try {
		$findProduct->bind_param('s', $sku);
		$findProduct->execute();
		$findProduct->store_result();

		if ($findProduct->num_rows === 0) {
			$stats['not_found']++;
			$findProduct->free_result();
			continue;
		}

		if ($findProduct->num_rows !== 1) {
			$stats['conflicts']++;
			$findProduct->free_result();
			continue;
		}

		$productId = 0;
		$currentStatus = 0;
		$findProduct->bind_result($productId, $currentStatus);
		$findProduct->fetch();
		$findProduct->free_result();

		$managedMarkup = activeShopManagedMarkup($findManagedMarkup, (int)$productId, $sku);

		if ($managedMarkup !== null) {
			$price = $feed->calculatePrice($feedPrice, $managedMarkup);
			if (!$dryRun) {
				$updateManaged->bind_param('idi', $quantity, $price, $productId);
				$updateManaged->execute();
			}
			$stats['updated_managed']++;
		} else {
			$multiplier = $weight > ACTIVESHOP_LEGACY_HEAVY_WEIGHT_KG ? ACTIVESHOP_LEGACY_HEAVY_MULTIPLIER : ACTIVESHOP_LEGACY_LIGHT_MULTIPLIER;
			$price = round($feedPrice * $multiplier, 4, PHP_ROUND_HALF_UP);
			$status = $quantity > 0 ? 1 : 0;
			if (!$dryRun) {
				$updateLegacy->bind_param('idii', $quantity, $price, $status, $productId);
				$updateLegacy->execute();
			}
			$stats['updated_legacy']++;
		}
	} catch (Throwable $exception) {
		$stats['errors']++;
		activeShopCronOutput('SKU ' . $sku . ': ' . $exception->getMessage());
	}
}

activeShopCronOutput('Mode: ' . ($dryRun ? 'DRY RUN' : 'LIVE UPDATE'));
activeShopCronOutput('ActiveShop update complete.');
activeShopCronOutput('Feed items: ' . $stats['feed']);
activeShopCronOutput('Managed products: ' . $stats['updated_managed']);
activeShopCronOutput('Legacy products: ' . $stats['updated_legacy']);
activeShopCronOutput('Not found: ' . $stats['not_found']);
activeShopCronOutput('SKU conflicts: ' . $stats['conflicts']);
activeShopCronOutput('Errors: ' . $stats['errors']);

$findProduct->close();
$updateManaged->close();
$updateLegacy->close();

if ($findManagedMarkup) {
	$findManagedMarkup->close();
}

$database->close();
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

function activeShopManagedMarkup(?mysqli_stmt $statement, int $productId, string $sku): ?float
{
	if (!$statement) {
		return null;
	}

	$statement->bind_param('is', $productId, $sku);
	$statement->execute();
	$statement->store_result();

	if ($statement->num_rows !== 1) {
		$statement->free_result();
		return null;
	}

	$markup = null;
	$statement->bind_result($markup);
	$statement->fetch();
	$statement->free_result();

	return $markup === null ? null : (float)$markup;
}

function activeShopTableExists(mysqli $database, string $table): bool
{
	$result = $database->query("SHOW TABLES LIKE '" . $database->real_escape_string($table) . "'");

	if (!$result) {
		return false;
	}

	$exists = $result->num_rows > 0;
	$result->free();

	return $exists;
}

function prepareActiveShopCronResponse(): void
{
	if (PHP_SAPI !== 'cli') {
		header('Content-Type: text/plain; charset=utf-8');
	}

	if (function_exists('set_time_limit')) {
		@set_time_limit(300);
	}
}

function activeShopDryRunRequested(): bool
{
	if (PHP_SAPI === 'cli') {
		$options = getopt('', array('live', 'dry-run'));
		return !array_key_exists('live', $options) || array_key_exists('dry-run', $options);
	}

	// Web cron calls are read-only unless the caller explicitly opts into the
	// live operation as well as presenting the separate cron key.
	return !isset($_GET['mode']) || strtolower(trim((string)$_GET['mode'])) !== 'live';
}

function authorizeActiveShopCronRequest(mysqli $database): void
{
	if (PHP_SAPI === 'cli') {
		return;
	}

	$keyQuery = $database->query(
		"SELECT `value` FROM `" . DB_PREFIX . "setting` "
		. "WHERE `store_id` = '0' AND `code` = 'module_activeshop_importer' "
		. "AND `key` = 'module_activeshop_importer_cron_key' LIMIT 1"
	);
	$expectedKey = $keyQuery && $keyQuery->num_rows ? trim((string)$keyQuery->fetch_assoc()['value']) : '';

	if ($keyQuery) {
		$keyQuery->free();
	}

	$providedKey = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
	if (strlen($expectedKey) < 32 || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
		http_response_code(403);
		activeShopCronOutput('Forbidden.');
		$database->close();
		exit;
	}
}

function activeShopCronOutput(string $message): void
{
	echo $message . PHP_EOL;
}
