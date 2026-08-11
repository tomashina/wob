<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

const HABYS_FEED_URL = 'https://b2b.habys.com/edi/export-offer.php?client=worldofbeauty&language=eng&token=748b93f03e0e8e8dd915fe1&shop=1&type=full&format=xml&iof_3_0';
const TARGET_CATEGORY_ID = 909;
const TARGET_STORE_ID = 0;
const SOURCE_PREFIX = 'habys';
const HABYS_PRICE_MULTIPLIER = 2.0; // Feed price is doubled before saving to OpenCart.
const IMAGE_DIR_REL = 'catalog/habys';
const IMPORT_ZERO_PRICE_AS_ACTIVE = false; // Zero-price products stay disabled until pricing is confirmed.
const CURL_CONNECT_TIMEOUT = 20;
const CURL_TIMEOUT = 120;
const WEB_ACCESS_KEY = 'wob-habys-6f4cb9d3d922';
const WEB_DEFAULT_LIMIT_FULL = 10;
const WEB_DEFAULT_MAX_SECONDS = 20;
const WEB_TIMEOUT_SAFETY_SECONDS = 5;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!defined('HABYS_IMPORT_LIBRARY_ONLY')) {
    [$dryRun, $limit, $downloadImages, $cleanupMissing, $start, $maxSeconds, $isWeb, $autoContinue] = resolveImportRuntimeOptions();

    $root = __DIR__;
    $configPath = $root . '/config.php';

    if (!is_file($configPath)) {
        fwrite(STDERR, "config.php not found in {$root}\n");
        exit(1);
    }

    require_once $configPath;

    main($root, $dryRun, $limit, $downloadImages, $cleanupMissing, $start, $maxSeconds, $isWeb, $autoContinue);
}

