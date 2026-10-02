<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('This script can only be run from the command line.');
}

$root = dirname(__DIR__);

function legalGuaranteeFail($message) {
	fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
	exit(1);
}

function legalGuaranteeRead($path) {
	$content = file_get_contents($path);

	if ($content === false) {
		legalGuaranteeFail('Unable to read ' . $path);
	}

	return $content;
}

function legalGuaranteeContains($content, $needle, $message) {
	if (strpos($content, $needle) === false) {
		legalGuaranteeFail($message);
	}
}

function legalGuaranteeNotMatches($content, $pattern, $message) {
	if (preg_match($pattern, $content)) {
		legalGuaranteeFail($message);
	}
}

$notice_path = $root . '/upload/image/catalog/legal/eu-legal-guarantee-hr.png';

if (!is_file($notice_path)) {
	legalGuaranteeFail('The official Croatian legal-guarantee notice is missing.');
}

if (hash_file('sha256', $notice_path) !== '4731df6c6cb59750cb02d80f4c3d83b8756e60661ec446927853c84f4e9dcd1a') {
	legalGuaranteeFail('The official notice was modified.');
}

$image = getimagesize($notice_path);

if (!$image || $image[0] !== 1654 || $image[1] !== 2339 || $image['mime'] !== 'image/png') {
	legalGuaranteeFail('The official notice has unexpected dimensions or format.');
}

$gitignore = legalGuaranteeRead($root . '/.gitignore');
legalGuaranteeContains($gitignore, '!upload/image/catalog/legal/eu-legal-guarantee-hr.png', 'The official notice is not explicitly versioned.');

$header_controller = legalGuaranteeRead($root . '/upload/catalog/controller/common/header.php');
legalGuaranteeContains($header_controller, 'image/catalog/legal/eu-legal-guarantee-hr.png', 'Header does not expose the official notice.');
legalGuaranteeContains($header_controller, 'citizens/consumers/shopping/guarantees/index_hr.htm', 'Header does not expose the official EU information URL.');
legalGuaranteeContains($header_controller, "text_legal_guarantee_full_size", 'Header does not pass the full-size link label to the template.');
legalGuaranteeContains($header_controller, 'wob-legal-guarantee.css', 'Header does not enqueue the legal-guarantee stylesheet.');

$footer_controller = legalGuaranteeRead($root . '/upload/catalog/controller/common/footer.php');
legalGuaranteeContains($footer_controller, "['footer_guarantee_url']", 'Footer does not expose the official notice URL.');
legalGuaranteeContains($footer_controller, "['footer_withdrawal_url']", 'Footer does not expose the withdrawal-form URL.');
legalGuaranteeContains($footer_controller, "['footer_pricelists_url']", 'Footer does not expose the digital-pricelist page URL.');
legalGuaranteeContains($footer_controller, "['show_footer_pricelists']", 'Footer does not respect the digital-pricelist status.');
legalGuaranteeContains($footer_controller, "url->link('account/return/add'", 'Footer withdrawal link does not target the WOB return form.');
legalGuaranteeContains($footer_controller, "url->link('extension/feed/digital_pricelist/page'", 'Footer pricelist link does not target the WOB public pricelist page.');

$checkout_controller = legalGuaranteeRead($root . '/upload/catalog/controller/checkout/confirm.php');
legalGuaranteeContains($checkout_controller, "['legal_guarantee_image']", 'Standard checkout does not expose the official notice URL.');
legalGuaranteeContains($checkout_controller, "['text_legal_guarantee_link']", 'Standard checkout does not expose the guarantee label.');
legalGuaranteeContains($checkout_controller, "['text_withdrawal_rights_copy']", 'Standard checkout does not expose the withdrawal copy.');
legalGuaranteeContains($checkout_controller, "['withdrawal_rights_url']", 'Standard checkout does not expose the withdrawal-form URL.');
legalGuaranteeContains($checkout_controller, "url->link('account/return/add'", 'Standard checkout withdrawal link does not target the WOB return form.');

$quick_checkout_controller = legalGuaranteeRead($root . '/upload/catalog/controller/extension/quickcheckout/confirm.php');
legalGuaranteeContains($quick_checkout_controller, "['legal_guarantee_image']", 'QuickCheckout does not expose the official notice URL.');
legalGuaranteeContains($quick_checkout_controller, "['text_legal_guarantee_link']", 'QuickCheckout does not expose the guarantee label.');
legalGuaranteeContains($quick_checkout_controller, "['text_withdrawal_rights_copy']", 'QuickCheckout does not expose the withdrawal copy.');
legalGuaranteeContains($quick_checkout_controller, "['withdrawal_rights_url']", 'QuickCheckout does not expose the withdrawal-form URL.');
legalGuaranteeContains($quick_checkout_controller, "url->link('account/return/add'", 'QuickCheckout withdrawal link does not target the WOB return form.');

