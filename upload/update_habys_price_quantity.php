<?php
declare(strict_types=1);

define('HABYS_IMPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/import_habys_new_offer.php';

const HABYS_LIGHT_FEED_URL = 'https://b2b.habys.com/edi/export-offer.php?client=worldofbeauty&language=eng&token=2fac4213aa0acffdb6b31b1&shop=1&type=light&format=xml&iof_3_0';
const LIGHT_FEED_PRICE_MODE = 'net'; // OpenCart stores product price without tax.
const WEB_DEFAULT_LIMIT_LIGHT = 200;

[$dryRun, $limit, $cleanupMissing, $start, $maxSeconds, $isWeb, $autoContinue] = resolveLightUpdateRuntimeOptions();

$root = __DIR__;
$configPath = $root . '/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found in {$root}\n");
    exit(1);
}

require_once $configPath;

runHabysLightUpdate($dryRun, $limit, $cleanupMissing, $start, $maxSeconds, $isWeb, $autoContinue);

function resolveLightUpdateRuntimeOptions(): array
{
    if (PHP_SAPI === 'cli') {
        $options = getopt('', ['dry-run', 'start::', 'limit::', 'max-seconds::', 'skip-cleanup']);

        return [
            array_key_exists('dry-run', $options),
            isset($options['limit']) ? max(0, (int) $options['limit']) : 0,
            !array_key_exists('skip-cleanup', $options),
            isset($options['start']) ? max(0, (int) $options['start']) : 0,
            isset($options['max-seconds']) ? max(0, (int) $options['max-seconds']) : 0,
            false,
            false,
        ];
    }

    $autoContinue = readBoolOption($_GET, 'auto_continue');
    initializeWebResponse($autoContinue);
    authorizeWebAccess();

    return [
        readBoolOption($_GET, 'dry_run'),
        readIntOption($_GET, 'limit', WEB_DEFAULT_LIMIT_LIGHT),
        !readBoolOption($_GET, 'skip_cleanup'),
        readIntOption($_GET, 'start'),
        readIntOption($_GET, 'max_seconds', resolveDefaultWebMaxSeconds(WEB_DEFAULT_MAX_SECONDS)),
        true,
        $autoContinue,
    ];
}

