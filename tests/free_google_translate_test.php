<?php

require_once dirname(__DIR__) . '/upload/system/library/wob_supplier/free_google_translate.php';

function translationAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function translationAssertSame($expected, $actual, $message) {
	translationAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function translationAssertThrows($callback, $message) {
	try {
		$callback();
	} catch (Throwable $exception) {
		return;
	}

	throw new RuntimeException($message);
}

$cache_directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wob-translation-test-' . bin2hex(random_bytes(8));
$calls = 0;

$transport = function ($url, $fields) use (&$calls) {
	$calls++;
	translationAssert(strpos($url, 'client=gtx') !== false, 'The keyless Google client must be selected.');
	translationAssert(strpos($url, 'sl=en') !== false, 'English must be the source language.');
	translationAssert(strpos($url, 'tl=hr') !== false, 'Croatian must be the target language.');
	translationAssert(isset($fields['q']), 'Translation source HTML must be sent as q.');

	$translated = str_replace(
		array('Professional cosmetic bed', 'Adjustable height'),
		array('Profesionalni kozmetički krevet', 'Podesiva visina'),
		$fields['q']
	);

	return array(
		'status' => 200,
		'body' => json_encode(array(array(array($translated, $fields['q'], null, null)), null, 'en'))
	);
};

$translator = new WobSupplierFreeGoogleTranslate($cache_directory, $transport);
$result = $translator->translateProduct(
	'Professional cosmetic bed',
	'<p><strong>Adjustable height</strong></p>'
);

translationAssertSame('Profesionalni kozmetički krevet', $result['name'], 'The translated product name should be extracted from the response envelope.');
translationAssert(strpos($result['description'], '<strong>Podesiva visina</strong>') !== false, 'Translated description HTML should be preserved.');
translationAssertSame(1, $calls, 'The first translation should call the transport once.');

$cached = $translator->translateProduct(
	'Professional cosmetic bed',
	'<p><strong>Adjustable height</strong></p>'
);

translationAssertSame($result, $cached, 'A cached translation should match the original result.');
translationAssertSame(1, $calls, 'A cached translation must not call Google again.');

$bad_status = new WobSupplierFreeGoogleTranslate('', function () {
	return array('status' => 429, 'body' => 'rate limited');
});
translationAssertThrows(function () use ($bad_status) {
	$bad_status->translateProduct('Product', '<p>Description</p>');
}, 'HTTP failures must reject the translation.');

$bad_json = new WobSupplierFreeGoogleTranslate('', function () {
	return array('status' => 200, 'body' => '{broken');
});
translationAssertThrows(function () use ($bad_json) {
	$bad_json->translateProduct('Product', '<p>Description</p>');
}, 'Malformed Google responses must reject the translation.');

$structure_cache_directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wob-translation-structure-test-' . bin2hex(random_bytes(8));
$structure_calls = 0;
$bad_then_good_structure = new WobSupplierFreeGoogleTranslate($structure_cache_directory, function ($url, $fields) use (&$structure_calls) {
	$structure_calls++;
	$translated = $structure_calls === 1
		? '<div>Struktura bez obaveznih oznaka</div>'
		: str_replace(array('Product', 'Description'), array('Proizvod', 'Opis'), $fields['q']);

	return array(
		'status' => 200,
		'body' => json_encode(array(array(array($translated, $fields['q'], null, null)), null, 'en'))
	);
});

translationAssertThrows(function () use ($bad_then_good_structure) {
	$bad_then_good_structure->translateProduct('Product', '<p>Description</p>');
}, 'A structurally broken translation must be rejected.');

$retried_structure = $bad_then_good_structure->translateProduct('Product', '<p>Description</p>');
translationAssertSame('Proizvod', $retried_structure['name'], 'A broken translation must not poison the cache.');
translationAssertSame(2, $structure_calls, 'A structurally broken translation must be requested again.');

$empty_cache_directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wob-translation-empty-cache-test-' . bin2hex(random_bytes(8));
@mkdir($empty_cache_directory, 0755, true);
$empty_envelope = '<div data-wob-translation="name">Product</div><div data-wob-translation="description"><p>Description</p></div>';
$empty_cache_key = hash('sha256', WobSupplierFreeGoogleTranslate::CACHE_VERSION . "\n" . 'en' . "\n" . 'hr' . "\n" . $empty_envelope);
file_put_contents(
	$empty_cache_directory . DIRECTORY_SEPARATOR . $empty_cache_key . '.json',
	json_encode(array('translation' => '<div data-wob-translation="name"></div><div data-wob-translation="description"></div>'))
);
$empty_cache_calls = 0;
$empty_cache_translator = new WobSupplierFreeGoogleTranslate($empty_cache_directory, function ($url, $fields) use (&$empty_cache_calls) {
	$empty_cache_calls++;
	$translated = str_replace(array('Product', 'Description'), array('Proizvod', 'Opis'), $fields['q']);
	return array('status' => 200, 'body' => json_encode(array(array(array($translated, $fields['q'], null, null)), null, 'en')));
});
$recovered_empty_cache = $empty_cache_translator->translateProduct('Product', '<p>Description</p>');
translationAssertSame('Proizvod', $recovered_empty_cache['name'], 'An old empty cached translation must be replaced.');
translationAssertSame(1, $empty_cache_calls, 'An old empty cached translation must trigger a fresh request.');

if (is_dir($cache_directory)) {
	foreach (glob($cache_directory . DIRECTORY_SEPARATOR . '*.json') ?: array() as $file) {
		@unlink($file);
	}
	@rmdir($cache_directory);
}

if (is_dir($structure_cache_directory)) {
	foreach (glob($structure_cache_directory . DIRECTORY_SEPARATOR . '*.json') ?: array() as $file) {
		@unlink($file);
	}
	@rmdir($structure_cache_directory);
}

if (is_dir($empty_cache_directory)) {
	foreach (glob($empty_cache_directory . DIRECTORY_SEPARATOR . '*.json') ?: array() as $file) {
		@unlink($file);
	}
	@rmdir($empty_cache_directory);
}

echo "Free Google translation tests passed.\n";
