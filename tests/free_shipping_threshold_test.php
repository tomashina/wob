<?php

require dirname(__DIR__) . '/upload/admin/config.php';
ini_set('display_errors', '0');
require DIR_SYSTEM . 'startup.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once DIR_CATALOG . 'model/extension/shipping/free.php';
require_once DIR_CATALOG . 'model/extension/shipping/xshippingpro.php';

function freeShippingAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function freeShippingAssertFloat($expected, $actual, $message) {
	freeShippingAssert(abs((float)$expected - (float)$actual) < 0.00001, $message . ' Expected ' . $expected . ', got ' . $actual . '.');
}

class FreeShippingTestCart {
	public $products = array();

	public function getProducts() {
		return $this->products;
	}

	public function getSubTotal() {
		throw new RuntimeException('Free shipping must not use the legacy net subtotal.');
	}
}

class FreeShippingTestTax {
	public function calculate($value, $tax_class_id, $calculate = true) {
		return $tax_class_id && $calculate ? (float)$value * 1.25 : (float)$value;
	}
}

class FreeShippingTestDbResult {
	public $num_rows;
	public $rows;
	public $row;

	public function __construct($matches) {
		$this->num_rows = $matches ? 1 : 0;
		$this->rows = $matches ? array(array('geo_zone_id' => 7)) : array();
		$this->row = $matches ? $this->rows[0] : array();
	}
}

class FreeShippingTestDb {
	public $allowed_country_id = 191;
	public $free_installed = true;

	public function query($sql) {
		if (strpos($sql, DB_PREFIX . "extension`") !== false) {
			return new FreeShippingTestDbResult($this->free_installed);
		}

		$matches = strpos($sql, "geo_zone_id = '7'") !== false
			&& strpos($sql, "country_id = '" . (int)$this->allowed_country_id . "'") !== false;

		return new FreeShippingTestDbResult($matches);
	}
}

class FreeShippingTestLanguage {
	public function get($key) {
		return $key;
	}
}

class FreeShippingTestCurrency {
	public function format($value, $currency) {
		return number_format((float)$value, 2, '.', '') . ' ' . $currency;
	}

	public function getDecimalPlace($currency) {
		return 2;
	}
}

class FreeShippingTestLoader {
	private $registry;
	public $loaded_models = array();

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function language($route) {
		return array();
	}

	public function model($route) {
		$this->loaded_models[] = $route;
	}
}

function createFreeShippingRegistry() {
	$registry = new Registry();
	$config = new Config();
	$config->set('shipping_free_status', 1);
	$config->set('shipping_free_total', 200);
	$config->set('shipping_free_geo_zone_id', 0);
	$config->set('shipping_free_sort_order', 1);
	$config->set('config_currency', 'EUR');
	$registry->set('config', $config);

	$cart = new FreeShippingTestCart();
	$registry->set('cart', $cart);
	$registry->set('tax', new FreeShippingTestTax());
	$registry->set('db', new FreeShippingTestDb());
	$registry->set('language', new FreeShippingTestLanguage());
	$registry->set('currency', new FreeShippingTestCurrency());

	$session = new stdClass();
	$session->data = array('currency' => 'EUR');
	$registry->set('session', $session);
	$registry->set('load', new FreeShippingTestLoader($registry));

	return $registry;
}

$registry = createFreeShippingRegistry();
$config = $registry->get('config');
$cart = $registry->get('cart');
$free = new ModelExtensionShippingFree($registry);
freeShippingAssert($free->isInstalled() === true, 'The core free-shipping extension must be recognized as installed.');

// Cart products expose the active price after OpenCart has applied specials.
// The unrelated regular_price proves that the gross active price is the basis.
$cart->products = array(array(
	'price' => 159.992,
	'regular_price' => 300,
	'quantity' => 1,
	'tax_class_id' => 1
));
$progress = $free->getThresholdProgress();
freeShippingAssert($progress['enabled'] === true, 'A configured positive free-shipping threshold must be enabled.');
freeShippingAssertFloat(199.99, $progress['cart_total'], 'The progress total must include product tax.');
freeShippingAssertFloat(0.01, $progress['remaining'], 'The progress must report the exact amount remaining below the threshold.');
freeShippingAssert($progress['reached'] === false, '199.99 must not reach a 200.00 threshold.');
freeShippingAssert($free->getQuote(array('country_id' => 191, 'zone_id' => 0)) === array(), 'Free shipping must not quote below the threshold.');

