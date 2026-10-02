<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This script can only be run from the command line.\n");
}

$project_root = dirname(__DIR__);
require_once $project_root . '/upload/config.php';

if (!function_exists('curl_init')) {
	throw new RuntimeException('cURL is required to refresh OCMOD.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$database->set_charset('utf8mb4');

$user_result = $database->query(
	'SELECT `user_id` FROM `' . DB_PREFIX . 'user`
	 WHERE `status` = 1 AND `user_group_id` = 1
	 ORDER BY `user_id` ASC LIMIT 1'
);

if (!$user_result->num_rows) {
	throw new RuntimeException('No active super-admin user is available for OCMOD refresh.');
}

$session_id = bin2hex(random_bytes(16));
$user_token = bin2hex(random_bytes(16));
$user_id = (int)$user_result->fetch_assoc()['user_id'];
$session_data = json_encode(array(
	'user_id' => $user_id,
	'user_token' => $user_token
));
$session = $database->prepare(
	'REPLACE INTO `' . DB_PREFIX . 'session`
	 (`session_id`, `data`, `expire`)
	 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
);
$session->bind_param('ss', $session_id, $session_data);
$session->execute();

function wobRequestAdminRoute($base_url, $route, $user_token, $session_id) {
	$url = rtrim($base_url, '/') . '/admin/index.php?route=' . rawurlencode($route)
		. '&user_token=' . rawurlencode($user_token);
	$curl = curl_init($url);
	curl_setopt_array($curl, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIE => 'OCSESSID=' . $session_id,
		CURLOPT_TIMEOUT => 180
	));
	$response = curl_exec($curl);
	$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$error = curl_error($curl);
	curl_close($curl);

	if ($response === false || $error !== '' || $status >= 400) {
		throw new RuntimeException($route . ' failed (HTTP ' . $status . '): ' . $error);
	}

	return $response;
}

try {
	wobRequestAdminRoute(HTTP_SERVER, 'marketplace/modification/refresh', $user_token, $session_id);
	wobRequestAdminRoute(HTTP_SERVER, 'common/developer/theme', $user_token, $session_id);
} finally {
	$delete = $database->prepare(
		'DELETE FROM `' . DB_PREFIX . 'session` WHERE `session_id` = ?'
	);
	$delete->bind_param('s', $session_id);
	$delete->execute();
}

function wobActiveCode($project_root, $relative_path) {
	$modified = rtrim(DIR_MODIFICATION, '/') . '/' . $relative_path;
	$source = $project_root . '/upload/' . $relative_path;
	$path = is_file($modified) ? $modified : $source;

	if (!is_file($path)) {
		throw new RuntimeException('Cannot verify active file: ' . $relative_path);
	}

	return file_get_contents($path);
}

$checks = array(
	'catalog/controller/common/header.php' => array('wob-legal-guarantee.css', 'legal_guarantee_image'),
	'catalog/controller/common/footer.php' => array('footer_withdrawal_url', 'footer_pricelists_url', 'footer_guarantee_url'),
	'catalog/controller/checkout/confirm.php' => array('withdrawal_rights_url', 'text_legal_guarantee_link'),
	'catalog/controller/extension/quickcheckout/confirm.php' => array('withdrawal_rights_url', 'text_legal_guarantee_link'),
	'catalog/controller/extension/feed/digital_pricelist.php' => array('ensureDaily', 'getArchivedFile'),
	'catalog/model/catalog/product.php' => array('anchor_price', 'anchor_price_date'),
	'catalog/controller/account/return.php' => array("load->library('return_request')", 'return_request_token'),
	'catalog/view/theme/basel/template/common/header.twig' => array('wob-legal-guarantee-modal', 'legal_guarantee_eu_url'),
	'catalog/view/theme/basel/template/common/footer.twig' => array('wob-prefooter-guarantee', 'footer_pricelists_url')
);

foreach ($checks as $relative_path => $needles) {
	$code = wobActiveCode($project_root, $relative_path);

	foreach ($needles as $needle) {
		if (strpos($code, $needle) === false) {
			throw new RuntimeException('OCMOD refreshed, but ' . $relative_path . ' is missing ' . $needle . '.');
		}
	}
}

echo "OCMOD and Twig caches refreshed; active compliance modules verified.\n";