function runHabysLightUpdate(
    bool $dryRun,
    int $limit,
    bool $cleanupMissing,
    int $start = 0,
    int $maxSeconds = 0,
    bool $isWeb = false,
    bool $autoContinue = false
): void
{
    prepareLongRunningExecution($isWeb);
    beginManagedResponse($isWeb, $autoContinue);

    $db = openDatabaseConnection();
    $db->set_charset('utf8mb4');

    $manufacturerId = getManufacturerIdByName($db, 'Habys');
    $taxClassId = resolveLightUpdateTaxClassId($db);
    $feedFile = downloadFeedToTempFile(HABYS_LIGHT_FEED_URL);

    $stats = [
        'processed' => 0,
        'updated' => 0,
        'not_found' => 0,
        'disabled_missing' => 0,
        'zero_price' => 0,
        'zero_quantity' => 0,
        'errors' => 0,
    ];

    $seenSourceKeys = [];
    $reader = new XMLReader();
    $startedAt = microtime(true);
    $absoluteIndex = -1;
    $stoppedEarly = false;
    $nextUrl = null;

    if (!$reader->open($feedFile, null, LIBXML_NOCDATA | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Unable to open downloaded feed: {$feedFile}");
    }

    echo 'Feed: ' . HABYS_LIGHT_FEED_URL . PHP_EOL;
    echo 'Mode: ' . ($dryRun ? 'DRY RUN' : 'LIVE UPDATE') . PHP_EOL;
    echo 'Cleanup missing: ' . ($cleanupMissing ? 'yes' : 'no') . PHP_EOL;
    echo 'Tax class id: ' . $taxClassId . PHP_EOL;
    echo 'Start: ' . $start . PHP_EOL;
    if ($maxSeconds > 0) {
        echo 'Max seconds: ' . $maxSeconds . PHP_EOL;
    }
    echo PHP_EOL;

    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'product') {
                continue;
            }

            $absoluteIndex++;

            if ($absoluteIndex < $start) {
                continue;
            }

            if (shouldStopForTimeBudget($startedAt, $maxSeconds)) {
                $stoppedEarly = true;
                echo PHP_EOL . "Stopped before timeout after {$stats['processed']} processed items." . PHP_EOL;
                break;
            }

            $productXml = new SimpleXMLElement($reader->readOuterXML(), LIBXML_NOCDATA);
            $feedRow = mapHabysLightFeedRow($productXml);

            if ($feedRow === null) {
                continue;
            }

            $seenSourceKeys[$feedRow['source_key']] = true;
            $stats['processed']++;

            if ($feedRow['price'] <= 0.0) {
                $stats['zero_price']++;
            }

            if ($feedRow['quantity'] <= 0) {
                $stats['zero_quantity']++;
            }

            try {
                $existingProduct = findExistingProduct($db, $feedRow['source_key'], $feedRow['sku'], $manufacturerId);

                if ($existingProduct === null) {
                    $stats['not_found']++;
                    echo sprintf(
                        "[MISS] source=%s | sku=%s | qty=%d | price=%s%s\n",
                        $feedRow['source_key'],
                        $feedRow['sku'],
                        $feedRow['quantity'],
                        number_format($feedRow['price'], 2, '.', ''),
                        $dryRun ? ' | dry-run' : ''
                    );
                } else {
                    if (!$dryRun) {
                        updateProductPriceQuantity($db, (int) $existingProduct['product_id'], $feedRow, $taxClassId);
                    }

                    $stats['updated']++;
                    echo sprintf(
                        "[UPDATE] product_id=%d | sku=%s | qty=%d | price=%s%s\n",
                        (int) $existingProduct['product_id'],
                        $feedRow['sku'],
                        $feedRow['quantity'],
                        number_format($feedRow['price'], 2, '.', ''),
                        $dryRun ? ' | dry-run' : ''
                    );
                }
            } catch (Throwable $exception) {
                $stats['errors']++;
                fwrite(STDERR, sprintf(
                    "[ERROR] source=%s | sku=%s | %s\n",
                    $feedRow['source_key'],
                    $feedRow['sku'],
                    $exception->getMessage()
                ));
            }

            if ($limit > 0 && $stats['processed'] >= $limit) {
                $stoppedEarly = true;
                echo PHP_EOL . "Limit {$limit} reached, stopping early." . PHP_EOL;
                break;
            }

            if (shouldStopForTimeBudget($startedAt, $maxSeconds)) {
                $stoppedEarly = true;
                echo PHP_EOL . "Stopped before timeout after {$stats['processed']} processed items." . PHP_EOL;
                break;
            }
        }
    } finally {
        $reader->close();
        @unlink($feedFile);
    }

    $partialRun = $start > 0 || $stoppedEarly;

    if ($cleanupMissing && $partialRun) {
        echo PHP_EOL . 'Cleanup skipped because this was a partial run.' . PHP_EOL;
    } elseif ($cleanupMissing) {
        $stats['disabled_missing'] = disableMissingHabysProducts($db, array_keys($seenSourceKeys), $dryRun);
    }

    if ($stoppedEarly) {
        $nextStart = $start + $stats['processed'];
        echo PHP_EOL . 'Continue with start=' . $nextStart . PHP_EOL;

        if ($isWeb) {
            $nextUrl = buildWebContinuationUrl($nextStart, [
                'skip_cleanup' => '1',
            ]);
            echo $nextUrl . PHP_EOL;
        } else {
            echo buildCliContinuationCommand(basename(__FILE__), $nextStart, [
                'limit' => $limit,
                'max-seconds' => $maxSeconds,
                'skip-cleanup' => true,
                'dry-run' => $dryRun,
            ]) . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo 'Summary:' . PHP_EOL;
    echo '  Processed: ' . $stats['processed'] . PHP_EOL;
    echo '  Updated: ' . $stats['updated'] . PHP_EOL;
    echo '  Not found: ' . $stats['not_found'] . PHP_EOL;
    echo '  Disabled missing: ' . $stats['disabled_missing'] . PHP_EOL;
    echo '  Zero price in feed: ' . $stats['zero_price'] . PHP_EOL;
    echo '  Zero quantity in feed: ' . $stats['zero_quantity'] . PHP_EOL;
    echo '  Errors: ' . $stats['errors'] . PHP_EOL;

    finishManagedResponse($isWeb, $autoContinue, $nextUrl);
}

function mapHabysLightFeedRow(SimpleXMLElement $product): ?array
{
    $productId = trim((string) $product['id']);

    if ($productId === '') {
        return null;
    }

    $size = isset($product->sizes->size) ? $product->sizes->size[0] : null;
    $sizeAttributes = $size ? $size->attributes('iaiext', true) : null;

    $sku = '';

    if ($sizeAttributes && isset($sizeAttributes['code_external'])) {
        $sku = trim((string) $sizeAttributes['code_external']);
    }

    if ($sku === '' && $size !== null) {
        $sku = trim((string) $size['code']);
    }

    if ($sku === '') {
        $sku = 'HABYS-' . $productId;
    }

    $price = 0.0;

    if (isset($product->price[LIGHT_FEED_PRICE_MODE])) {
        $price = (float) $product->price[LIGHT_FEED_PRICE_MODE];
    } elseif ($size !== null && isset($size->price[LIGHT_FEED_PRICE_MODE])) {
        $price = (float) $size->price[LIGHT_FEED_PRICE_MODE];
    }

    $price = adjustLightFeedPrice($price);

    $quantity = 0;
    if ($size !== null && isset($size->stock['quantity'])) {
        $quantity = (int) round((float) $size->stock['quantity']);
    }

    return [
        'source_key' => SOURCE_PREFIX . ':' . $productId,
        'sku' => truncateText($sku, 64),
        'price' => round($price, 4),
        'quantity' => max(0, $quantity),
        'status' => ($quantity > 0 && $price > 0) ? 1 : 0,
    ];
}

function adjustLightFeedPrice(float $price): float
{
    if (function_exists('applyHabysPriceMultiplier')) {
        return applyHabysPriceMultiplier($price);
    }

    if ($price <= 0.0) {
        return 0.0;
    }

    return round($price * 2, 4);
}

function getManufacturerIdByName(mysqli $db, string $name): int
{
    $table = DB_PREFIX . 'manufacturer';
    $escapedName = $db->real_escape_string($name);
    $result = $db->query("SELECT manufacturer_id FROM `{$table}` WHERE LCASE(name) = LCASE('{$escapedName}') LIMIT 1");

    if ($result->num_rows === 0) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) $row['manufacturer_id'];
}

