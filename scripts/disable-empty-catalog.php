<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

if (!defined('EMPTY_CATALOG_LIBRARY_ONLY')) {
    cecMain($argv);
}

function cecMain(array $argv): void
{
    $options = getopt('', array('apply', 'store::', 'restore:'));
    $apply = array_key_exists('apply', $options);
    $storeId = isset($options['store']) ? max(0, (int)$options['store']) : 0;
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/upload/config.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
    $database->set_charset('utf8mb4');
    $lockName = 'disable-empty-catalog:' . DB_DATABASE . ':' . $storeId;

    try {
        if (!cecAcquireLock($database, $lockName)) {
            throw new RuntimeException('Another empty catalog cleanup is already running.');
        }

        if (isset($options['restore'])) {
            cecRestore($database, cecReadRecoveryFile((string)$options['restore']));
            echo 'Restored product and category statuses from: ' . $options['restore'] . PHP_EOL;
            return;
        }

        $plan = cecPlan($database, $storeId, DIR_IMAGE);
        cecPrintPlan($apply, $storeId, $plan);

        if (!$apply) {
            echo PHP_EOL . 'No database changes made. Re-run with --apply after reviewing this preview.' . PHP_EOL;
            return;
        }
        if (!$plan['products'] && !$plan['categories']) {
            echo PHP_EOL . 'Nothing to disable.' . PHP_EOL;
            return;
        }

        $backupPath = cecWriteRecoveryFile($projectRoot, $storeId, $plan);
        echo PHP_EOL . 'Recovery backup: ' . $backupPath . PHP_EOL;

        try {
            cecApply($database, $plan);
            $remaining = cecPlan($database, $storeId, DIR_IMAGE);
            if ($remaining['products'] || $remaining['categories']) {
                throw new RuntimeException(
                    'Post-apply verification found ' . count($remaining['products']) .
                    ' products without images and ' . count($remaining['categories']) . ' empty categories.'
                );
            }
        } catch (Throwable $exception) {
            try {
                cecRestore($database, cecReadRecoveryFile($backupPath));
            } catch (Throwable $restoreException) {
                throw new RuntimeException(
                    'Apply and automatic restore failed. Run --restore=' . $backupPath .
                    '. Apply error: ' . $exception->getMessage() .
                    '. Restore error: ' . $restoreException->getMessage(),
                    0,
                    $exception
                );
            }
            throw new RuntimeException('Apply failed; the recovery backup was restored. ' . $exception->getMessage(), 0, $exception);
        }

        echo PHP_EOL . 'Disabled products without usable images and categories without active products.' . PHP_EOL;
    } finally {
        try {
            cecReleaseLock($database, $lockName);
        } catch (Throwable $ignored) {
        }
        $database->close();
    }
}

