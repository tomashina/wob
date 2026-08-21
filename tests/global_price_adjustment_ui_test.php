<?php

function globalPriceUiAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__);
$template = file_get_contents($root . '/upload/admin/view/template/extension/module/global_price_adjustment.twig');
$controller = file_get_contents($root . '/upload/admin/controller/extension/module/global_price_adjustment.php');
$languages = array(
	'hr-hr' => file_get_contents($root . '/upload/admin/language/hr-hr/extension/module/global_price_adjustment.php'),
	'en-gb' => file_get_contents($root . '/upload/admin/language/en-gb/extension/module/global_price_adjustment.php')
);

globalPriceUiAssert($template !== false && $controller !== false, 'Global price adjustment UI files must be readable.');
globalPriceUiAssert(strpos($template, 'id="form-') === false, 'Price form IDs must not match the OpenCart common.js form-* global submit selector.');
globalPriceUiAssert(strpos($template, '<button type="submit"') === false, 'Price action buttons must not trigger OpenCart common.js global form submission.');
globalPriceUiAssert(strpos($template, 'form.checkValidity()') !== false && strpos($template, 'form.reportValidity()') !== false, 'Native required-field validation must run before programmatic submission.');
globalPriceUiAssert(strpos($template, 'form.submit();') !== false, 'Validated price actions must use the selected form native submit method.');

foreach (array('preview', 'apply', 'rollback') as $action) {
	$form_id = 'price-' . $action . '-form';
	$button_id = 'button-price-' . $action;
	globalPriceUiAssert(strpos($template, 'id="' . $form_id . '"') !== false, ucfirst($action) . ' must have its own non-global form ID.');
	globalPriceUiAssert(strpos($template, 'id="' . $button_id . '"') !== false, ucfirst($action) . ' must have an explicit action button.');
	globalPriceUiAssert(strpos($template, "submitPriceForm('" . $form_id . "'") !== false, ucfirst($action) . ' must submit only its own form.');
}

globalPriceUiAssert(strpos($template, "submitPriceForm('price-apply-form', {{ text_apply_confirm") !== false, 'Apply must require its dedicated confirmation dialog.');
globalPriceUiAssert(strpos($template, "submitPriceForm('price-rollback-form', {{ text_rollback_confirm") !== false, 'Rollback must require its dedicated confirmation dialog.');
globalPriceUiAssert(substr_count($template, 'name="confirm_') === 2 && substr_count($template, 'name="confirm_apply" value="1" required') === 1 && substr_count($template, 'name="confirm_rollback" value="1" required') === 1, 'Apply and rollback confirmations must remain required fields.');

foreach (array('column_current_price', 'column_base_price', 'text_legacy_basis_warning', 'text_source_conflicts', 'text_source_conflicts_warning') as $ui_key) {
	globalPriceUiAssert(strpos($template, $ui_key) !== false, 'The template must render ' . $ui_key . '.');
	foreach ($languages as $language_code => $language) {
		$language_key = '$' . "_['" . $ui_key . "']";
		globalPriceUiAssert($language !== false && strpos($language, $language_key) !== false, $language_code . ' must define ' . $ui_key . '.');
	}
}

foreach (array('text_source_activeshop_feed', 'text_source_catalog_regular', 'text_source_legacy') as $source_key) {
	globalPriceUiAssert(strpos($controller, "'" . $source_key . "'") !== false, 'The controller must map ' . $source_key . '.');
	foreach ($languages as $language_code => $language) {
		$language_key = '$' . "_['" . $source_key . "']";
		globalPriceUiAssert($language !== false && strpos($language, $language_key) !== false, $language_code . ' must define ' . $source_key . '.');
	}
}

globalPriceUiAssert(strpos($controller, "'Price source changed after preview.' => 'text_item_message_price_source_changed'") !== false, 'The controller must localize feed-source CAS conflicts.');
globalPriceUiAssert(strpos($controller, "'Managed supplier pricing changed after preview.' => 'text_item_message_managed_pricing_changed'") !== false, 'The controller must localize managed-markup CAS conflicts.');
globalPriceUiAssert(strpos($controller, "['is_legacy_basis']") !== false, 'The controller must decorate legacy previews for a clear warning.');
globalPriceUiAssert(strpos($controller, 'priceSourceText') !== false && strpos($controller, 'priceSourceBadgeClass') !== false, 'The controller must decorate each immutable price basis.');
globalPriceUiAssert(strpos($controller, "'/activeshop-importer'") !== false, 'Price preview/apply/rollback must share the ActiveShop operation lock with feed refresh and cron.');

echo "Global price adjustment UI tests passed.\n";
