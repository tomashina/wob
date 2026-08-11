<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$database->set_charset('utf8mb4');

function seoUpsertSetting(mysqli $database, $code, $key, $value, $storeId = 0)
{
    $table = DB_PREFIX . 'setting';
    $select = $database->prepare(
        "SELECT `setting_id` FROM `{$table}` WHERE `store_id` = ? AND `code` = ? AND `key` = ? LIMIT 1"
    );
    $select->bind_param('iss', $storeId, $code, $key);
    $select->execute();
    $select->bind_result($settingId);
    $found = $select->fetch();
    $select->close();

    if ($found) {
        $update = $database->prepare(
            "UPDATE `{$table}` SET `value` = ?, `serialized` = 0 WHERE `setting_id` = ?"
        );
        $update->bind_param('si', $value, $settingId);
        $update->execute();
        return;
    }

    $insert = $database->prepare(
        "INSERT INTO `{$table}` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (?, ?, ?, ?, 0)"
    );
    $insert->bind_param('isss', $storeId, $code, $key, $value);
    $insert->execute();
}

function seoIsBrokenMeta($value, $checkBuyBest = false)
{
    $value = trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));

    if ($value === '' || preg_match('/\{[^}]+\}/', $value)) {
        return true;
    }

    return $checkBuyBest && preg_match('/^buy best\b/i', $value);
}

function seoPlainText($html)
{
    $text = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return preg_replace('/\s+/u', ' ', trim(strip_tags($text)));
}

function seoTruncate($text, $limit)
{
    $text = preg_replace('/\s+/u', ' ', trim((string) $text));

    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    $short = mb_substr($text, 0, $limit - 1, 'UTF-8');
    $space = mb_strrpos($short, ' ', 0, 'UTF-8');

    if ($space !== false && $space > (int) ($limit * 0.7)) {
        $short = mb_substr($short, 0, $space, 'UTF-8');
    }

    return rtrim($short, ' ,.;:-') . '…';
}

function seoLanguages(mysqli $database)
{
    $languages = array();
    $result = $database->query(
        "SELECT `language_id`, `code` FROM `" . DB_PREFIX . "language` WHERE `status` = 1"
    );

    while ($row = $result->fetch_assoc()) {
        $languages[(int) $row['language_id']] = strtolower($row['code']);
    }

    return $languages;
}

function seoConfigureSearchMeta(mysqli $database, array $languages)
{
    foreach ($languages as $languageId => $code) {
        $croatian = strpos($code, 'hr') === 0;
        $values = $croatian ? array(
            'hb_metatags_pgtitle' => 'Stranica {page} – {meta}',
            'hb_metatags_pgdesc' => '{meta} Stranica {page}.',
            'hb_metatags_srtitle' => 'Rezultati za „{tag}” | Stranica {page} | World of Beauty',
            'hb_metatags_srdesc' => 'Pronađeno je {total} proizvoda za pojam „{tag}” na stranici {page}.',
            'hb_metatags_tgtitle' => 'Proizvodi: {tag} | Stranica {page} | World of Beauty',
            'hb_metatags_tgdesc' => 'Istražite {total} proizvoda označenih pojmom „{tag}” na stranici {page}.'
        ) : array(
            'hb_metatags_pgtitle' => 'Page {page} – {meta}',
            'hb_metatags_pgdesc' => '{meta} Page {page}.',
            'hb_metatags_srtitle' => 'Results for “{tag}” | Page {page} | World of Beauty',
            'hb_metatags_srdesc' => '{total} products found for “{tag}” on page {page}.',
            'hb_metatags_tgtitle' => 'Products: {tag} | Page {page} | World of Beauty',
            'hb_metatags_tgdesc' => 'Explore {total} products tagged “{tag}” on page {page}.'
        );

        foreach ($values as $key => $value) {
            seoUpsertSetting($database, 'hb_metatags', $key . $languageId, $value);
        }
    }
}

function seoConfigureStructuredData(mysqli $database)
{
    $values = array(
        'hb_snippets_prod_enable' => '1',
        'hb_snippets_bc_enable' => '1',
        'hb_snippets_bc_type' => 'smart',
        'hb_snippets_list_enable' => '1',
        'hb_snippets_kg_enable' => '1',
        'hb_snippets_search_enable' => '1',
        'hb_snippets_og_enable' => '1',
        'hb_snippets_tc_enable' => '1',
        'hb_snippets_local_enable' => '0',
        'hb_snippets_local_country' => 'HR'
    );

    foreach ($values as $key => $value) {
        seoUpsertSetting($database, 'hb_snippets', $key, $value);
    }
}