function cecPlan(mysqli $database, int $storeId, string $imageRoot): array
{
    $products = array();
    $activeProductIds = array();
    $productRows = $database->query(
        "SELECT p.`product_id`, p.`image`, p.`date_modified`, COALESCE(MIN(pd.`name`), '') AS name " .
        "FROM `" . DB_PREFIX . "product` p " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}' " .
        "LEFT JOIN `" . DB_PREFIX . "product_description` pd ON pd.`product_id` = p.`product_id` " .
        "WHERE p.`status` = 1 GROUP BY p.`product_id`, p.`image`, p.`date_modified` ORDER BY p.`product_id`"
    );
    $additionalImages = array();
    $additionalRows = $database->query(
        "SELECT pi.`product_id`, pi.`image` FROM `" . DB_PREFIX . "product_image` pi " .
        "JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = pi.`product_id` AND p.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}'"
    );
    while ($row = $additionalRows->fetch_assoc()) {
        $additionalImages[(int)$row['product_id']][] = $row['image'];
    }

    while ($row = $productRows->fetch_assoc()) {
        $productId = (int)$row['product_id'];
        $activeProductIds[$productId] = true;
        $images = array_merge(array($row['image']), isset($additionalImages[$productId]) ? $additionalImages[$productId] : array());
        $hasUsableImage = false;
        foreach ($images as $image) {
            if (cecHasUsableImage($imageRoot, (string)$image)) {
                $hasUsableImage = true;
                break;
            }
        }
        if (!$hasUsableImage) {
            $products[$productId] = array(
                'product_id' => $productId,
                'name' => $row['name'],
                'old_status' => 1,
                'old_date_modified' => $row['date_modified']
            );
        }
    }

    foreach ($products as $productId => $ignored) {
        unset($activeProductIds[$productId]);
    }

    $categories = array();
    $categoryRows = $database->query(
        "SELECT c.`category_id`, c.`date_modified`, COALESCE(MIN(cd.`name`), '') AS name " .
        "FROM `" . DB_PREFIX . "category` c " .
        "JOIN `" . DB_PREFIX . "category_to_store` c2s ON c2s.`category_id` = c.`category_id` AND c2s.`store_id` = '{$storeId}' " .
        "LEFT JOIN `" . DB_PREFIX . "category_description` cd ON cd.`category_id` = c.`category_id` " .
        "WHERE c.`status` = 1 GROUP BY c.`category_id`, c.`date_modified` ORDER BY c.`category_id`"
    );
    $categoryData = array();
    while ($row = $categoryRows->fetch_assoc()) {
        $categoryId = (int)$row['category_id'];
        $categoryData[$categoryId] = $row;
    }

    $descendants = array();
    $pathRows = $database->query(
        "SELECT cp.`path_id`, cp.`category_id` FROM `" . DB_PREFIX . "category_path` cp " .
        "JOIN `" . DB_PREFIX . "category_to_store` c2s ON c2s.`category_id` = cp.`category_id` AND c2s.`store_id` = '{$storeId}'"
    );
    while ($row = $pathRows->fetch_assoc()) {
        $descendants[(int)$row['path_id']][(int)$row['category_id']] = true;
    }

    $productsByCategory = array();
    $assignmentRows = $database->query(
        "SELECT ptc.`category_id`, ptc.`product_id` FROM `" . DB_PREFIX . "product_to_category` ptc " .
        "JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = ptc.`product_id` AND p.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}'"
    );
    while ($row = $assignmentRows->fetch_assoc()) {
        $productId = (int)$row['product_id'];
        if (isset($activeProductIds[$productId])) {
            $productsByCategory[(int)$row['category_id']][$productId] = true;
        }
    }

    foreach ($categoryData as $categoryId => $row) {
        $descendantIds = isset($descendants[$categoryId]) ? array_keys($descendants[$categoryId]) : array($categoryId);
        if (!in_array($categoryId, $descendantIds, true)) {
            $descendantIds[] = $categoryId;
        }
        $hasProduct = false;
        foreach ($descendantIds as $descendantId) {
            if (!empty($productsByCategory[$descendantId])) {
                $hasProduct = true;
                break;
            }
        }
        if (!$hasProduct) {
            $categories[$categoryId] = array(
                'category_id' => $categoryId,
                'name' => $row['name'],
                'old_status' => 1,
                'old_date_modified' => $row['date_modified']
            );
        }
    }

    return array('products' => array_values($products), 'categories' => array_values($categories));
}

