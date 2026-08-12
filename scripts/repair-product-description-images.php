<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

if (!defined('PRODUCT_DESCRIPTION_IMAGE_LIBRARY_ONLY')) {
    pdiMain($argv);
}

function pdiMain(array $argv): void
{
    $options = getopt('', array('apply', 'store::', 'restore:', 'timeout::', 'max-bytes::'));
    $apply = array_key_exists('apply', $options);
    $storeId = isset($options['store']) ? max(0, (int)$options['store']) : 0;
    $timeout = isset($options['timeout']) ? max(5, (int)$options['timeout']) : 30;
    $maxBytes = isset($options['max-bytes']) ? max(1048576, (int)$options['max-bytes']) : 15728640;
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/upload/config.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
    $database->set_charset('utf8mb4');
    $lockName = 'product-description-images:' . DB_DATABASE . ':' . $storeId;

    try {
        if (!pdiAcquireLock($database, $lockName)) {
            throw new RuntimeException('Another product description image repair is already running.');
        }

        if (isset($options['restore'])) {
            pdiRestore($database, pdiReadRecoveryFile((string)$options['restore']));
            echo 'Restored product descriptions from: ' . $options['restore'] . PHP_EOL;
            return;
        }

        $plan = pdiPlan($database, $storeId);
        pdiPrintPlan($apply, $storeId, $plan);

        if (!$apply) {
            echo PHP_EOL . 'No files or database rows changed. Re-run with --apply after reviewing this preview.' . PHP_EOL;
            return;
        }

        if (!$plan['rows']) {
            echo PHP_EOL . 'Nothing to repair.' . PHP_EOL;
            return;
        }

        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required to mirror external images.');
        }

        $download = pdiMirrorImages(array_keys($plan['urls']), $timeout, $maxBytes);
        $updates = array();
        foreach ($plan['rows'] as $row) {
            $transformed = pdiTransformDescription($row['description'], $row['name'], $download['map']);
            $row['new_description'] = $transformed['html'];
            $updates[] = $row;
        }

        $backupPath = pdiWriteRecoveryFile($projectRoot, $storeId, $updates, $download['created_files']);
        echo 'Recovery backup: ' . $backupPath . PHP_EOL;

        try {
            pdiApply($database, $updates);
            $remaining = pdiPlan($database, $storeId);
            if ($remaining['rows']) {
                throw new RuntimeException('Post-apply verification found remaining external or incomplete description images.');
            }
        } catch (Throwable $exception) {
            try {
                pdiRestore($database, pdiReadRecoveryFile($backupPath));
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

        echo PHP_EOL . 'Mirrored external product-description images and added alt/lazy attributes.' . PHP_EOL;
    } finally {
        try {
            pdiReleaseLock($database, $lockName);
        } catch (Throwable $ignored) {
        }
        $database->close();
    }
}

function pdiPlan(mysqli $database, int $storeId): array
{
    $result = $database->query(
        "SELECT pd.`product_id`, pd.`language_id`, pd.`name`, pd.`description` " .
        "FROM `" . DB_PREFIX . "product_description` pd " .
        "JOIN `" . DB_PREFIX . "language` l ON l.`language_id` = pd.`language_id` AND l.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = pd.`product_id` AND p.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}' " .
        "WHERE pd.`description` LIKE '%<img%' ORDER BY pd.`product_id`, pd.`language_id`"
    );
    $rows = array();
    $urls = array();
    $stats = array(
        'descriptions' => 0,
        'image_tags' => 0,
        'external_images' => 0,
        'missing_alt' => 0,
        'missing_lazy' => 0,
        'missing_async_decoding' => 0
    );

    while ($row = $result->fetch_assoc()) {
        $transformed = pdiTransformDescription($row['description'], $row['name']);
        $stats['image_tags'] += $transformed['image_tags'];
        $stats['external_images'] += $transformed['external_images'];
        $stats['missing_alt'] += $transformed['missing_alt'];
        $stats['missing_lazy'] += $transformed['missing_lazy'];
        $stats['missing_async_decoding'] += $transformed['missing_async_decoding'];

        foreach ($transformed['external_urls'] as $url) {
            $urls[$url] = true;
        }

        if (!$transformed['changed'] && !$transformed['external_images']) {
            continue;
        }

        $stats['descriptions']++;
        $rows[] = array(
            'product_id' => (int)$row['product_id'],
            'language_id' => (int)$row['language_id'],
            'name' => $row['name'],
            'description' => $row['description']
        );
    }

    return array('rows' => $rows, 'urls' => $urls, 'stats' => $stats);
}

function pdiTransformDescription(string $html, string $productName, array $urlMap = array()): array
{
    $stats = array(
        'image_tags' => 0,
        'external_images' => 0,
        'missing_alt' => 0,
        'missing_lazy' => 0,
        'missing_async_decoding' => 0,
        'external_urls' => array(),
        'changed' => false
    );
    $altText = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($productName, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

    $updated = preg_replace_callback('/<img\b[^>]*>/i', function ($matches) use (&$stats, $altText, $urlMap) {
        $tag = $matches[0];
        $stats['image_tags']++;
        $source = pdiImageAttribute($tag, 'src');

        if ($source !== '') {
            $decodedSource = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^https?://#i', $decodedSource)) {
                $stats['external_images']++;
                $stats['external_urls'][$decodedSource] = $decodedSource;
                if (isset($urlMap[$decodedSource])) {
                    $tag = pdiSetImageAttribute($tag, 'src', $urlMap[$decodedSource]);
                }
            }
        }

        if (trim(pdiImageAttribute($tag, 'alt')) === '') {
            $stats['missing_alt']++;
            $tag = pdiSetImageAttribute($tag, 'alt', $altText);
        }
        if (strtolower(pdiImageAttribute($tag, 'loading')) !== 'lazy') {
            $stats['missing_lazy']++;
            $tag = pdiSetImageAttribute($tag, 'loading', 'lazy');
        }
        if (strtolower(pdiImageAttribute($tag, 'decoding')) !== 'async') {
            $stats['missing_async_decoding']++;
            $tag = pdiSetImageAttribute($tag, 'decoding', 'async');
        }

        if ($tag !== $matches[0]) {
            $stats['changed'] = true;
        }
        return $tag;
    }, $html);

    $stats['html'] = $updated === null ? $html : $updated;
    $stats['external_urls'] = array_values($stats['external_urls']);
    return $stats;
}

function pdiImageAttribute(string $tag, string $attribute): string
{
    if (!preg_match(
        '/\b' . preg_quote($attribute, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
        $tag,
        $matches
    )) {
        return '';
    }

    foreach (array(1, 2, 3) as $index) {
        if (isset($matches[$index]) && $matches[$index] !== '') {
            return $matches[$index];
        }
    }
    return '';
}

function pdiSetImageAttribute(string $tag, string $attribute, string $value): string
{
    $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i';
    if (preg_match($pattern, $tag)) {
        return preg_replace($pattern, $attribute . '="' . $escaped . '"', $tag, 1);
    }

    if (substr($tag, -2) === '/>') {
        return substr($tag, 0, -2) . ' ' . $attribute . '="' . $escaped . '" />';
    }
    return substr($tag, 0, -1) . ' ' . $attribute . '="' . $escaped . '">';
}

function pdiMirrorImages(array $urls, int $timeout, int $maxBytes): array
{
    $directory = rtrim(DIR_IMAGE, '/\\') . '/catalog/seo-description';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create local image directory: ' . $directory);
    }

    $map = array();
    $created = array();
    $total = count($urls);
    foreach (array_values($urls) as $index => $url) {
        pdiAssertPublicUrl($url);
        $hash = hash('sha256', $url);
        $existing = glob($directory . '/' . $hash . '.*');
        if ($existing) {
            $map[$url] = 'image/catalog/seo-description/' . basename($existing[0]);
            continue;
        }

        $temporary = tempnam($directory, '.download-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate a temporary image file.');
        }

        try {
            pdiDownloadImage($url, $temporary, $timeout, $maxBytes);
            $extension = pdiImageExtension($temporary);
            $destination = $directory . '/' . $hash . '.' . $extension;
            if (!rename($temporary, $destination)) {
                throw new RuntimeException('Unable to store mirrored image: ' . $destination);
            }
            chmod($destination, 0644);
            $created[] = $destination;
            $map[$url] = 'image/catalog/seo-description/' . basename($destination);
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw new RuntimeException('Unable to mirror ' . $url . ': ' . $exception->getMessage(), 0, $exception);
        }

        if (($index + 1) % 100 === 0 || $index + 1 === $total) {
            echo 'Mirrored images: ' . ($index + 1) . '/' . $total . PHP_EOL;
        }
    }

    return array('map' => $map, 'created_files' => $created);
}