function seoRepairCategoryMeta(mysqli $database, array $languages)
{
    $table = DB_PREFIX . 'category_description';
    $result = $database->query(
        "SELECT `category_id`, `language_id`, `name`, `meta_title`, `meta_description` FROM `{$table}`"
    );
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? WHERE `category_id` = ? AND `language_id` = ?"
    );
    $updated = 0;

    while ($row = $result->fetch_assoc()) {
        $languageId = (int) $row['language_id'];
        $code = isset($languages[$languageId]) ? $languages[$languageId] : '';
        $croatian = strpos($code, 'hr') === 0;
        $title = $row['meta_title'];
        $description = $row['meta_description'];
        $changed = false;

        if (seoIsBrokenMeta($title, true)) {
            $title = seoTruncate($row['name'] . ' | World of Beauty', 65);
            $changed = true;
        }

        if (seoIsBrokenMeta($description)) {
            $description = $croatian
                ? 'Istražite ' . $row['name'] . ' za profesionalne salone. Kvalitetna oprema, sigurna online kupnja, brza isporuka i stručna podrška.'
                : 'Explore ' . $row['name'] . ' for professional salons. Quality equipment, secure shopping, fast delivery and expert support.';
            $description = seoTruncate($description, 160);
            $changed = true;
        }

        if ($changed) {
            $categoryId = (int) $row['category_id'];
            $update->bind_param('ssii', $title, $description, $categoryId, $languageId);
            $update->execute();
            $updated++;
        }
    }

    return $updated;
}

function seoRepairProductMeta(mysqli $database, array $languages)
{
    $table = DB_PREFIX . 'product_description';
    $result = $database->query(
        "SELECT `product_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description` FROM `{$table}`"
    );
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? WHERE `product_id` = ? AND `language_id` = ?"
    );
    $updated = 0;

    while ($row = $result->fetch_assoc()) {
        $languageId = (int) $row['language_id'];
        $code = isset($languages[$languageId]) ? $languages[$languageId] : '';
        $croatian = strpos($code, 'hr') === 0;
        $title = $row['meta_title'];
        $description = $row['meta_description'];
        $changed = false;

        if (seoIsBrokenMeta($title)) {
            $title = seoTruncate($row['name'] . ' | World of Beauty', 65);
            $changed = true;
        }

        if (seoIsBrokenMeta($description)) {
            $description = seoPlainText($row['description']);

            if ($description === '') {
                $description = $croatian
                    ? $row['name'] . ' – profesionalna oprema za salone. Sigurna kupnja, brza isporuka i stručna podrška.'
                    : $row['name'] . ' – professional salon equipment with secure shopping, fast delivery and expert support.';
            }

            $description = seoTruncate($description, 160);
            $changed = true;
        }

        if ($changed) {
            $productId = (int) $row['product_id'];
            $update->bind_param('ssii', $title, $description, $productId, $languageId);
            $update->execute();
            $updated++;
        }
    }

    return $updated;
}

function seoRefreshRuntimeCaches(mysqli $database)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for --refresh.');
    }

    $userResult = $database->query(
        "SELECT `user_id` FROM `" . DB_PREFIX . "user` WHERE `status` = 1 AND `user_group_id` = 1 ORDER BY `user_id` LIMIT 1"
    );

    if (!$userResult->num_rows) {
        throw new RuntimeException('No active super-admin user is available for OCMOD refresh.');
    }

    $sessionId = bin2hex(random_bytes(16));
    $userToken = bin2hex(random_bytes(16));
    $userId = (int) $userResult->fetch_assoc()['user_id'];
    $sessionData = json_encode(array('user_id' => $userId, 'user_token' => $userToken));
    $sessionTable = DB_PREFIX . 'session';
    $session = $database->prepare(
        "REPLACE INTO `{$sessionTable}` (`session_id`, `data`, `expire`) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))"
    );
    $session->bind_param('ss', $sessionId, $sessionData);
    $session->execute();

    try {
        $server = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
        $admin = rtrim($server, '/') . '/admin/index.php';
        $cookie = 'OCSESSID=' . $sessionId;
        $urls = array(
            $admin . '?route=marketplace/modification/refresh&user_token=' . rawurlencode($userToken),
            $admin . '?route=common/developer/theme&user_token=' . rawurlencode($userToken)
        );

        foreach ($urls as $index => $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => $index === 0,
                CURLOPT_COOKIE => $cookie,
                CURLOPT_POST => $index === 1,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => false,
            ));
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $error !== '' || $status >= 400) {
                throw new RuntimeException('Runtime refresh failed (HTTP ' . $status . '): ' . $error);
            }
        }
    } finally {
        $delete = $database->prepare("DELETE FROM `{$sessionTable}` WHERE `session_id` = ?");
        $delete->bind_param('s', $sessionId);
        $delete->execute();
    }
}

try {
    $database->begin_transaction();
    $languages = seoLanguages($database);
    seoConfigureSearchMeta($database, $languages);
    seoConfigureStructuredData($database);
    $categoryUpdates = seoRepairCategoryMeta($database, $languages);
    $productUpdates = seoRepairProductMeta($database, $languages);
    $database->commit();

    echo 'UPDATED search and pagination metadata' . PHP_EOL;
    echo 'UPDATED Open Graph, Twitter Cards and structured data settings' . PHP_EOL;
    echo 'REPAIRED ' . $categoryUpdates . ' category metadata records' . PHP_EOL;
    echo 'REPAIRED ' . $productUpdates . ' product metadata records' . PHP_EOL;

    if (in_array('--refresh', $argv, true)) {
        seoRefreshRuntimeCaches($database);
        echo 'REFRESHED OCMOD and theme template cache' . PHP_EOL;
    } else {
        echo 'Next: refresh Extensions > Modifications and the theme cache in OpenCart.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    try {
        $database->rollback();
    } catch (Throwable $ignored) {
    }

    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $database->close();
}