foreach (array('basel', 'default') as $theme) {
	$header = legalGuaranteeRead($root . '/upload/catalog/view/theme/' . $theme . '/template/common/header.twig');
	legalGuaranteeContains($header, 'id="wob-legal-guarantee-modal"', 'The ' . $theme . ' header is missing the notice modal.');
	legalGuaranteeContains($header, 'class="wob-legal-guarantee-notice"', 'The ' . $theme . ' header is missing the complete notice image.');
	legalGuaranteeContains($header, 'text_legal_guarantee_full_size', 'The ' . $theme . ' header is missing the full-size notice link.');
	legalGuaranteeContains($header, "$(document).on('click', 'a.wob-legal-guarantee-trigger'", 'The ' . $theme . ' header is missing the delegated modal handler.');

	$footer = legalGuaranteeRead($root . '/upload/catalog/view/theme/' . $theme . '/template/common/footer.twig');
	legalGuaranteeContains($footer, 'class="wob-prefooter-guarantee"', 'The ' . $theme . ' theme is missing the single highlighted guarantee notice.');
	legalGuaranteeContains($footer, 'text_footer_withdrawal', 'The ' . $theme . ' footer is missing the withdrawal-form link.');
	legalGuaranteeContains($footer, 'text_footer_pricelists', 'The ' . $theme . ' footer is missing the price-list link.');
	legalGuaranteeContains($footer, 'text_footer_guarantee', 'The ' . $theme . ' footer is missing the guarantee link.');
	legalGuaranteeContains($footer, 'footer_withdrawal_url', 'The ' . $theme . ' footer does not consume the withdrawal-form URL.');
	legalGuaranteeContains($footer, 'footer_pricelists_url', 'The ' . $theme . ' footer does not consume the price-list URL.');
	legalGuaranteeContains($footer, 'footer_guarantee_url', 'The ' . $theme . ' footer does not consume the official notice URL.');
	if (substr_count($footer, 'class="wob-legal-guarantee-trigger"') !== 1) {
		legalGuaranteeFail('The ' . $theme . ' footer must show the legal-guarantee link exactly once.');
	}

	$checkout = legalGuaranteeRead($root . '/upload/catalog/view/theme/' . $theme . '/template/checkout/confirm.twig');
	legalGuaranteeContains($checkout, 'text_legal_guarantee_link', 'The ' . $theme . ' checkout is missing the legal-guarantee link.');
	legalGuaranteeContains($checkout, 'text_withdrawal_rights_copy', 'The ' . $theme . ' checkout is missing the separate withdrawal notice.');
	legalGuaranteeContains($checkout, 'legal_guarantee_image', 'The ' . $theme . ' checkout does not consume the official notice URL.');
	legalGuaranteeContains($checkout, 'withdrawal_rights_url', 'The ' . $theme . ' checkout does not consume the withdrawal-form URL.');
}

$quick_checkout = legalGuaranteeRead($root . '/upload/catalog/view/theme/basel/template/extension/quickcheckout/confirm.twig');
legalGuaranteeContains($quick_checkout, 'text_legal_guarantee_link', 'QuickCheckout is missing the legal-guarantee link.');
legalGuaranteeContains($quick_checkout, 'text_withdrawal_rights_copy', 'QuickCheckout is missing the separate withdrawal notice.');
legalGuaranteeContains($quick_checkout, 'legal_guarantee_image', 'QuickCheckout does not consume the official notice URL.');
legalGuaranteeContains($quick_checkout, 'withdrawal_rights_url', 'QuickCheckout does not consume the withdrawal-form URL.');

$mail_controller = legalGuaranteeRead($root . '/upload/catalog/controller/mail/order.php');
$mail_template = legalGuaranteeRead($root . '/upload/catalog/view/theme/default/template/mail/order_add.twig');
legalGuaranteeContains($mail_controller, 'legal_guarantee_image', 'Order mail does not receive the official notice URL.');
legalGuaranteeContains($mail_controller, 'addAttachment($legal_notice)', 'Order mail does not attach the official notice.');
legalGuaranteeContains($mail_controller, "route=account/return/add", 'Order mail does not link to the withdrawal form.');
legalGuaranteeContains($mail_template, '{{ legal_guarantee_image }}', 'Order mail is missing the full-colour notice.');
legalGuaranteeContains($mail_template, '{{ legal_guarantee_eu_url }}', 'Order mail is missing the official EU information link.');
legalGuaranteeContains($mail_template, '{{ withdrawal_rights_url }}', 'Order mail is missing the withdrawal-form link.');

$integration_sources = implode("\n", array(
	$header_controller,
	$footer_controller,
	$checkout_controller,
	$quick_checkout_controller,
	$mail_controller,
	$mail_template,
	$quick_checkout
));
legalGuaranteeNotMatches(
	$integration_sources,
	'/information_id\s*=\s*[\'\"]?17\b|eurosender|dryzen/i',
	'Compliance integration contains a Dryzen-specific page or excluded Eurosender coupling.'
);

$notice_css = legalGuaranteeRead($root . '/upload/catalog/view/theme/basel/stylesheet/wob-legal-guarantee.css');
legalGuaranteeContains($notice_css, 'overflow-x: auto', 'The mobile notice cannot be panned at a readable size.');
legalGuaranteeContains($notice_css, 'width: 900px', 'The mobile notice is reduced to an illegible full-page thumbnail.');

foreach (array('hr-hr', 'en-gb') as $language) {
	$checkout_language = legalGuaranteeRead($root . '/upload/catalog/language/' . $language . '/checkout/checkout.php');
	$mail_language = legalGuaranteeRead($root . '/upload/catalog/language/' . $language . '/mail/order_add.php');
	legalGuaranteeContains($checkout_language, 'text_legal_guarantee_link', 'Checkout guarantee copy is missing for ' . $language . '.');
	legalGuaranteeContains($checkout_language, 'text_withdrawal_rights_copy', 'Checkout withdrawal copy is missing for ' . $language . '.');
	legalGuaranteeContains($mail_language, 'text_legal_guarantee_heading', 'Mail guarantee copy is missing for ' . $language . '.');
	legalGuaranteeContains($mail_language, 'text_withdrawal_heading', 'Mail withdrawal copy is missing for ' . $language . '.');
}

echo 'Legal-guarantee checks passed.' . PHP_EOL;