function main(
    string $root,
    bool $dryRun,
    int $limit,
    bool $downloadImages,
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

    $imageBaseDir = resolveImageBaseDir($root);
    $imageTargetDir = rtrim($imageBaseDir, '/\\') . '/' . IMAGE_DIR_REL;

    if ($downloadImages && !$dryRun && !is_dir($imageTargetDir) && !mkdir($imageTargetDir, 0755, true) && !is_dir($imageTargetDir)) {
        throw new RuntimeException("Unable to create image directory: {$imageTargetDir}");
    }

    $languages = getLanguages($db);
    if (!$languages) {
        throw new RuntimeException('No active languages found in the OpenCart database.');
    }

    ensureCategoryExists($db, TARGET_CATEGORY_ID);

    $defaults = [
        'stock_status_id' => getConfigInt($db, 'config_stock_status_id', 0),
        'weight_class_id' => resolveHabysWeightClassId($db),
        'length_class_id' => getConfigInt($db, 'config_length_class_id', 1),
        'tax_class_id' => resolveHabysTaxClassId($db),
    ];

    $manufacturerId = ensureManufacturer($db, 'Habys', $dryRun);
    $feedFile = downloadFeedToTempFile(HABYS_FEED_URL);

    $stats = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'disabled_missing' => 0,
        'image_failures' => 0,
        'errors' => 0,
        'zero_price' => 0,
        'zero_quantity' => 0,
    ];

    $seenSourceKeys = [];
    $reader = new XMLReader();
    $startedAt = microtime(true);
    $absoluteIndex = -1;
    $stoppedEarly = false;
    $stopReason = '';
    $nextUrl = null;

    if (!$reader->open($feedFile, null, LIBXML_NOCDATA | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("Unable to open downloaded feed: {$feedFile}");
    }

    echo 'Feed: ' . HABYS_FEED_URL . PHP_EOL;
    echo 'Category: ' . TARGET_CATEGORY_ID . PHP_EOL;
    echo 'Mode: ' . ($dryRun ? 'DRY RUN' : 'LIVE IMPORT') . PHP_EOL;
    echo 'Images: ' . ($downloadImages ? 'download' : 'skip') . PHP_EOL;
    echo 'Cleanup missing: ' . ($cleanupMissing ? 'yes' : 'no') . PHP_EOL;
    echo 'Tax class id: ' . $defaults['tax_class_id'] . PHP_EOL;
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
                $stopReason = 'time';
                echo PHP_EOL . "Stopped before timeout after {$stats['processed']} processed items." . PHP_EOL;
                break;
            }

            $productXml = new SimpleXMLElement($reader->readOuterXML(), LIBXML_NOCDATA);
            $feedProduct = mapFeedProduct($productXml);

            if (!$feedProduct) {
                continue;
            }

            $seenSourceKeys[$feedProduct['source_key']] = true;
            $stats['processed']++;

            if ($feedProduct['price'] <= 0.0) {
                $stats['zero_price']++;
            }

            if ($feedProduct['quantity'] <= 0) {
                $stats['zero_quantity']++;
            }

            try {
                $imagePaths = [];

                if ($downloadImages) {
                    $imagePaths = downloadProductImages(
                        $feedProduct['image_urls'],
                        $feedProduct['source_key'],
                        $imageBaseDir,
                        $dryRun
                    );

                    $feedProduct['image'] = $imagePaths ? $imagePaths[0] : '';
                    $feedProduct['additional_images'] = $imagePaths ? array_slice($imagePaths, 1) : [];
                }

                $feedProduct['replace_images'] = $downloadImages;

              $existingProduct = findExistingProduct($db, $feedProduct['source_key'], $feedProduct['sku'], $manufacturerId);

if ($existingProduct !== null) {
    echo sprintf(
        "[SKIP] %s | sku=%s | already exists%s\n",
        $feedProduct['name'],
        $feedProduct['sku'],
        $dryRun ? ' | dry-run' : ''
    );
    continue;
}

if (!$dryRun) {
    upsertProduct(
        $db,
        null,
        $feedProduct,
        $languages,
        $manufacturerId,
        $defaults
    );
}

echo sprintf(
    "[CREATE] %s | sku=%s | qty=%d | price=%s%s\n",
    $feedProduct['name'],
    $feedProduct['sku'],
    $feedProduct['quantity'],
    number_format($feedProduct['price'], 2, '.', ''),
    $dryRun ? ' | dry-run' : ''
);

$stats['created']++;
            } catch (Throwable $exception) {
                $stats['errors']++;
                fwrite(STDERR, sprintf(
                    "[ERROR] %s | sku=%s | %s\n",
                    $feedProduct['name'],
                    $feedProduct['sku'],
                    $exception->getMessage()
                ));
            }

            if ($limit > 0 && $stats['processed'] >= $limit) {
                $stoppedEarly = true;
                $stopReason = 'limit';
                echo PHP_EOL . "Limit {$limit} reached, stopping early." . PHP_EOL;
                break;
            }

            if (shouldStopForTimeBudget($startedAt, $maxSeconds)) {
                $stoppedEarly = true;
                $stopReason = 'time';
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
        $stats['disabled_missing'] = cleanupMissingProducts($db, array_keys($seenSourceKeys), $dryRun);
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
                'skip-images' => !$downloadImages,
                'skip-cleanup' => true,
                'dry-run' => $dryRun,
            ]) . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo 'Summary:' . PHP_EOL;
    echo '  Processed: ' . $stats['processed'] . PHP_EOL;
    echo '  Created: ' . $stats['created'] . PHP_EOL;
    echo '  Updated: ' . $stats['updated'] . PHP_EOL;
    echo '  Disabled missing: ' . $stats['disabled_missing'] . PHP_EOL;
    echo '  Zero price in feed: ' . $stats['zero_price'] . PHP_EOL;
    echo '  Zero quantity in feed: ' . $stats['zero_quantity'] . PHP_EOL;
    echo '  Errors: ' . $stats['errors'] . PHP_EOL;

    if (!$dryRun && $stats['processed'] > 0 && $stopReason !== 'time') {
        echo PHP_EOL . 'Tip: after import refresh OpenCart cache/modifications in admin if category pages do not update immediately.' . PHP_EOL;
    }

    finishManagedResponse($isWeb, $autoContinue, $nextUrl);
}

