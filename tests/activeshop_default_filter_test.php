<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once DIR_APPLICATION . 'controller/extension/module/activeshop_importer.php';

function defaultFilterAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$registry = new Registry();
$request = new Request();
$registry->set('request', $request);
$controller = new ControllerExtensionModuleActiveshopImporter($registry);

$get_filters = new ReflectionMethod($controller, 'getProductFilters');
$get_filters->setAccessible(true);
$build_url = new ReflectionMethod($controller, 'buildFilterUrl');
$build_url->setAccessible(true);

$request->get = array();
$filters = $get_filters->invoke($controller);
defaultFilterAssert($filters['filter_status'] === 'new', 'A fresh importer page must default to new products.');

$request->get = array('filter_status' => 'all');
$filters = $get_filters->invoke($controller);
defaultFilterAssert($filters['filter_status'] === '', 'The explicit all-statuses choice must disable the status filter.');
defaultFilterAssert(strpos($build_url->invoke($controller), '&filter_status=all') !== false, 'Pagination and redirects must preserve the explicit all-statuses choice.');

$request->get = array('filter_status' => 'existing');
$filters = $get_filters->invoke($controller);
defaultFilterAssert($filters['filter_status'] === 'existing', 'An explicitly selected supported status must be preserved.');

$request->get = array('filter_status' => 'invalid');
$filters = $get_filters->invoke($controller);
defaultFilterAssert($filters['filter_status'] === 'new', 'An invalid status must fail safely to the default new-products filter.');

$template = file_get_contents(DIR_TEMPLATE . 'extension/module/activeshop_importer.twig');
defaultFilterAssert(strpos($template, 'id="form-activeshop-') === false, 'Importer form IDs must not match the OpenCart common.js form-* global submit selector.');
defaultFilterAssert(strpos($template, '<button type="submit"') === false, 'Importer buttons must explicitly submit only their own form.');
foreach (array('refresh', 'filter', 'import') as $action) {
	defaultFilterAssert(strpos($template, "document.getElementById('activeshop-" . $action . "-form').submit()") !== false, ucfirst($action) . ' must submit its own form explicitly.');
}

echo "ActiveShop default filter tests passed.\n";
