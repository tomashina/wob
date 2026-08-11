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

const WOB_GTM_ID = 'GTM-K6DBPBNM';

function ensureTrackingTable(mysqli $database)
{
    $table = DB_PREFIX . 'cmpltguagaf';
    $database->query(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `cmpltguagaf_id` int(11) NOT NULL AUTO_INCREMENT,
            `store_id` int(11) NOT NULL,
            `status` tinyint(1) NOT NULL,
            `gaid` varchar(100) NOT NULL,
            `gafid` varchar(250) NOT NULL,
            `gtmid` varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY (`cmpltguagaf_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );

    $column = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'gtmid'");

    if (!$column->num_rows) {
        $database->query(
            "ALTER TABLE `{$table}` ADD `gtmid` varchar(100) NOT NULL DEFAULT '' AFTER `gafid`"
        );
    }
}

function configureTracking(mysqli $database)
{
    $table = DB_PREFIX . 'cmpltguagaf';
    $select = $database->prepare(
        "SELECT `cmpltguagaf_id` FROM `{$table}` WHERE `store_id` = 0 ORDER BY `cmpltguagaf_id` LIMIT 1"
    );
    $select->execute();
    $result = $select->get_result();

    if ($result->num_rows) {
        $id = (int) $result->fetch_assoc()['cmpltguagaf_id'];
        $update = $database->prepare(
            "UPDATE `{$table}` SET `status` = 1, `gaid` = '', `gafid` = '', `gtmid` = ? WHERE `cmpltguagaf_id` = ?"
        );
        $gtmId = WOB_GTM_ID;
        $update->bind_param('si', $gtmId, $id);
        $update->execute();
        return 'updated';
    }

    $insert = $database->prepare(
        "INSERT INTO `{$table}` (`store_id`, `status`, `gaid`, `gafid`, `gtmid`) VALUES (0, 1, '', '', ?)"
    );
    $gtmId = WOB_GTM_ID;
    $insert->bind_param('s', $gtmId);
    $insert->execute();

    return 'inserted';
}

function upsertSetting(mysqli $database, $code, $key, $value, $storeId = 0)
{
    $table = DB_PREFIX . 'setting';
    $select = $database->prepare(
        "SELECT `setting_id` FROM `{$table}` WHERE `store_id` = ? AND `code` = ? AND `key` = ? LIMIT 1"
    );
    $select->bind_param('iss', $storeId, $code, $key);
    $select->execute();
    $result = $select->get_result();

    if ($result->num_rows) {
        $settingId = (int) $result->fetch_assoc()['setting_id'];
        $update = $database->prepare(
            "UPDATE `{$table}` SET `value` = ?, `serialized` = 0 WHERE `setting_id` = ?"
        );
        $update->bind_param('si', $value, $settingId);
        $update->execute();
        return 'updated';
    }

    $insert = $database->prepare(
        "INSERT INTO `{$table}` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (?, ?, ?, ?, 0)"
    );
    $insert->bind_param('isss', $storeId, $code, $key, $value);
    $insert->execute();

    return 'inserted';
}

function refreshRuntimeCaches(mysqli $database)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for --refresh.');
    }

    $userTable = DB_PREFIX . 'user';
    $userResult = $database->query(
        "SELECT `user_id` FROM `{$userTable}` WHERE `status` = 1 AND `user_group_id` = 1 ORDER BY `user_id` LIMIT 1"
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

        $modificationUrl = $admin . '?route=marketplace/modification/refresh&user_token='
            . rawurlencode($userToken);
        $curl = curl_init($modificationUrl);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '' || $status >= 400) {
            throw new RuntimeException('OCMOD refresh failed (HTTP ' . $status . '): ' . $error);
        }

        $cacheUrl = $admin . '?route=common/developer/theme&user_token=' . rawurlencode($userToken);
        $curl = curl_init($cacheUrl);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '' || $status >= 400) {
            throw new RuntimeException('Runtime cache refresh failed (HTTP ' . $status . '): ' . $error);
        }
    } finally {
        $delete = $database->prepare("DELETE FROM `{$sessionTable}` WHERE `session_id` = ?");
        $delete->bind_param('s', $sessionId);
        $delete->execute();
    }
}

try {
    ensureTrackingTable($database);
    echo strtoupper(configureTracking($database)) . ' Google Tag Manager ' . WOB_GTM_ID . PHP_EOL;

    echo strtoupper(upsertSetting($database, 'mpgdpr', 'mpgdpr_cbstatus', '0'))
        . ' legacy GDPR cookie banner disabled' . PHP_EOL;
    echo strtoupper(upsertSetting($database, 'basel', 'basel_cookie_bar_status', '0'))
        . ' legacy Basel cookie bar disabled' . PHP_EOL;

    if (in_array('--refresh', $argv, true)) {
        refreshRuntimeCaches($database);
        echo 'REFRESHED OCMOD and theme template cache' . PHP_EOL;
    } else {
        echo 'Next: refresh Extensions > Modifications and the theme cache in OpenCart.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $database->close();
}
