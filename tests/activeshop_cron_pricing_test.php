<?php

define('WOB_ACTIVESHOP_CRON_FUNCTIONS_ONLY', true);
require dirname(__DIR__) . '/upload/update.php';

function activeShopCronPricingAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function activeShopCronPricingAssertSame($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
		);
	}
}

class ActiveShopCronPricingSettingResult {
	public $num_rows;
	public $freed = false;
	private $value;

	public function __construct($has_row, $value = null) {
		$this->num_rows = $has_row ? 1 : 0;
		$this->value = $value;
	}

	public function fetch_assoc() {
		return array('value' => $this->value);
	}

	public function free() {
		$this->freed = true;
	}
}

$setting_sql = activeShopConfiguredMarkupSql();
activeShopCronPricingAssert(strpos($setting_sql, "`store_id` = '0'") !== false, 'Configured markup must come from store_id 0.');
activeShopCronPricingAssert(strpos($setting_sql, "`code` = 'module_activeshop_importer'") !== false, 'Configured markup must use the exact module setting code.');
activeShopCronPricingAssert(strpos($setting_sql, "`key` = 'module_activeshop_importer_markup'") !== false, 'Configured markup must use the exact module setting key.');

$valid_setting_result = new ActiveShopCronPricingSettingResult(true, '63.3000');
activeShopCronPricingAssertSame(63.3, activeShopConfiguredMarkupResult($valid_setting_result), 'A valid configured module markup must be loaded.');
activeShopCronPricingAssert($valid_setting_result->freed, 'The setting result must always be freed.');
$zero_setting_result = new ActiveShopCronPricingSettingResult(true, '0');
activeShopCronPricingAssertSame(0.0, activeShopConfiguredMarkupResult($zero_setting_result), 'Configured zero markup is valid and must not fall through to legacy pricing.');
$missing_setting_result = new ActiveShopCronPricingSettingResult(false);
activeShopCronPricingAssertSame(null, activeShopConfiguredMarkupResult($missing_setting_result), 'A missing configured markup must enable the legacy fallback.');
$invalid_setting_result = new ActiveShopCronPricingSettingResult(true, '1000.0001');
activeShopCronPricingAssertSame(null, activeShopConfiguredMarkupResult($invalid_setting_result), 'A configured markup above 1000 must be rejected.');

foreach (array(null, '', 'not-a-number', -0.0001, 1000.0001, INF, array(10)) as $invalid_markup) {
	activeShopCronPricingAssertSame(null, activeShopNormalizeMarkup($invalid_markup), 'Invalid markup values must be rejected.');
}
activeShopCronPricingAssertSame(0.0, activeShopNormalizeMarkup('0'), 'The lower markup boundary must be accepted.');
activeShopCronPricingAssertSame(1000.0, activeShopNormalizeMarkup('1000'), 'The upper markup boundary must be accepted.');
activeShopCronPricingAssertSame(63.3333, activeShopNormalizeMarkup('63.33334'), 'Valid markup must be normalized to four decimal places.');

$managed = activeShopResolvePricing('10', '63', 5.0);
activeShopCronPricingAssertSame(array('source' => 'managed', 'markup' => 10.0), $managed, 'Exact managed markup must have first priority.');
$configured = activeShopResolvePricing(null, '63', 5.0);
activeShopCronPricingAssertSame(array('source' => 'configured', 'markup' => 63.0), $configured, 'Configured module markup must be the second priority.');
$configured_after_invalid_managed = activeShopResolvePricing('1001', '63', 5.0);
activeShopCronPricingAssertSame(array('source' => 'configured', 'markup' => 63.0), $configured_after_invalid_managed, 'Invalid managed markup must safely fall back to configured markup.');
$configured_zero = activeShopResolvePricing(null, '0', 5.0);
activeShopCronPricingAssertSame(array('source' => 'configured', 'markup' => 0.0), $configured_zero, 'Configured zero markup must remain a configured result.');
$legacy_light = activeShopResolvePricing(null, null, 2.0);
activeShopCronPricingAssertSame(array('source' => 'legacy', 'markup' => 20.0), $legacy_light, 'Legacy weight at the boundary must use the light 20% rule.');
$legacy_heavy = activeShopResolvePricing(null, 'invalid', 2.0001);
activeShopCronPricingAssertSame(array('source' => 'legacy', 'markup' => 40.0), $legacy_heavy, 'Missing or invalid configured markup must use the heavy 40% rule above 2 kg.');

$managed_sql = activeShopManagedMarkupSql();
activeShopCronPricingAssert(strpos($managed_sql, "s.`code` = 'activeshop'") !== false, 'Managed pricing must be restricted to ActiveShop.');
activeShopCronPricingAssert(strpos($managed_sql, 'sp.`product_id` = ?') !== false, 'Managed pricing must match the exact local product.');
activeShopCronPricingAssert(strpos($managed_sql, 'sp.`external_id` = ?') !== false, 'Managed pricing must match the exact supplier SKU.');
activeShopCronPricingAssert(strpos($managed_sql, "sp.`is_current` = '1'") !== false, 'Managed pricing must use a current supplier mapping.');
activeShopCronPricingAssert(strpos($managed_sql, 'sp.`last_markup` IS NOT NULL') !== false, 'Managed pricing requires an explicit per-product markup.');
activeShopCronPricingAssert(strpos($managed_sql, 'last_imported') === false, 'Managed pricing must not require a synthetic last_imported timestamp.');
activeShopCronPricingAssertSame(2, substr_count($managed_sql, '?'), 'Managed pricing must bind exactly product_id and external_id.');

$feed = new WobSupplierActiveShopFeed();
$feed_price = 315.24;
activeShopCronPricingAssertSame(513.84, $feed->calculatePrice($feed_price, $configured['markup']), 'Configured 63% pricing must use fresh feed price and two-decimal rounding.');
activeShopCronPricingAssertSame(378.29, $feed->calculatePrice($feed_price, $legacy_light['markup']), 'Legacy light pricing must use the shared two-decimal feed formula.');
activeShopCronPricingAssertSame(441.34, $feed->calculatePrice($feed_price, $legacy_heavy['markup']), 'Legacy heavy pricing must use the shared two-decimal feed formula.');

$cron_source = file_get_contents(dirname(__DIR__) . '/upload/update.php');
activeShopCronPricingAssert(strpos($cron_source, "\$price = \$feed->calculatePrice(\$feedPrice, \$pricing['markup']);") !== false, 'Cron must calculate every pricing branch through the shared feed helper.');
activeShopCronPricingAssert(strpos($cron_source, 'Configured markup products: ') !== false, 'Cron output must report configured-markup usage.');
activeShopCronPricingAssert(strpos($cron_source, 'Legacy products: ') !== false, 'Cron output must retain legacy fallback reporting.');

echo "ActiveShop cron pricing tests passed; fresh feed price and markup priority are consistent.\n";
