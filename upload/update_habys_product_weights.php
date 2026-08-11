<?php
declare(strict_types=1);

define('HABYS_IMPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/import_habys_new_offer.php';

const WEB_DEFAULT_LIMIT_WEIGHTS = 200;

[$dryRun, $limit, $start, $maxSeconds, $isWeb, $autoContinue] = resolveHabysWeightUpdateRuntimeOptions();

$root = __DIR__;
$configPath = $root . '/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found in {$root}\n");
    exit(1);
}

require_once $configPath;

runHabysWeightUpdate($dryRun, $limit, $start, $maxSeconds, $isWeb, $autoContinue);

function resolveHabysWeightUpdateRuntimeOptions(): array
{
    if (PHP_SAPI === 'cli') {
        $options = getopt('', ['dry-run', 'start::', 'limit::', 'max-seconds::']);

        return [
            array_key_exists('dry-run', $options),
            isset($options['limit']) ? max(0, (int) $options['limit']) : 0,
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
        readIntOption($_GET, 'limit', WEB_DEFAULT_LIMIT_WEIGHTS),
        readIntOption($_GET, 'start'),
        readIntOption($_GET, 'max_seconds', resolveDefaultWebMaxSeconds(WEB_DEFAULT_MAX_SECONDS)),
        true,
        $autoContinue,
    ];
}

function runHabysWeightUpdate(
    bool $dryRun,
    int $limit,
    int $start = 0,
    int $maxSeconds = 0,
    bool $isWeb = false,
    bool $autoContinue = false
): void {
    prepareLongRunningExecution($isWeb);
    beginManagedResponse($isWeb, $autoContinue);

    $db = openDatabaseConnection();
    $db->set_charset('utf8mb4');

    $manufacturerId = getHabysManufacturerIdForWeights($db);
    $weightClassId = resolveHabysWeightClassId($db);
    $feedFile = downloadFeedToTempFile(HABYS_FEED_URL);

    $stats = [
        'processed' => 0,
        'updated' => 0,
        'disabled_zero_weight' => 0,
        'not_found' => 0,
        'errors' => 0,
    ];

    $reader = new XMLReader();
    $startedAt = microtime(true);
    $absoluteIndex = -1;
    $stoppedEarly = false;
    $nextUrl = null;

    if (!$reader->open($feedFile, null, LIBXML_NOCDATA | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Unable to open downloaded feed: {$feedFile}");
    }

    echo 'Feed: ' . HABYS_FEED_URL . PHP_EOL;
    echo 'Mode: ' . ($dryRun ? 'DRY RUN' : 'LIVE WEIGHT UPDATE') . PHP_EOL;
    echo 'Weight class id: ' . $weightClassId . PHP_EOL;
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
            $feedProduct = mapFeedProduct($productXml);

            if ($feedProduct === null) {
                continue;
            }

            $stats['processed']++;

            try {
                $existingProduct = findExistingProduct($db, $feedProduct['source_key'], $feedProduct['sku'], $manufacturerId);

                if ($existingProduct === null) {
                    $stats['not_found']++;
                    echo sprintf(
                        "[MISS] source=%s | sku=%s | weight=%s kg%s\n",
                        $feedProduct['source_key'],
                        $feedProduct['sku'],
                        number_format($feedProduct['weight'], 4, '.', ''),
                        $dryRun ? ' | dry-run' : ''
                    );
                } elseif ($feedProduct['weight'] <= 0.0) {
                    if (!$dryRun) {
                        disableExistingHabysProduct($db, (int) $existingProduct['product_id']);
                    }

                    $stats['disabled_zero_weight']++;
                    echo sprintf(
                        "[DISABLE] product_id=%d | sku=%s | no positive weight in feed%s\n",
                        (int) $existingProduct['product_id'],
                        $feedProduct['sku'],
                        $dryRun ? ' | dry-run' : ''
                    );
                } else {
                    if (!$dryRun) {
                        updateExistingHabysProductWeight(
                            $db,
                            (int) $existingProduct['product_id'],
                            (float) $feedProduct['weight'],
                            $weightClassId
                        );
                    }

                    $stats['updated']++;
                    echo sprintf(
                        "[UPDATE] product_id=%d | sku=%s | weight=%s kg%s\n",
                        (int) $existingProduct['product_id'],
                        $feedProduct['sku'],
                        number_format($feedProduct['weight'], 4, '.', ''),
                        $dryRun ? ' | dry-run' : ''
                    );
                }
            } catch (Throwable $exception) {
                $stats['errors']++;
                fwrite(STDERR, sprintf(
                    "[ERROR] source=%s | sku=%s | %s\n",
                    $feedProduct['source_key'],
                    $feedProduct['sku'],
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

    if ($stoppedEarly) {
        $nextStart = $start + $stats['processed'];
        echo PHP_EOL . 'Continue with start=' . $nextStart . PHP_EOL;

        if ($isWeb) {
            $nextUrl = buildWebContinuationUrl($nextStart);
            echo $nextUrl . PHP_EOL;
        } else {
            echo buildCliContinuationCommand(basename(__FILE__), $nextStart, [
                'limit' => $limit,
                'max-seconds' => $maxSeconds,
                'dry-run' => $dryRun,
            ]) . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo 'Summary:' . PHP_EOL;
    echo '  Processed: ' . $stats['processed'] . PHP_EOL;
    echo '  Updated: ' . $stats['updated'] . PHP_EOL;
    echo '  Disabled with no weight: ' . $stats['disabled_zero_weight'] . PHP_EOL;
    echo '  Not found: ' . $stats['not_found'] . PHP_EOL;
    echo '  Errors: ' . $stats['errors'] . PHP_EOL;

    finishManagedResponse($isWeb, $autoContinue, $nextUrl);
}

function updateExistingHabysProductWeight(mysqli $db, int $productId, float $weightKg, int $weightClassId): void
{
    $productTable = DB_PREFIX . 'product';

    $db->query(
        "UPDATE `{$productTable}` SET
            weight = " . (float) $weightKg . ",
            weight_class_id = " . (int) $weightClassId . ",
            date_modified = NOW()
         WHERE product_id = {$productId}"
    );
}

function disableExistingHabysProduct(mysqli $db, int $productId): void
{
    $productTable = DB_PREFIX . 'product';

    $db->query(
        "UPDATE `{$productTable}` SET
            status = 0,
            date_modified = NOW()
         WHERE product_id = {$productId}"
    );
}

function getHabysManufacturerIdForWeights(mysqli $db): int
{
    $table = DB_PREFIX . 'manufacturer';
    $escapedName = $db->real_escape_string('Habys');
    $result = $db->query("SELECT manufacturer_id FROM `{$table}` WHERE LCASE(name) = LCASE('{$escapedName}') LIMIT 1");

    if ($result->num_rows === 0) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) $row['manufacturer_id'];
}