function cecHasUsableImage(string $imageRoot, string $image): bool
{
    $image = ltrim(trim(html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8')), '/\\');
    if ($image === '' || $image === 'no_image.png' || preg_match('#^(?:https?:)?//#i', $image) || strpos($image, '..') !== false) {
        return false;
    }

    $root = realpath($imageRoot);
    $path = realpath(rtrim($imageRoot, '/\\') . DIRECTORY_SEPARATOR . $image);
    return $root !== false && $path !== false && is_file($path) && strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
}

function cecApply(mysqli $database, array $plan): void
{
    $productUpdate = $database->prepare(
        "UPDATE `" . DB_PREFIX . "product` SET `status` = 0, `date_modified` = NOW() WHERE `product_id` = ? AND `status` = 1"
    );
    foreach ($plan['products'] as $row) {
        $productUpdate->bind_param('i', $row['product_id']);
        $productUpdate->execute();
    }
    $productUpdate->close();

    $categoryUpdate = $database->prepare(
        "UPDATE `" . DB_PREFIX . "category` SET `status` = 0, `date_modified` = NOW() WHERE `category_id` = ? AND `status` = 1"
    );
    foreach ($plan['categories'] as $row) {
        $categoryUpdate->bind_param('i', $row['category_id']);
        $categoryUpdate->execute();
    }
    $categoryUpdate->close();
}

function cecRestore(mysqli $database, array $backup): void
{
    $modeRow = $database->query('SELECT @@SESSION.sql_mode AS sql_mode')->fetch_assoc();
    $originalMode = isset($modeRow['sql_mode']) ? $modeRow['sql_mode'] : '';
    $restoreModes = array_values(array_filter(explode(',', $originalMode), static function (string $mode): bool {
        return !in_array($mode, array('NO_ZERO_DATE', 'NO_ZERO_IN_DATE'), true);
    }));
    $database->query("SET SESSION sql_mode = '" . $database->real_escape_string(implode(',', $restoreModes)) . "'");

    try {
        foreach (array('products' => 'product', 'categories' => 'category') as $key => $table) {
            $idColumn = $key === 'products' ? 'product_id' : 'category_id';
            $statement = $database->prepare(
                "UPDATE `" . DB_PREFIX . $table . "` SET `status` = ?, `date_modified` = ? WHERE `{$idColumn}` = ?"
            );
            foreach ($backup[$key] as $row) {
                $statement->bind_param('isi', $row['old_status'], $row['old_date_modified'], $row[$idColumn]);
                $statement->execute();
            }
            $statement->close();
        }
    } finally {
        $database->query("SET SESSION sql_mode = '" . $database->real_escape_string($originalMode) . "'");
    }
}

function cecPrintPlan(bool $apply, int $storeId, array $plan): void
{
    echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
    echo 'Store: ' . $storeId . PHP_EOL;
    echo 'Active products without usable images to disable: ' . count($plan['products']) . PHP_EOL;
    echo 'Active categories without products in their subtree to disable: ' . count($plan['categories']) . PHP_EOL;
    foreach (array('products', 'categories') as $key) {
        foreach (array_slice($plan[$key], 0, 10) as $row) {
            $id = $key === 'products' ? $row['product_id'] : $row['category_id'];
            echo '  ' . rtrim($key, 's') . ' ' . $id . ': ' . $row['name'] . PHP_EOL;
        }
    }
}

function cecWriteRecoveryFile(string $projectRoot, int $storeId, array $plan): string
{
    $storageRoot = defined('DIR_STORAGE') ? rtrim(DIR_STORAGE, '/\\') : $projectRoot . '/storage';
    $directory = $storageRoot . '/backup';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create recovery backup directory: ' . $directory);
    }
    $data = array(
        'format' => 1,
        'database' => DB_DATABASE,
        'prefix' => DB_PREFIX,
        'store_id' => $storeId,
        'created_at' => gmdate('c'),
        'products' => $plan['products'],
        'categories' => $plan['categories']
    );
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode the recovery backup.');
    }
    $wrapper = json_encode(
        array('checksum' => hash('sha256', $json), 'data' => $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    $path = $directory . '/empty-catalog-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    if ($wrapper === false || file_put_contents($path, $wrapper, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the recovery backup: ' . $path);
    }
    chmod($path, 0600);
    return $path;
}

function cecReadRecoveryFile(string $path): array
{
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Recovery backup does not exist: ' . $path);
    }
    $contents = file_get_contents($path);
    $wrapper = $contents === false ? null : json_decode($contents, true);
    if (!is_array($wrapper) || !isset($wrapper['checksum'], $wrapper['data']) || !is_array($wrapper['data'])) {
        throw new RuntimeException('Recovery backup is invalid: ' . $path);
    }
    $json = json_encode($wrapper['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (
        $json === false ||
        !hash_equals($wrapper['checksum'], hash('sha256', $json)) ||
        !isset($wrapper['data']['database'], $wrapper['data']['prefix'], $wrapper['data']['products'], $wrapper['data']['categories']) ||
        $wrapper['data']['database'] !== DB_DATABASE ||
        $wrapper['data']['prefix'] !== DB_PREFIX
    ) {
        throw new RuntimeException('Recovery backup validation failed: ' . $path);
    }
    return $wrapper['data'];
}

function cecAcquireLock(mysqli $database, string $lockName): bool
{
    $statement = $database->prepare('SELECT GET_LOCK(?, 10)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->bind_result($acquired);
    $statement->fetch();
    $statement->close();
    return (int)$acquired === 1;
}

function cecReleaseLock(mysqli $database, string $lockName): void
{
    $statement = $database->prepare('SELECT RELEASE_LOCK(?)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->close();
}