function resolveImportRuntimeOptions(): array
{
    if (PHP_SAPI === 'cli') {
        $options = getopt('', ['dry-run', 'start::', 'limit::', 'max-seconds::', 'skip-images', 'skip-cleanup']);

        return [
            array_key_exists('dry-run', $options),
            isset($options['limit']) ? max(0, (int) $options['limit']) : 0,
            !array_key_exists('skip-images', $options),
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
        readIntOption($_GET, 'limit', WEB_DEFAULT_LIMIT_FULL),
        !readBoolOption($_GET, 'skip_images'),
        !readBoolOption($_GET, 'skip_cleanup'),
        readIntOption($_GET, 'start'),
        readIntOption($_GET, 'max_seconds', resolveDefaultWebMaxSeconds(WEB_DEFAULT_MAX_SECONDS)),
        true,
        $autoContinue,
    ];
}

function prepareLongRunningExecution(bool $isWeb): void
{
    if ($isWeb && function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }
}

function initializeWebResponse(bool $autoContinue): void
{
    if (!headers_sent()) {
        header('Content-Type: ' . ($autoContinue ? 'text/html' : 'text/plain') . '; charset=utf-8');
    }
}

function beginManagedResponse(bool $isWeb, bool $autoContinue): void
{
    if ($isWeb && $autoContinue) {
        ob_start();
    }
}

function finishManagedResponse(bool $isWeb, bool $autoContinue, ?string $nextUrl = null): void
{
    if (!$isWeb || !$autoContinue) {
        return;
    }

    $output = ob_get_clean();
    echo renderAutoContinueHtml($output === false ? '' : $output, $nextUrl);
}

function authorizeWebAccess(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $providedKey = trim((string) ($_GET['key'] ?? ''));

    if ($providedKey !== '' && hash_equals(WEB_ACCESS_KEY, $providedKey)) {
        return;
    }

    http_response_code(403);
    exit("Forbidden\n");
}

function readBoolOption(array $source, string $key): bool
{
    if (!array_key_exists($key, $source)) {
        return false;
    }

    return parseBooleanValue($source[$key]);
}

function readIntOption(array $source, string $key, int $default = 0): int
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }

    return max(0, (int) $source[$key]);
}

function resolveDefaultWebMaxSeconds(int $fallback): int
{
    $maxExecutionTime = (int) ini_get('max_execution_time');

    if ($maxExecutionTime <= 0) {
        return $fallback;
    }

    return max(1, min($fallback, $maxExecutionTime - WEB_TIMEOUT_SAFETY_SECONDS));
}

function parseBooleanValue($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));

    if ($value === '') {
        return true;
    }

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function shouldStopForTimeBudget(float $startedAt, int $maxSeconds): bool
{
    return $maxSeconds > 0 && (microtime(true) - $startedAt) >= $maxSeconds;
}

function buildWebContinuationUrl(int $nextStart, array $overrides = []): string
{
    $params = $_GET;
    $params['start'] = (string) $nextStart;

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = (string) $value;
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = (string) ($_SERVER['PHP_SELF'] ?? '');

    return $scheme . '://' . $host . $path . '?' . http_build_query($params);
}

function renderAutoContinueHtml(string $output, ?string $nextUrl = null): string
{
    $escapedOutput = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    $escapedNextUrl = $nextUrl !== null ? htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') : '';
    $refresh = $nextUrl !== null ? '<meta http-equiv="refresh" content="1;url=' . $escapedNextUrl . '">' : '';
    $message = $nextUrl !== null
        ? '<p>Automatski nastavljam za 1 sekundu. Ako redirect ne krene, otvori <a href="' . $escapedNextUrl . '">sljedeći chunk</a>.</p>'
        : '<p>Import je završio.</p>';

    return '<!doctype html><html lang="hr"><head><meta charset="utf-8"><title>Habys Import</title>' .
        $refresh .
        '<style>body{font-family:Menlo,Monaco,monospace;background:#111;color:#f5f5f5;padding:24px;}a{color:#8fd3ff;}pre{white-space:pre-wrap;background:#1b1b1b;border:1px solid #333;padding:16px;border-radius:8px;}</style>' .
        '</head><body><h1>Habys Import</h1>' . $message . '<pre>' . $escapedOutput . '</pre></body></html>';
}

function buildCliContinuationCommand(string $scriptName, int $nextStart, array $options = []): string
{
    $parts = ['php', $scriptName, '--start=' . $nextStart];

    foreach ($options as $key => $value) {
        if (is_bool($value)) {
            if ($value) {
                $parts[] = '--' . $key;
            }
            continue;
        }

        if ((int) $value > 0 || (string) $value !== '0') {
            $parts[] = '--' . $key . '=' . $value;
        }
    }

    return implode(' ', $parts);
}

