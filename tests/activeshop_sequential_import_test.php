<?php

require dirname(__DIR__) . '/upload/admin/config.php';

function sequentialImportAssert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$controller = file_get_contents(DIR_APPLICATION . 'controller/extension/module/activeshop_importer.php');
$template = file_get_contents(DIR_TEMPLATE . 'extension/module/activeshop_importer.twig');
$croatian = file_get_contents(DIR_LANGUAGE . 'hr-hr/extension/module/activeshop_importer.php');
$english = file_get_contents(DIR_LANGUAGE . 'en-gb/extension/module/activeshop_importer.php');

sequentialImportAssert(strpos($controller, '$wants_json = $this->wantsJsonResponse();') !== false, 'Import endpoint must explicitly support JSON batch requests.');
sequentialImportAssert(strpos($controller, "\$data['import_url'] = html_entity_decode(") !== false, 'The JavaScript import URL must contain a real user_token separator, not a literal &amp;.');
sequentialImportAssert(strpos($controller, '$wants_json && count($selected) !== 1') !== false, 'Each AJAX request must accept exactly one selected product.');
sequentialImportAssert(strpos($controller, "'retryable' => true") !== false && strpos($controller, "'error_code' => 'busy'") !== false, 'A busy operation lock must return a bounded-retry signal.');
sequentialImportAssert(strpos($controller, 'finally {') !== false && strpos($controller, '$this->releaseOperationLock($operation_lock);') !== false, 'Every item request must release the operation lock in finally.');
sequentialImportAssert(strpos($controller, "'selected' => count(\$selected)") !== false, 'Each per-item audit run must record its exact selection count.');

sequentialImportAssert(strpos($template, 'id="activeshop-import-progress"') !== false, 'Importer must render visible progress UI.');
sequentialImportAssert(strpos($template, "ajax: '1'") !== false, 'Sequential requests must opt into the JSON endpoint.');
sequentialImportAssert(strpos($template, "'selected[]': ids[index]") !== false, 'Each request must submit exactly the current item ID.');
sequentialImportAssert(strpos($template, 'runSequentialImport(ids, index + 1') !== false, 'Runner must advance one item only after the previous response.');
sequentialImportAssert(strpos($template, "dataType: 'json'") !== false, 'Runner must require a JSON response.');
sequentialImportAssert(strpos($template, 'busyAttempt >= 100') !== false, 'Busy retries must be bounded while allowing a long-running refresh to finish.');
sequentialImportAssert(strpos($template, 'Do not retry an ambiguous request') !== false, 'Network failures must not blindly repeat a possibly completed import.');
sequentialImportAssert(strpos($template, 'stopSequentialImport(index, ids.length, textImportNetworkError)') !== false, 'An ambiguous transport failure must stop the queue instead of marking all remaining products failed.');
sequentialImportAssert(strpos($template, 'if (!response || !response.counts)') !== false, 'The queue must advance only after a terminal per-item response with audit counts.');
sequentialImportAssert(strpos($template, 'timeout: 0') !== false, 'The browser must not race the server with an equal or shorter request timeout.');
sequentialImportAssert(strpos($template, 'maxTranslatedImportItems') === false, 'The obsolete ten-new-product browser cap must be removed.');
sequentialImportAssert(strpos($template, "document.getElementById('activeshop-import-form').submit()") === false, 'The old long-running form submit must be removed.');
sequentialImportAssert(strpos($template, ".js-import-progress-text').text(") !== false, 'Progress and server-derived messages must be inserted as text, not HTML.');

foreach (array($croatian, $english) as $language) {
	sequentialImportAssert(strpos($language, 'text_import_processing') !== false, 'Both languages must define the per-item progress message.');
	sequentialImportAssert(strpos($language, 'error_ajax_single_item') !== false, 'Both languages must define the JSON single-item guard error.');
	sequentialImportAssert(strpos($language, 'error_import') !== false, 'Both languages must define the import exception message.');
}

echo "ActiveShop sequential import tests passed; selected products are processed one request at a time.\n";
