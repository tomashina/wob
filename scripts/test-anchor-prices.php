<?php

require_once dirname(__DIR__) . '/upload/system/library/anchor_price/csv_importer.php';

function anchorAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function anchorTempCsv($contents) {
	$filename = tempnam(sys_get_temp_dir(), 'wob-anchor-');
	file_put_contents($filename, $contents);

	return $filename;
}

function anchorExpectError($contents, $error_key) {
	$filename = anchorTempCsv($contents);

	try {
		$importer = new WobAnchorPriceCsvImporter('2026-10-02');
		$importer->parse($filename);
		throw new RuntimeException('Expected CSV error: ' . $error_key);
	} catch (WobAnchorPriceCsvException $exception) {
		anchorAssert($exception->getErrorKey() === $error_key, 'Unexpected error key: ' . $exception->getErrorKey());
	} finally {
		unlink($filename);
	}
}

$filename = anchorTempCsv("model;anchor_price;anchor_price_date\nWOB-01;19,9900;2026-09-30\nWOB-02;0,0000;\nWOB-03;12345678901.2345;2026-10-01\n");
$importer = new WobAnchorPriceCsvImporter('2026-10-02');
$rows = $importer->parse($filename);
unlink($filename);

anchorAssert(count($rows) === 3, 'Expected three parsed rows.');
anchorAssert($rows[0]['identifiers']['model'] === 'WOB-01', 'Model was not parsed.');
anchorAssert($rows[0]['anchor_price'] === '19.99', 'Decimal comma was not canonicalised exactly.');
anchorAssert($rows[0]['anchor_price_date'] === '2026-09-30', 'Reference date was not parsed.');
anchorAssert($rows[1]['anchor_price'] === '0' && $rows[1]['anchor_price_date'] === '', 'Clear row was not parsed.');
anchorAssert($rows[2]['anchor_price'] === '12345678901.2345', 'Large decimal value lost precision.');

$filename = anchorTempCsv("\xEF\xBB\xBFproduct_id,anchor_price,reference_date\n12,25.50,2026-10-01\n");
$rows = $importer->parse($filename);
unlink($filename);
anchorAssert($rows[0]['identifiers']['product_id'] === '12', 'BOM/product ID handling failed.');

anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;12.00;\n", 'missing_date');
anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;12.00;2026-10-03\n", 'future_date');
anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;0;2026-10-01\n", 'unexpected_date');
anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;-1;2026-10-01\n", 'invalid_price');
anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;12.00;2026-02-30\n", 'invalid_date');
anchorExpectError("model;anchor_price;sidrena_cijena;anchor_price_date\nWOB-01;12.00;12.00;2026-10-01\n", 'duplicate_column');
anchorExpectError("anchor_price;anchor_price_date\n12.00;2026-10-01\n", 'missing_identifier_column');
anchorExpectError("model;anchor_price;anchor_price_date\n;12.00;2026-10-01\n", 'missing_identifier');
anchorExpectError("model;anchor_price;anchor_price_date\nWOB-01;12.12345;2026-10-01\n", 'invalid_price');

$admin_model = file_get_contents(dirname(__DIR__) . '/upload/admin/model/catalog/product.php');
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261002_anchor_prices.sql');
anchorAssert(strpos($admin_model, 'SET `anchor_price` = `price`') === false, 'Runtime schema fallback must not invent historical anchor prices.');
anchorAssert(strpos($migration, 'SET\n  `p`.`anchor_price` = `p`.`price`') === false, 'Migration must not label the current price as a historical price.');
anchorAssert(strpos($migration, 'Povijesne vrijednosti namjerno se ne izvode') !== false, 'Migration documents the required verified-data import.');

echo "Anchor price CSV tests passed.\n";