function openDatabaseConnection(): mysqli
{
    $hosts = [DB_HOSTNAME];

    if (DB_HOSTNAME === 'localhost') {
        $hosts[] = '127.0.0.1';
    }

    $lastException = null;

    foreach (array_unique($hosts) as $host) {
        try {
            return new mysqli($host, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
        } catch (Throwable $exception) {
            $lastException = $exception;
        }
    }

    throw new RuntimeException('Unable to connect to MySQL: ' . ($lastException ? $lastException->getMessage() : 'unknown error'));
}

function resolveImageBaseDir(string $root): string
{
    $localImageDir = $root . '/image';

    if (is_dir($localImageDir)) {
        return $localImageDir;
    }

    if (defined('DIR_IMAGE') && is_dir(DIR_IMAGE)) {
        return rtrim(DIR_IMAGE, '/\\');
    }

    return $localImageDir;
}

function getLanguages(mysqli $db): array
{
    $table = DB_PREFIX . 'language';
    $result = $db->query("SELECT language_id, code, locale, name FROM `{$table}` WHERE status = 1 ORDER BY sort_order, language_id");
    $languages = [];

    while ($row = $result->fetch_assoc()) {
        $languages[] = [
            'language_id' => (int) $row['language_id'],
            'code' => (string) $row['code'],
            'locale' => (string) $row['locale'],
            'name' => (string) $row['name'],
        ];
    }

    return $languages;
}

function ensureCategoryExists(mysqli $db, int $categoryId): void
{
    $table = DB_PREFIX . 'category';
    $result = $db->query("SELECT category_id FROM `{$table}` WHERE category_id = {$categoryId} LIMIT 1");

    if ($result->num_rows === 0) {
        throw new RuntimeException("Category {$categoryId} does not exist.");
    }
}

function getConfigInt(mysqli $db, string $key, int $fallback): int
{
    $table = DB_PREFIX . 'setting';
    $escapedKey = $db->real_escape_string($key);
    $result = $db->query(
        "SELECT `value`
         FROM `{$table}`
         WHERE store_id = " . TARGET_STORE_ID . " AND `key` = '{$escapedKey}'
         ORDER BY setting_id DESC
         LIMIT 1"
    );

    if ($result->num_rows === 0) {
        return $fallback;
    }

    $row = $result->fetch_assoc();
    return (int) $row['value'];
}

function resolveHabysTaxClassId(mysqli $db): int
{
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

function resolveHabysWeightClassId(mysqli $db): int
{
    $configuredWeightClassId = getConfigInt($db, 'config_weight_class_id', 1);
    $table = DB_PREFIX . 'weight_class';
    $descriptionTable = DB_PREFIX . 'weight_class_description';
    $result = $db->query(
        "SELECT wc.weight_class_id
         FROM `{$table}` wc
         LEFT JOIN `{$descriptionTable}` wcd ON (wc.weight_class_id = wcd.weight_class_id)
         WHERE LCASE(wcd.unit) IN ('kg', 'kgs')
         ORDER BY ABS(wc.value - 1) ASC, wc.weight_class_id ASC
         LIMIT 1"
    );

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int) $row['weight_class_id'];
    }

    return $configuredWeightClassId > 0 ? $configuredWeightClassId : 1;
}

function ensureManufacturer(mysqli $db, string $name, bool $dryRun): int
{
    $manufacturerTable = DB_PREFIX . 'manufacturer';
    $manufacturerToStoreTable = DB_PREFIX . 'manufacturer_to_store';
    $escapedName = $db->real_escape_string($name);
    $result = $db->query("SELECT manufacturer_id FROM `{$manufacturerTable}` WHERE LCASE(name) = LCASE('{$escapedName}') LIMIT 1");

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int) $row['manufacturer_id'];
    }

    if ($dryRun) {
        echo "[DRY-RUN] Manufacturer '{$name}' would be created." . PHP_EOL;
        return 0;
    }

    $db->query("INSERT INTO `{$manufacturerTable}` SET name = '{$escapedName}', sort_order = 0");
    $manufacturerId = (int) $db->insert_id;
    $db->query(
        "INSERT INTO `{$manufacturerToStoreTable}` SET manufacturer_id = {$manufacturerId}, store_id = " . TARGET_STORE_ID
    );

    return $manufacturerId;
}

function downloadFeedToTempFile(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The cURL extension is required to download the Habys feed.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'habys-feed-');

    if ($tmpFile === false) {
        throw new RuntimeException('Unable to create a temporary file for the feed.');
    }

    $handle = fopen($tmpFile, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Unable to open a temporary file for writing the feed.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'WOB-Habys-Importer/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $success = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);
    fclose($handle);

    if ($success === false || $httpCode >= 400) {
        @unlink($tmpFile);
        throw new RuntimeException('Feed download failed: ' . ($error ?: 'HTTP ' . $httpCode));
    }

    return $tmpFile;
}