$cart->products[0]['price'] = 160;
$progress = $free->getThresholdProgress();
freeShippingAssertFloat(200, $progress['cart_total'], 'The gross cart total at the threshold must be 200.00.');
freeShippingAssertFloat(0, $progress['remaining'], 'Nothing must remain at the threshold.');
freeShippingAssert($progress['reached'] === true, 'The threshold comparison must be inclusive.');
freeShippingAssert(!empty($free->getQuote(array('country_id' => 191, 'zone_id' => 0))['quote']['free']), 'Free shipping must quote at exactly 200.00.');

$cart->products[0]['price'] = 159.99999999999;
$progress = $free->getThresholdProgress();
freeShippingAssertFloat(200, $progress['cart_total'], 'A floating-point total displayed as 200.00 must be normalized to currency precision.');
freeShippingAssert($progress['reached'] === true, 'A floating-point total displayed as 200.00 must qualify for free shipping.');

$config->set('shipping_free_status', 0);
$progress = $free->getThresholdProgress();
freeShippingAssert($progress['enabled'] === false && $progress['reached'] === false, 'A disabled module must not expose a reached threshold.');
freeShippingAssert($free->getQuote(array('country_id' => 191, 'zone_id' => 0)) === array(), 'A disabled module must never quote.');

$config->set('shipping_free_status', 1);
$config->set('shipping_free_total', 0);
$progress = $free->getThresholdProgress();
freeShippingAssert($progress['enabled'] === false && $progress['reached'] === false, 'A blank or zero threshold must fail closed.');

$config->set('shipping_free_total', 200);
$config->set('shipping_free_geo_zone_id', 7);
$allowed_address = array('country_id' => 191, 'zone_id' => 13);
$denied_address = array('country_id' => 999, 'zone_id' => 0);
freeShippingAssert($free->getThresholdProgress($allowed_address)['reached'] === true, 'A matching geo-zone address must remain eligible.');
freeShippingAssert($free->getQuote($denied_address) === array(), 'A non-matching geo-zone address must not receive free shipping.');
freeShippingAssert($free->getQuote(array()) === array(), 'A partial address must be warning-safe and ineligible for a restricted geo-zone.');

// X-Shipping Pro must stop before evaluating any paid method once the same
// address receives the core free-shipping quote.
$registry->set('model_extension_shipping_free', $free);
$registry->set('ocm_front', new stdClass());
$xshipping = new ModelExtensionShippingXshippingpro($registry);
freeShippingAssert($xshipping->getQuote($allowed_address) === array(), 'X-Shipping Pro must hide all paid quotes when core free shipping applies.');
freeShippingAssert(in_array('extension/shipping/free', $registry->get('load')->loaded_models, true), 'X-Shipping Pro must delegate the threshold decision to the core free-shipping model.');

$hide_check = new ReflectionMethod($xshipping, 'hasCoreFreeShippingQuote');
$hide_check->setAccessible(true);
freeShippingAssert($hide_check->invoke($xshipping, $denied_address) === false, 'X-Shipping Pro must not hide paid methods outside the free-shipping geo-zone.');
$config->set('shipping_free_status', 0);
freeShippingAssert($hide_check->invoke($xshipping, $allowed_address) === false, 'X-Shipping Pro must not hide paid methods when core free shipping is disabled.');
$config->set('shipping_free_status', 1);
$cart->products[0]['price'] = 159.992;
freeShippingAssert($hide_check->invoke($xshipping, $allowed_address) === false, 'X-Shipping Pro must not hide paid methods at 199.99.');

$stale_registry = createFreeShippingRegistry();
$stale_registry->get('db')->free_installed = false;
$stale_registry->get('cart')->products = array(array(
	'price' => 160,
	'quantity' => 1,
	'tax_class_id' => 1
));
$stale_free = new ModelExtensionShippingFree($stale_registry);
$stale_progress = $stale_free->getThresholdProgress();
freeShippingAssert($stale_progress['enabled'] === false && $stale_progress['reached'] === false, 'Stale enabled settings must not activate an uninstalled core extension.');
freeShippingAssert($stale_free->getQuote($allowed_address) === array(), 'An uninstalled core extension must never return a quote.');
$stale_registry->set('model_extension_shipping_free', $stale_free);
$stale_registry->set('ocm_front', new stdClass());
$stale_xshipping = new ModelExtensionShippingXshippingpro($stale_registry);
$stale_hide_check = new ReflectionMethod($stale_xshipping, 'hasCoreFreeShippingQuote');
$stale_hide_check->setAccessible(true);
freeShippingAssert($stale_hide_check->invoke($stale_xshipping, $allowed_address) === false, 'Stale free-shipping settings must not hide installed paid methods.');

echo "Free-shipping threshold tests passed.\n";