function pdiDownloadImage(string $url, string $destination, int $timeout, int $maxBytes): void
{
    $handle = fopen($destination, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the temporary image file.');
    }

    $bytes = 0;
    $curl = curl_init($url);
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'WorldOfBeauty-SEO-Image-Migration/1.0',
        CURLOPT_FAILONERROR => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($curlHandle, string $chunk) use ($handle, &$bytes, $maxBytes) {
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                return 0;
            }
            return fwrite($handle, $chunk);
        }
    );
    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($curl, $options);
    $success = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);
    fclose($handle);

    if ($success === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'HTTP status ' . $status);
    }
    if ($bytes === 0 || $bytes > $maxBytes) {
        throw new RuntimeException('Downloaded file is empty or exceeds the byte limit.');
    }
    pdiAssertPublicUrl($effectiveUrl);
}

function pdiImageExtension(string $path): string
{
    $info = @getimagesize($path);
    if ($info === false || empty($info[2])) {
        throw new RuntimeException('Downloaded file is not a supported image.');
    }

    $extensions = array(
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png'
    );
    if (defined('IMAGETYPE_WEBP')) {
        $extensions[IMAGETYPE_WEBP] = 'webp';
    }

    if (!isset($extensions[$info[2]])) {
        throw new RuntimeException('Downloaded image type is not supported.');
    }
    return $extensions[$info[2]];
}