function mapFeedProduct(SimpleXMLElement $product): ?array
{
    $productId = trim((string) $product['id']);

    if ($productId === '') {
        return null;
    }

    $sourceKey = SOURCE_PREFIX . ':' . $productId;
    $name = firstLocalizedValue($product->description->name, 'eng');
    $description = firstLocalizedValue($product->description->long_desc, 'eng');
    $description = normalizeDescriptionHtml($description);

    $size = null;
    if (isset($product->sizes->size)) {
        $size = $product->sizes->size[0];
    }

    $sku = trim((string) $product['code_on_card']);
    if ($sku === '' && $size !== null) {
        $sku = trim((string) $size['code']);
    }
    if ($sku === '') {
        $sku = 'HABYS-' . $productId;
    }

    $ean = $size !== null ? trim((string) $size['code_producer']) : '';
    $weight = extractHabysWeightKg($product, $size);
    $price = 0.0;

    if ($size !== null && isset($size->price['net'])) {
        $price = (float) $size->price['net'];
    } elseif (isset($product->price['net'])) {
        $price = (float) $product->price['net'];
    } elseif ($size !== null && isset($size->price['gross'])) {
        $price = (float) $size->price['gross'];
    } elseif (isset($product->price['gross'])) {
        $price = (float) $product->price['gross'];
    }

    $price = applyHabysPriceMultiplier($price);

    $quantity = 0;
    if ($size !== null && isset($size->stock['available_stock_quantity'])) {
        $quantity = (int) round((float) $size->stock['available_stock_quantity']);
    } elseif ($size !== null && isset($size->stock['quantity'])) {
        $quantity = (int) round((float) $size->stock['quantity']);
    }

    $imageUrls = [];
    if (isset($product->images->large->image)) {
        foreach ($product->images->large->image as $image) {
            $url = trim((string) $image['url']);
            if ($url !== '') {
                $imageUrls[] = $url;
            }
        }
    } elseif (isset($product->images->image)) {
        foreach ($product->images->image as $image) {
            $url = trim((string) $image['url']);
            if ($url !== '') {
                $imageUrls[] = $url;
            }
        }
    }

    $imageUrls = array_values(array_unique($imageUrls));

    return [
        'source_key' => $sourceKey,
        'product_id_external' => $productId,
        'name' => $name !== '' ? $name : $sku,
        'description' => $description,
        'sku' => truncateText($sku, 64),
        'model' => truncateText($sku, 64),
        'ean' => truncateText($ean, 32),
        'location' => truncateText($sourceKey, 128),
        'mpn' => truncateText($sourceKey, 64),
        'price' => round($price, 4),
        'quantity' => max(0, $quantity),
        'weight' => $weight > 0 ? round($weight, 4) : 0.0,
        'status' => ($weight > 0 && $quantity > 0 && ($price > 0 || IMPORT_ZERO_PRICE_AS_ACTIVE)) ? 1 : 0,
        'image_urls' => $imageUrls,
        'image' => '',
        'additional_images' => [],
    ];
}

function extractHabysWeightKg(SimpleXMLElement $product, ?SimpleXMLElement $size): float
{
    $candidates = [
        extractXmlNumericValue($size, 'weight'),
        extractXmlNumericValue($product, 'weight'),
        extractXmlNumericValue($size, 'gross_weight'),
        extractXmlNumericValue($product, 'gross_weight'),
        extractXmlNumericValue($size, 'net_weight'),
        extractXmlNumericValue($product, 'net_weight'),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate > 0.0) {
            return $candidate;
        }
    }

    return 0.0;
}

function extractXmlNumericValue(?SimpleXMLElement $node, string $field): ?float
{
    if ($node === null) {
        return null;
    }

    if (isset($node->{$field})) {
        $fieldNode = $node->{$field};
        $value = normalizeNumericString((string) $fieldNode);

        if ($value !== null) {
            $unit = resolveXmlUnitAttribute($fieldNode, $field);
            return normalizeHabysWeightValue($value, $unit);
        }
    }

    if (isset($node[$field])) {
        $value = normalizeNumericString((string) $node[$field]);

        if ($value !== null) {
            $unit = resolveXmlUnitAttribute($node, $field);
            return normalizeHabysWeightValue($value, $unit);
        }
    }

    return null;
}

