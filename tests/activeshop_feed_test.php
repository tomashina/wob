<?php

require_once dirname(__DIR__) . '/upload/system/library/wob_supplier/activeshop_feed.php';

function assertTrue($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function assertSameValue($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
		);
	}
}

function assertContainsText($needle, $haystack, $message) {
	assertTrue(strpos($haystack, $needle) !== false, $message . '\nMissing: ' . $needle . '\nValue: ' . $haystack);
}

function assertNotContainsText($needle, $haystack, $message) {
	assertTrue(strpos($haystack, $needle) === false, $message . '\nUnexpected: ' . $needle . '\nValue: ' . $haystack);
}

function assertThrowsException($callback, $message) {
	$thrown = false;

	try {
		$callback();
	} catch (Throwable $exception) {
		$thrown = true;
	}

	assertTrue($thrown, $message);
}

$feed = new WobSupplierActiveShopFeed();
$fixture = __DIR__ . '/fixtures/activeshop-feed.xml';

assertSameValue(
	'https://b2b.activeshop.com.pl/media/productsfeed/b2b-eng.xml',
	$feed->getFeedUrl(),
	'The ActiveShop feed URL must be fixed.'
);

$metadata = $feed->getCacheMetadata($fixture);
assertSameValue(2, $metadata['count'], 'Fixture item count should be detected with XMLReader.');
assertSameValue(hash_file('sha256', $fixture), $metadata['hash'], 'Metadata should contain the source SHA-256 hash.');
assertSameValue(filesize($fixture), $metadata['size'], 'Metadata should contain the source byte size.');

$items = iterator_to_array($feed->iterate($fixture), false);
assertSameValue(2, count($items), 'The fixture should yield two normalized products.');

$product = $items[0];
assertSameValue('148507', $product['sku'], 'SKU must remain a string.');
assertSameValue('5906717459146', $product['ean'], 'EAN must remain a string.');
assertSameValue(304.68, $product['feed_price'], 'Feed price should be normalized as a number.');
assertSameValue(850, $product['quantity'], 'Quantity should be normalized as an integer.');
assertSameValue(6.9, $product['weight'], 'Weight should be normalized in feed units.');
assertSameValue(
	array('Cosmetic devices', 'Professional devices', 'Equipment for the office'),
	$product['category_path'],
	'Category hierarchy should be split and trimmed.'
);
assertSameValue(2, count($product['images']), 'Images from non-allowlisted hosts must be omitted.');
assertSameValue(460.0, $product['dimensions']['length'], 'Dimensions should be normalized as numbers.');
assertSameValue('23%', $product['payload']['tax'], 'Unmapped supplier data should remain available in payload.');
assertSameValue('Activeshop Sp. z o.o', $product['payload']['gpsr']['manufacturer'], 'GPSR data should be retained.');
assertSameValue($metadata['hash'], $product['source_hash'], 'Each product should carry its source feed hash.');

assertSameValue(array(), $items[1]['category_path'], 'A missing supplier category should normalize to an empty path.');
assertSameValue(array(), $items[1]['images'], 'An empty image container should normalize to an empty list.');
assertSameValue(0, $items[1]['quantity'], 'Zero supplier stock must be preserved.');

assertSameValue(497.54, $feed->calculatePrice(304.68, 63.3), '304.68 plus 63.3% must equal 497.54.');
assertSameValue(304.68, $feed->calculatePrice('304.68', '0'), 'Numeric strings should be accepted by price calculation.');
assertSameValue('Injected name', $feed->sanitizePlainText('&lt;script&gt;bad()&lt;/script&gt;Injected <b>name</b>'), 'Plain-text feed fields must not retain markup or script content.');
assertThrowsException(function () use ($feed) {
	$feed->calculatePrice(100, -1);
}, 'Negative markup must be rejected.');

$dirty_html = '<div class="layout" onclick="bad()">'
	. '<script>alert("x")</script><style>body{display:none}</style>'
	. '<p style="color:red">Hello <strong onmouseover="bad()">world</strong>'
	. '<a href="javascript:alert(1)">bad link</a>'
	. '<a href="https://example.com/product" target="_blank">safe link</a>'
	. '<img src="https://b2b.activeshop.com.pl/media/catalog/product/safe.jpg" onerror="bad()">'
	. '<img src="https://example.invalid/evil.jpg">'
	. '<iframe src="https://www.youtube.com/embed/example"></iframe><!-- comment -->'
	. '</p></div>';
$clean_html = $feed->sanitizeDescription($dirty_html);

assertContainsText('<strong>world</strong>', $clean_html, 'Safe formatting should remain.');
assertContainsText('href="https://example.com/product"', $clean_html, 'Safe HTTPS links should remain.');
assertContainsText('rel="nofollow noopener noreferrer"', $clean_html, 'External links should receive safe rel attributes.');
assertContainsText('src="https://b2b.activeshop.com.pl/media/catalog/product/safe.jpg"', $clean_html, 'Allowlisted HTTPS images should remain.');
assertContainsText('loading="lazy"', $clean_html, 'Sanitized description images should be lazy-loaded.');
assertNotContainsText('alert("x")', $clean_html, 'Script content must be removed.');
assertNotContainsText('javascript:', $clean_html, 'Executable URLs must be removed.');
assertNotContainsText('onclick', $clean_html, 'Event handlers must be removed.');
assertNotContainsText('onmouseover', $clean_html, 'Nested event handlers must be removed.');
assertNotContainsText('onerror', $clean_html, 'Image event handlers must be removed.');
assertNotContainsText('style=', $clean_html, 'Inline CSS must be removed.');
assertNotContainsText('<iframe', $clean_html, 'Iframes must be removed.');
assertNotContainsText('example.invalid', $clean_html, 'Images from untrusted hosts must be removed.');
assertNotContainsText('comment', $clean_html, 'HTML comments must be removed.');

$invalid_file = tempnam(sys_get_temp_dir(), 'activeshop-invalid-');
if ($invalid_file === false) {
	throw new RuntimeException('Unable to create invalid XML test file.');
}

file_put_contents($invalid_file, '<?xml version="1.0"?><offers><item><sku>broken</sku></offers>');

try {
	assertThrowsException(function () use ($feed, $invalid_file) {
		$feed->getCacheMetadata($invalid_file);
	}, 'Malformed XML must be rejected before iteration.');

	assertThrowsException(function () use ($feed, $invalid_file) {
		iterator_to_array($feed->iterate($invalid_file), false);
	}, 'Malformed XML must never yield importable products.');
} finally {
	@unlink($invalid_file);
}

$doctype_file = tempnam(sys_get_temp_dir(), 'activeshop-doctype-');
if ($doctype_file === false) {
	throw new RuntimeException('Unable to create DOCTYPE XML test file.');
}

file_put_contents(
	$doctype_file,
	'<?xml version="1.0"?><!DOCTYPE offers [<!ENTITY supplier "expanded">]><offers><item><sku>&supplier;</sku></item></offers>'
);

try {
	assertThrowsException(function () use ($feed, $doctype_file) {
		$feed->getCacheMetadata($doctype_file);
	}, 'Feeds containing a DOCTYPE must be rejected without entity expansion.');
} finally {
	@unlink($doctype_file);
}

echo "ActiveShop feed tests passed.\n";
