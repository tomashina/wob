<?php
// Redirect direct theme image requests only on local Herd domains.
if (isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) && preg_match('/\.test(?::\d+)?$/i', $_SERVER['HTTP_HOST'])) {
	$image_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

	if (strpos($image_path, '/image/') === 0) {
		header('Location: https://www.worldofbeauty.hr' . $_SERVER['REQUEST_URI'], true, 302);
		exit;
	}

	// Herd uses Nginx, so mirror OpenCart's Apache SEO rewrite locally.
	$local_route = trim(rawurldecode($image_path), '/');

	if ($local_route && $local_route !== 'index.php' && strpos($local_route, 'admin/') !== 0 && !isset($_GET['route'], $_GET['_route_'])) {
		$_GET['_route_'] = $local_route;
	}
}

// Version

define('VERSION', '3.0.3.8');

// Configuration
if (is_file('config.php')) {
	require_once('config.php');
}

// Agmedia custom Configuration
if (is_file('env.php')) {
    require_once('env.php');
}

// Install
if (!defined('DIR_APPLICATION')) {
	header('Location: install/index.php');
	exit;
}

// Startup
require_once(DIR_SYSTEM . 'startup.php');

start('catalog');