function normalizeNumericString(string $value): ?float
{
    $value = trim(str_replace(',', '.', $value));

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function resolveXmlUnitAttribute(SimpleXMLElement $node, string $field): string
{
    $attributeNames = [
        'unit',
        $field . '_unit',
        'measure_unit',
        'unit_type',
        'uom',
    ];

    foreach ($attributeNames as $attributeName) {
        if (!isset($node[$attributeName])) {
            continue;
        }

        $value = strtolower(trim((string) $node[$attributeName]));

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function normalizeHabysWeightValue(float $value, string $unit): float
{
    $unit = strtolower(trim($unit));

    if (in_array($unit, ['g', 'gr', 'gram', 'grams'], true)) {
        return $value / 1000;
    }

    return $value;
}

function firstLocalizedValue($nodes, string $preferredLang): string
{
    if (!$nodes) {
        return '';
    }

    $fallback = '';

    foreach ($nodes as $node) {
        $lang = strtolower((string) $node->attributes('xml', true)->lang);
        $value = trim((string) $node);

        if ($value === '') {
            continue;
        }

        if ($fallback === '') {
            $fallback = $value;
        }

        if ($lang === strtolower($preferredLang)) {
            return $value;
        }
    }

    return $fallback;
}

function truncateText(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function normalizeDescriptionHtml(string $html): string
{
    if ($html === '') {
        return '';
    }

    $normalized = preg_replace('~(src|href)=([\'"])/~i', '$1=$2https://b2b.habys.com/', $html);
    return trim((string) $normalized);
}

function applyHabysPriceMultiplier(float $price): float
{
    if ($price <= 0.0) {
        return 0.0;
    }

    return round($price * HABYS_PRICE_MULTIPLIER, 4);
}

function downloadProductImages(array $urls, string $sourceKey, string $imageBaseDir, bool $dryRun): array
{
    if (!$urls) {
        return [];
    }

    $safeDirName = sanitizePathComponent(str_replace(':', '-', $sourceKey));
    $relativeDir = IMAGE_DIR_REL . '/' . $safeDirName;
    $absoluteDir = rtrim($imageBaseDir, '/\\') . '/' . $relativeDir;
    $storedPaths = [];

    if (!$dryRun && !is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException("Unable to create image directory {$absoluteDir}");
    }

    foreach ($urls as $index => $url) {
        $extension = detectImageExtension($url);
        $fileName = sprintf('%02d-%s.%s', $index + 1, substr(md5($url), 0, 12), $extension);
        $absolutePath = $absoluteDir . '/' . $fileName;
        $relativePath = $relativeDir . '/' . $fileName;

        if (!$dryRun && !is_file($absolutePath)) {
            [$bytes, $error] = fetchRemoteBinary($url);

            if ($bytes === null) {
                throw new RuntimeException("Image download failed for {$url}: {$error}");
            }

            if (file_put_contents($absolutePath, $bytes) === false) {
                throw new RuntimeException("Unable to write image file {$absolutePath}");
            }
        }

        $storedPaths[] = $relativePath;
    }

    return $storedPaths;
}

function detectImageExtension(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === '') {
        return 'jpg';
    }

    return preg_replace('/[^a-z0-9]/', '', $extension) ?: 'jpg';
}

function fetchRemoteBinary(string $url): array
{
    if (!function_exists('curl_init')) {
        return [null, 'cURL extension is missing'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'WOB-Habys-Importer/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $data = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $httpCode >= 400) {
        return [null, $error ?: ('HTTP ' . $httpCode)];
    }

    return [$data, null];
}

function sanitizePathComponent(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    $value = trim((string) $value, '-.');
    return $value !== '' ? $value : 'item';
}

function findExistingProduct(mysqli $db, string $sourceKey, string $sku, int $manufacturerId): ?array
{
    $productTable = DB_PREFIX . 'product';
    $sourceKeyEscaped = $db->real_escape_string($sourceKey);
    $skuEscaped = $db->real_escape_string($sku);
    $manufacturerFilter = $manufacturerId > 0 ? ' AND manufacturer_id = ' . $manufacturerId : '';

    $queries = [
        "SELECT product_id, location FROM `{$productTable}` WHERE location = '{$sourceKeyEscaped}' LIMIT 1",
        "SELECT product_id, location FROM `{$productTable}` WHERE mpn = '{$sourceKeyEscaped}' LIMIT 1",
    ];

    if ($sku !== '') {
        $queries[] = "SELECT product_id, location FROM `{$productTable}` WHERE sku = '{$skuEscaped}'{$manufacturerFilter} LIMIT 1";
    }

    foreach ($queries as $sql) {
        $result = $db->query($sql);
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }

    return null;
}

function upsertProduct(
    mysqli $db,
    ?array $existingProduct,
    array $product,
    array $languages,
    int $manufacturerId,
    array $defaults
): void {
    $productTable = DB_PREFIX . 'product';
    $productDescriptionTable = DB_PREFIX . 'product_description';
    $productImageTable = DB_PREFIX . 'product_image';

    $db->begin_transaction();

    try {
        $nameEscaped = $db->real_escape_string($product['name']);
        $descriptionEscaped = $db->real_escape_string($product['description']);
        $skuEscaped = $db->real_escape_string($product['sku']);
        $modelEscaped = $db->real_escape_string($product['model']);
        $eanEscaped = $db->real_escape_string($product['ean']);
        $locationEscaped = $db->real_escape_string($product['location']);
        $mpnEscaped = $db->real_escape_string($product['mpn']);
        $imageEscaped = $db->real_escape_string($product['image']);

        if ($existingProduct === null) {
            $db->query(
                "INSERT INTO `{$productTable}` SET
                    model = '{$modelEscaped}',
                    sku = '{$skuEscaped}',
                    upc = '',
                    ean = '{$eanEscaped}',
                    jan = '',
                    isbn = '',
                    mpn = '{$mpnEscaped}',
                    location = '{$locationEscaped}',
                    quantity = " . (int) $product['quantity'] . ",
                    minimum = 1,
                    subtract = 1,
                    stock_status_id = " . (int) $defaults['stock_status_id'] . ",
                    date_available = CURDATE(),
                    manufacturer_id = " . (int) $manufacturerId . ",
                    shipping = 1,
                    price = " . (float) $product['price'] . ",
                    points = 0,
                    weight = " . (float) $product['weight'] . ",
                    weight_class_id = " . (int) $defaults['weight_class_id'] . ",
                    length = 0,
                    width = 0,
                    height = 0,
                    length_class_id = " . (int) $defaults['length_class_id'] . ",
                    status = " . (int) $product['status'] . ",
                    tax_class_id = " . (int) $defaults['tax_class_id'] . ",
                    sort_order = 0,
                    image = '{$imageEscaped}',
                    date_added = NOW(),
                    date_modified = NOW()"
            );

            $productId = (int) $db->insert_id;
        } else {
            $productId = (int) $existingProduct['product_id'];

            $db->query(
                "UPDATE `{$productTable}` SET
                    model = '{$modelEscaped}',
                    sku = '{$skuEscaped}',
                    ean = '{$eanEscaped}',
                    mpn = '{$mpnEscaped}',
                    location = '{$locationEscaped}',
                    quantity = " . (int) $product['quantity'] . ",
                    minimum = 1,
                    subtract = 1,
                    stock_status_id = " . (int) $defaults['stock_status_id'] . ",
                    date_available = CURDATE(),
                    manufacturer_id = " . (int) $manufacturerId . ",
                    shipping = 1,
                    price = " . (float) $product['price'] . ",
                    weight = " . (float) $product['weight'] . ",
                    weight_class_id = " . (int) $defaults['weight_class_id'] . ",
                    length_class_id = " . (int) $defaults['length_class_id'] . ",
                    status = " . (int) $product['status'] . ",
                    tax_class_id = " . (int) $defaults['tax_class_id'] . ",
                    date_modified = NOW()
                 WHERE product_id = {$productId}"
            );

            $db->query("DELETE FROM `{$productDescriptionTable}` WHERE product_id = {$productId}");
            if (!empty($product['replace_images'])) {
                $db->query("UPDATE `{$productTable}` SET image = '{$imageEscaped}' WHERE product_id = {$productId}");
                $db->query("DELETE FROM `{$productImageTable}` WHERE product_id = {$productId}");
            }
        }

        foreach ($languages as $language) {
            $languageId = (int) $language['language_id'];
            $db->query(
                "INSERT INTO `{$productDescriptionTable}` SET
                    product_id = {$productId},
                    language_id = {$languageId},
                    name = '{$nameEscaped}',
                    description = '{$descriptionEscaped}',
                    tag = '',
                    meta_title = '{$nameEscaped}',
                    meta_description = '{$nameEscaped}',
                    meta_keyword = ''"
            );
        }

        ensureStoreAssignment($db, $productId, TARGET_STORE_ID);
        ensureCategoryAssignment($db, $productId, TARGET_CATEGORY_ID);

        if (!empty($product['replace_images'])) {
            foreach ($product['additional_images'] as $sortOrder => $imagePath) {
                $imagePathEscaped = $db->real_escape_string($imagePath);
                $db->query(
                    "INSERT INTO `{$productImageTable}` SET
                        product_id = {$productId},
                        image = '{$imagePathEscaped}',
                        sort_order = " . ((int) $sortOrder + 1)
                );
            }
        }

        ensureSeoUrl($db, $productId, $product['name'], $languages);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function ensureStoreAssignment(mysqli $db, int $productId, int $storeId): void
{
    $table = DB_PREFIX . 'product_to_store';
    $result = $db->query("SELECT product_id FROM `{$table}` WHERE product_id = {$productId} AND store_id = {$storeId} LIMIT 1");

    if ($result->num_rows === 0) {
        $db->query("INSERT INTO `{$table}` SET product_id = {$productId}, store_id = {$storeId}");
    }
}

function ensureCategoryAssignment(mysqli $db, int $productId, int $categoryId): void
{
    $table = DB_PREFIX . 'product_to_category';
    $result = $db->query("SELECT product_id FROM `{$table}` WHERE product_id = {$productId} AND category_id = {$categoryId} LIMIT 1");

    if ($result->num_rows === 0) {
        $db->query("INSERT INTO `{$table}` SET product_id = {$productId}, category_id = {$categoryId}");
    }
}

function ensureSeoUrl(mysqli $db, int $productId, string $name, array $languages): void
{
    $table = DB_PREFIX . 'seo_url';
    $queryString = 'product_id=' . $productId;
    $queryStringEscaped = $db->real_escape_string($queryString);
    $existingResult = $db->query("SELECT seo_url_id, store_id, language_id, keyword FROM `{$table}` WHERE `query` = '{$queryStringEscaped}'");
    $existingByLanguage = [];

    while ($row = $existingResult->fetch_assoc()) {
        $existingByLanguage[(int) $row['store_id'] . ':' . (int) $row['language_id']] = $row;
    }

    foreach ($languages as $language) {
        $languageId = (int) $language['language_id'];
        $key = TARGET_STORE_ID . ':' . $languageId;

        if (isset($existingByLanguage[$key])) {
            continue;
        }

        $keyword = buildUniqueSeoKeyword($db, $name . '-' . $productId, $queryString, TARGET_STORE_ID, $languageId);
        $keywordEscaped = $db->real_escape_string($keyword);

        $db->query(
            "INSERT INTO `{$table}` SET
                store_id = " . TARGET_STORE_ID . ",
                language_id = {$languageId},
                `query` = '{$queryStringEscaped}',
                keyword = '{$keywordEscaped}'"
        );
    }
}

function buildUniqueSeoKeyword(mysqli $db, string $seed, string $queryString, int $storeId, int $languageId): string
{
    $table = DB_PREFIX . 'seo_url';
    $base = slugify($seed);

    if ($base === '') {
        $base = 'habys-product';
    }

    $candidate = $base;
    $suffix = 2;
    while (true) {
        $candidateEscaped = $db->real_escape_string($candidate);
        $result = $db->query(
            "SELECT seo_url_id, `query`
             FROM `{$table}`
             WHERE store_id = {$storeId}
               AND language_id = {$languageId}
               AND keyword = '{$candidateEscaped}'
             LIMIT 1"
        );

        if ($result->num_rows === 0) {
            return $candidate;
        }

        $row = $result->fetch_assoc();
        if ((string) $row['query'] === $queryString) {
            return $candidate;
        }

        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

function slugify(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if ($transliterated !== false) {
        $value = $transliterated;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value;
}

function cleanupMissingProducts(mysqli $db, array $seenSourceKeys, bool $dryRun): int
{
    $productTable = DB_PREFIX . 'product';
    $productToCategoryTable = DB_PREFIX . 'product_to_category';
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
            "UPDATE `{$productTable}`
             SET status = 0, quantity = 0, date_modified = NOW()
             WHERE product_id = {$productId}"
        );

        $db->query(
            "DELETE FROM `{$productToCategoryTable}`
             WHERE product_id = {$productId}
               AND category_id = " . TARGET_CATEGORY_ID
        );
    }

    return $disabled;
}