function resolveLightUpdateTaxClassId(mysqli $db): int
{
    if (function_exists('resolveHabysTaxClassId')) {
        return resolveHabysTaxClassId($db);
    }

    $configuredTaxClassId = getConfigInt($db, 'config_tax_class_id', 0);

    if ($configuredTaxClassId > 0) {
        return $configuredTaxClassId;
    }

    $taxClassTable = DB_PREFIX . 'tax_class';
    $result = $db->query("SELECT tax_class_id FROM `{$taxClassTable}` ORDER BY tax_class_id ASC LIMIT 1");

    if ($result->num_rows === 0) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) $row['tax_class_id'];
}

function updateProductPriceQuantity(mysqli $db, int $productId, array $feedRow, int $taxClassId): void
{
    $productTable = DB_PREFIX . 'product';

    $db->query(
        "UPDATE `{$productTable}` SET
            quantity = " . (int) $feedRow['quantity'] . ",
            price = " . (float) $feedRow['price'] . ",
            status = " . (int) $feedRow['status'] . ",
            tax_class_id = " . $taxClassId . ",
            date_modified = NOW()
         WHERE product_id = {$productId}"
    );
}

function disableMissingHabysProducts(mysqli $db, array $seenSourceKeys, bool $dryRun): int
{
    $productTable = DB_PREFIX . 'product';
    $seenMap = array_fill_keys($seenSourceKeys, true);
    $result = $db->query(
        "SELECT product_id, location
         FROM `{$productTable}`
         WHERE location LIKE '" . SOURCE_PREFIX . ":%'"
    );

    $disabled = 0;

    while ($row = $result->fetch_assoc()) {
        $location = (string) $row['location'];

        if (isset($seenMap[$location])) {
            continue;
        }

        $productId = (int) $row['product_id'];
        $disabled++;

        echo sprintf(
            "[CLEANUP] product_id=%d | source=%s%s\n",
            $productId,
            $location,
            $dryRun ? ' | dry-run' : ''
        );

        if ($dryRun) {
            continue;
        }

        $db->query(
            "UPDATE `{$productTable}` SET
                quantity = 0,
                status = 0,
                date_modified = NOW()
             WHERE product_id = {$productId}"
        );
    }

    return $disabled;
}
