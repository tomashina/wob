<?php

function freeShippingNoticeAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__);
$controllers = array(
	'checkout cart' => file_get_contents($root . '/upload/catalog/controller/checkout/cart.php'),
	'mini cart' => file_get_contents($root . '/upload/catalog/controller/common/cart.php'),
	'quick checkout cart' => file_get_contents($root . '/upload/catalog/controller/extension/quickcheckout/cart.php')
);
$templates = array(
	'checkout cart' => file_get_contents($root . '/upload/catalog/view/theme/basel/template/checkout/cart.twig'),
	'mini cart' => file_get_contents($root . '/upload/catalog/view/theme/basel/template/common/cart.twig'),
	'quick checkout cart' => file_get_contents($root . '/upload/catalog/view/theme/basel/template/extension/quickcheckout/cart.twig')
);
$catalog_languages = array(
	'hr-hr' => file_get_contents($root . '/upload/catalog/language/hr-hr/extension/shipping/free.php'),
	'en-gb' => file_get_contents($root . '/upload/catalog/language/en-gb/extension/shipping/free.php')
);
$admin_languages = array(
	'hr-hr' => file_get_contents($root . '/upload/admin/language/hr-hr/extension/shipping/free.php'),
	'en-gb' => file_get_contents($root . '/upload/admin/language/en-gb/extension/shipping/free.php')
);

foreach ($controllers as $name => $controller) {
	freeShippingNoticeAssert($controller !== false, ucfirst($name) . ' controller must be readable.');
	freeShippingNoticeAssert(strpos($controller, "get('shipping_free_status')") !== false, ucfirst($name) . ' must hide the notice while free shipping is disabled.');
	freeShippingNoticeAssert(strpos($controller, '$this->cart->hasProducts()') !== false, ucfirst($name) . ' must hide the notice for an empty cart.');
	freeShippingNoticeAssert(strpos($controller, "model('extension/shipping/free')") !== false, ucfirst($name) . ' must load the shared free-shipping model.');
	freeShippingNoticeAssert(strpos($controller, "session->data['shipping_address']") !== false, ucfirst($name) . ' must respect the known shipping address and configured geo-zone.');
	freeShippingNoticeAssert(strpos($controller, 'getThresholdProgress($free_shipping_address)') !== false, ucfirst($name) . ' must use the shared threshold calculation contract.');
	freeShippingNoticeAssert(strpos($controller, "['remaining']") !== false && strpos($controller, "['reached']") !== false, ucfirst($name) . ' must render both threshold states.');
	freeShippingNoticeAssert(strpos($controller, '$this->currency->format(') !== false, ucfirst($name) . ' must format the remaining amount in the active currency.');
}

foreach ($templates as $name => $template) {
	freeShippingNoticeAssert($template !== false, ucfirst($name) . ' template must be readable.');
	freeShippingNoticeAssert(strpos($template, '{% if free_shipping_notice %}') !== false, ucfirst($name) . ' must render the notice only when progress is available.');
	freeShippingNoticeAssert(strpos($template, 'free_shipping_notice.reached') !== false, ucfirst($name) . ' must distinguish progress and success states.');
	freeShippingNoticeAssert(strpos($template, 'free_shipping_notice.text|escape') !== false, ucfirst($name) . ' must escape the localized notice text.');
	freeShippingNoticeAssert(strpos($template, 'role="status"') !== false, ucfirst($name) . ' must expose live cart progress as a status message.');
}

foreach ($catalog_languages as $language_code => $language) {
	freeShippingNoticeAssert($language !== false, $language_code . ' catalog language must be readable.');
	$remaining_key = '$' . "_['text_free_shipping_remaining']";
	$reached_key = '$' . "_['text_free_shipping_reached']";
	freeShippingNoticeAssert(strpos($language, $remaining_key) !== false, $language_code . ' must define the remaining-amount message.');
	freeShippingNoticeAssert(strpos($language, $reached_key) !== false, $language_code . ' must define the reached-threshold message.');
}

freeShippingNoticeAssert(strpos($admin_languages['hr-hr'], 's PDV-om') !== false, 'Croatian admin help must describe a tax-inclusive product threshold.');
freeShippingNoticeAssert(strpos($admin_languages['en-gb'], 'including tax') !== false, 'English admin help must describe a tax-inclusive product threshold.');

$quickcheckout = file_get_contents($root . '/upload/catalog/view/theme/basel/template/extension/quickcheckout/checkout.twig');
freeShippingNoticeAssert(strpos($quickcheckout, "$('#cart1 .quickcheckout-content').html(html);") !== false, 'Quick checkout must replace the full cart partial so the notice updates with quantity changes.');
freeShippingNoticeAssert(strpos($quickcheckout, "$('#cart-content').load('index.php?route=common/cart/info #cart-content > *');") !== false, 'Quick checkout must reload the mini cart so its notice stays in sync.');

echo "Free shipping notice tests passed.\n";