function pdiAssertPublicUrl(string $url): void
{
    $parts = parse_url($url);
    if (
        !is_array($parts) ||
        empty($parts['scheme']) ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        (isset($parts['port']) && !in_array((int)$parts['port'], array(80, 443), true)) ||
        !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
    ) {
        throw new RuntimeException('Invalid external image URL.');
    }

    $addresses = gethostbynamel($parts['host']);
    if (!$addresses) {
        throw new RuntimeException('External image host cannot be resolved.');
    }
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('External image host resolves to a non-public address.');
        }
    }
}

function pdiApply(mysqli $database, array $updates): void
{
    $statement = $database->prepare(
        "UPDATE `" . DB_PREFIX . "product_description` SET `description` = ? WHERE `product_id` = ? AND `language_id` = ?"
    );
    foreach ($updates as $row) {
        $statement->bind_param('sii', $row['new_description'], $row['product_id'], $row['language_id']);
        $statement->execute();
    }
    $statement->close();
}

function pdiPrintPlan(bool $apply, int $storeId, array $plan): void
{
    echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
    echo 'Store: ' . $storeId . PHP_EOL;
    echo 'Descriptions to repair: ' . count($plan['rows']) . PHP_EOL;
    echo 'Image tags scanned: ' . $plan['stats']['image_tags'] . PHP_EOL;
    echo 'External image references: ' . $plan['stats']['external_images'] . PHP_EOL;
    echo 'Unique external images to mirror: ' . count($plan['urls']) . PHP_EOL;
    echo 'Images missing alt text: ' . $plan['stats']['missing_alt'] . PHP_EOL;
    echo 'Images missing loading=lazy: ' . $plan['stats']['missing_lazy'] . PHP_EOL;
    echo 'Images missing decoding=async: ' . $plan['stats']['missing_async_decoding'] . PHP_EOL;
}

function pdiWriteRecoveryFile(string $projectRoot, int $storeId, array $updates, array $createdFiles): string
{
    $storageRoot = defined('DIR_STORAGE') ? rtrim(DIR_STORAGE, '/\\') : $projectRoot . '/storage';
    $directory = $storageRoot . '/backup';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create recovery backup directory: ' . $directory);
    }

    $rows = array();
    foreach ($updates as $row) {
        $rows[] = array(
            'product_id' => $row['product_id'],
            'language_id' => $row['language_id'],
            'description' => $row['description']
        );
    }
    $data = array(
        'format' => 1,
        'database' => DB_DATABASE,
        'prefix' => DB_PREFIX,
        'store_id' => $storeId,
        'created_at' => gmdate('c'),
        'rows' => $rows,
        'created_files' => $createdFiles
    );
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode the recovery backup.');
    }
    $wrapper = json_encode(
        array('checksum' => hash('sha256', $json), 'data' => $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    if ($wrapper === false) {
        throw new RuntimeException('Unable to encode the recovery backup wrapper.');
    }
    $path = $directory . '/product-description-images-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    if (file_put_contents($path, $wrapper, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the recovery backup: ' . $path);
    }
    chmod($path, 0600);
    return $path;
}

function pdiReadRecoveryFile(string $path): array
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
    if ($json === false || !hash_equals($wrapper['checksum'], hash('sha256', $json))) {
        throw new RuntimeException('Recovery backup checksum failed: ' . $path);
    }
    if (
        !isset($wrapper['data']['database'], $wrapper['data']['prefix'], $wrapper['data']['rows']) ||
        $wrapper['data']['database'] !== DB_DATABASE ||
        $wrapper['data']['prefix'] !== DB_PREFIX
    ) {
        throw new RuntimeException('Recovery backup belongs to a different OpenCart database.');
    }
    return $wrapper['data'];
}

function pdiRestore(mysqli $database, array $backup): void
{
    $statement = $database->prepare(
        "UPDATE `" . DB_PREFIX . "product_description` SET `description` = ? WHERE `product_id` = ? AND `language_id` = ?"
    );
    foreach ($backup['rows'] as $row) {
        $statement->bind_param('sii', $row['description'], $row['product_id'], $row['language_id']);
        $statement->execute();
    }
    $statement->close();
}

function pdiAcquireLock(mysqli $database, string $lockName): bool
{
    $statement = $database->prepare('SELECT GET_LOCK(?, 10)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->bind_result($acquired);
    $statement->fetch();
    $statement->close();
    return (int)$acquired === 1;
}

function pdiReleaseLock(mysqli $database, string $lockName): void
{
    $statement = $database->prepare('SELECT RELEASE_LOCK(?)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->close();
}
