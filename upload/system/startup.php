<?php
// Error Reporting
error_reporting(E_ALL);

// Check Version
if (version_compare(phpversion(), '7.3.0', '<') == true) {
	exit('PHP7.3+ Required');
}

if (!ini_get('date.timezone')) {
	date_default_timezone_set('UTC');
}

// Windows IIS Compatibility
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
	if (isset($_SERVER['SCRIPT_FILENAME'])) {
		$_SERVER['DOCUMENT_ROOT'] = str_replace('\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0 - strlen($_SERVER['PHP_SELF'])));
	}
}

if (!isset($_SERVER['DOCUMENT_ROOT'])) {
	if (isset($_SERVER['PATH_TRANSLATED'])) {
		$_SERVER['DOCUMENT_ROOT'] = str_replace('\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0 - strlen($_SERVER['PHP_SELF'])));
	}
}

if (!isset($_SERVER['REQUEST_URI'])) {
	$_SERVER['REQUEST_URI'] = substr($_SERVER['PHP_SELF'], 1);

	if (isset($_SERVER['QUERY_STRING'])) {
		$_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
	}
}

if (!isset($_SERVER['HTTP_HOST'])) {
	$_SERVER['HTTP_HOST'] = getenv('HTTP_HOST');
}

// Check if SSL
if ((isset($_SERVER['HTTPS']) && (($_SERVER['HTTPS'] == 'on') || ($_SERVER['HTTPS'] == '1'))) || (isset($_SERVER['HTTPS']) && (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443))) {
	$_SERVER['HTTPS'] = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
	$_SERVER['HTTPS'] = true;
} else {
	$_SERVER['HTTPS'] = false;
}

// Modification Override
function modification($filename) {
	if (defined('DIR_CATALOG')) {
		$file = DIR_MODIFICATION . 'admin/' .  substr($filename, strlen(DIR_APPLICATION));
	} elseif (defined('DIR_OPENCART')) {
		$file = DIR_MODIFICATION . 'install/' .  substr($filename, strlen(DIR_APPLICATION));
	} else {
		$file = DIR_MODIFICATION . 'catalog/' . substr($filename, strlen(DIR_APPLICATION));
	}

	if (substr($filename, 0, strlen(DIR_SYSTEM)) == DIR_SYSTEM) {
		$file = DIR_MODIFICATION . 'system/' . substr($filename, strlen(DIR_SYSTEM));
	}

	if (is_file($file)) {
		return $file;
	}

	return $filename;
}

// Autoloader
$bundled_vendor_dir = DIR_SYSTEM . 'storage/vendor/';
$external_vendor_dirs = array();

if (defined('DIR_STORAGE')) {
	$external_vendor_dirs[] = rtrim(DIR_STORAGE, '/\\') . '/vendor/';
}

// The repository keeps the application Composer packages in its tracked
// storage directory. Production can use a different DIR_STORAGE location for
// runtime files, so do not assume that its vendor directory contains them.
$project_root = dirname(rtrim(DIR_SYSTEM, '/\\'), 2);
$repository_vendor_dir = $project_root . '/storagedijana/storagedijana/vendor/';

if (!in_array($repository_vendor_dir, $external_vendor_dirs, true)) {
	$external_vendor_dirs[] = $repository_vendor_dir;
}

$external_vendor_dir = '';

// Prefer the installation that actually contains the custom API package used
// by order export. A stale runtime vendor directory may still have a valid
// autoload.php while missing Agmedia\\Api entirely.
foreach ($external_vendor_dirs as $candidate_vendor_dir) {
	if (
		is_file($candidate_vendor_dir . 'autoload.php') &&
		is_file($candidate_vendor_dir . 'agmedia/api/src/Helper/Helper.php')
	) {
		$external_vendor_dir = $candidate_vendor_dir;
		break;
	}
}

if (!$external_vendor_dir) {
	foreach ($external_vendor_dirs as $candidate_vendor_dir) {
		if (is_file($candidate_vendor_dir . 'autoload.php')) {
			$external_vendor_dir = $candidate_vendor_dir;
			break;
		}
	}
}

$external_vendor_autoload = $external_vendor_dir ? $external_vendor_dir . 'autoload.php' : '';

if ($external_vendor_autoload && is_file($external_vendor_autoload)) {
	$vendor_loader = require_once($external_vendor_autoload);
	$bundled_composer_dir = $bundled_vendor_dir . 'composer/';

	// Production storage can contain only application-specific packages. Merge the
	// bundled OpenCart dependencies into the active loader without loading a second
	// Composer runtime (both runtimes may share the same generated class name).
	if (is_object($vendor_loader)) {
		$namespace_map = $bundled_composer_dir . 'autoload_namespaces.php';
		$psr4_map = $bundled_composer_dir . 'autoload_psr4.php';
		$class_map = $bundled_composer_dir . 'autoload_classmap.php';
		$files_map = $bundled_composer_dir . 'autoload_files.php';

		if (is_file($namespace_map) && method_exists($vendor_loader, 'add')) {
			foreach ((array)require($namespace_map) as $prefix => $paths) {
				$vendor_loader->add($prefix, $paths, true);
			}
		}

		if (is_file($psr4_map) && method_exists($vendor_loader, 'addPsr4')) {
			foreach ((array)require($psr4_map) as $prefix => $paths) {
				$vendor_loader->addPsr4($prefix, $paths, true);
			}
		}

		if (is_file($class_map) && method_exists($vendor_loader, 'addClassMap')) {
			$vendor_loader->addClassMap((array)require($class_map));
		}

		if (is_file($files_map)) {
			foreach ((array)require($files_map) as $file) {
				require_once($file);
			}
		}
	}

	require_once(DIR_CONFIG . 'eloquent.php');
} elseif (is_file($bundled_vendor_dir . 'autoload.php')) {
	require_once($bundled_vendor_dir . 'autoload.php');
}

function library($class) {
	$file = DIR_SYSTEM . 'library/' . str_replace('\\', '/', strtolower($class)) . '.php';

	if (is_file($file)) {
		include_once(modification($file));

		return true;
	} else {
		return false;
	}
}

spl_autoload_register('library');
spl_autoload_extensions('.php');

// Engine
require_once(modification(DIR_SYSTEM . 'engine/action.php'));
require_once(modification(DIR_SYSTEM . 'engine/controller.php'));
require_once(modification(DIR_SYSTEM . 'engine/event.php'));
require_once(modification(DIR_SYSTEM . 'engine/router.php'));
require_once(modification(DIR_SYSTEM . 'engine/loader.php'));
require_once(modification(DIR_SYSTEM . 'engine/model.php'));
require_once(modification(DIR_SYSTEM . 'engine/registry.php'));
require_once(modification(DIR_SYSTEM . 'engine/proxy.php'));

// Helper
require_once(DIR_SYSTEM . 'helper/general.php');
require_once(DIR_SYSTEM . 'helper/utf8.php');

function start($application_config) {
	require_once(DIR_SYSTEM . 'framework.php');	
}
