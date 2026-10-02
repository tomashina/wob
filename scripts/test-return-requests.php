<?php

require_once dirname(__DIR__) . '/upload/system/library/return_request.php';

function assertReturnRequest($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: " . $message . "\n");
		exit(1);
	}
}

$service = new return_request();
$items = $service->normaliseItems(array(
	array('name' => '  <b>Krema</b> ', 'code' => ' WOB-01 ', 'quantity' => '2', 'price' => '12,50'),
	array('name' => '', 'code' => '', 'quantity' => '', 'price' => '')
));

assertReturnRequest(count($items) === 1, 'Completely empty product rows are ignored.');
assertReturnRequest($items[0]['name'] === 'Krema', 'Product markup is removed.');
assertReturnRequest($items[0]['code'] === 'WOB-01', 'Product codes are trimmed.');
assertReturnRequest($items[0]['quantity'] === 2, 'Quantities are normalised to integers.');
assertReturnRequest($items[0]['price'] === '12.50', 'Decimal commas are normalised.');
assertReturnRequest($service->validateItems($items), 'A valid item row passes validation.');
assertReturnRequest(!$service->validateItems(array(array('name' => '', 'code' => '', 'quantity' => 1, 'price' => ''))), 'An item needs a name or code.');
assertReturnRequest(!$service->validateItems(array(array('name' => 'Krema', 'code' => '', 'quantity' => 0, 'price' => ''))), 'An item needs a positive quantity.');
assertReturnRequest(!$service->validateItems(array(array('name' => 'Krema', 'code' => '', 'quantity' => 10000, 'price' => ''))), 'Item quantities are bounded.');
assertReturnRequest($service->validateItems(array_fill(0, 100, $items[0])), 'One request accepts up to 100 item rows.');
assertReturnRequest(!$service->validateItems(array_fill(0, 101, $items[0])), 'One request rejects more than 100 item rows.');
assertReturnRequest($service->validateComment(str_repeat('a', 5000)), 'A 5000-character note is accepted.');
assertReturnRequest(!$service->validateComment(str_repeat('a', 5001)), 'An oversized note is rejected.');
assertReturnRequest($service->normaliseType('unexpected') === 'withdrawal', 'Unknown request types safely default to withdrawal.');

$encoded = $service->encodeItems($items);
assertReturnRequest($service->decodeItems($encoded) === $items, 'Structured return items survive JSON storage.');
assertReturnRequest($service->decodeItems('{bad json', $items) === $items, 'Legacy fallback items are used for invalid JSON.');
assertReturnRequest($service->decodeItems('[]', $items) === $items, 'Legacy fallback items are used for an empty JSON list.');
assertReturnRequest($service->maskIban('HR12 3456 7890 1234 5678 9') === 'HR12*************6789', 'IBAN is masked in e-mails.');

$labels = array(
	'return_id' => 'Broj zahtjeva', 'request_type' => 'Vrsta', 'type_withdrawal' => 'Raskid',
	'type_return' => 'Povrat', 'submitted_at' => 'Vrijeme', 'invoice_number' => 'Račun',
	'invoice_date' => 'Datum', 'customer' => 'Kupac', 'email' => 'E-mail', 'telephone' => 'Telefon',
	'items' => 'Artikli', 'reason' => 'Razlog', 'comment' => 'Napomena', 'refund_iban' => 'IBAN'
);
$submission = array(
	'request_type' => 'withdrawal', 'submitted_at' => '2026-10-02 12:00:00', 'invoice_number' => 'IR-42',
	'invoice_date' => '2026-10-01', 'firstname' => 'Ana', 'lastname' => 'Anić', 'email' => 'ana@example.test',
	'telephone' => '+385 91 000 0000', 'return_products' => $items, 'reason' => '', 'comment' => 'Molim potvrdu.',
	'refund_iban' => 'HR1234567890123456789'
);
$mail = $service->buildMailText($labels, 42, $submission, 'Zaprimljeno.', 'Hvala.');
assertReturnRequest(strpos($mail, 'IR-42') !== false && strpos($mail, 'Krema / WOB-01 × 2') !== false, 'Confirmation mail contains invoice and item details.');
assertReturnRequest(strpos($mail, 'HR1234567890123456789') === false && strpos($mail, 'HR12') !== false, 'Confirmation mail never exposes the full IBAN.');

$csv = $service->buildCsv(array(
	array('42', '=HYPERLINK("bad")', 'normal'),
	array('43', "\t=HYPERLINK(\"bad\")", "\r+SUM(1,1)"),
	array('44', '  @SUM(1,1)', 'normal')
), array('ID', 'Name', 'Value'));
assertReturnRequest(strpos($csv, "'=HYPERLINK") !== false, 'CSV formula injection is neutralised.');
assertReturnRequest(strpos($csv, "'\t=HYPERLINK") !== false, 'CSV tab-prefixed formula injection is neutralised.');
assertReturnRequest(strpos($csv, "'\r+SUM") !== false, 'CSV carriage-return-prefixed formula injection is neutralised.');
assertReturnRequest(strpos($csv, "'  @SUM") !== false, 'CSV whitespace-prefixed formula injection is neutralised.');

$controller = file_get_contents(dirname(__DIR__) . '/upload/catalog/controller/account/return.php');
$catalog_model = file_get_contents(dirname(__DIR__) . '/upload/catalog/model/account/return.php');
$admin_controller = file_get_contents(dirname(__DIR__) . '/upload/admin/controller/sale/return.php');
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261002_return_requests.sql');
$croatian_language = file_get_contents(dirname(__DIR__) . '/upload/catalog/language/hr-hr/account/return.php');
$english_language = file_get_contents(dirname(__DIR__) . '/upload/catalog/language/en-gb/account/return.php');
$admin_english_language = file_get_contents(dirname(__DIR__) . '/upload/admin/language/en-gb/sale/return.php');
$admin_croatian_language = file_get_contents(dirname(__DIR__) . '/upload/admin/language/hr-hr/sale/return.php');
$admin_list_template = file_get_contents(dirname(__DIR__) . '/upload/admin/view/template/sale/return_list.twig');
$admin_form_template = file_get_contents(dirname(__DIR__) . '/upload/admin/view/template/sale/return_form.twig');
$default_form_template = file_get_contents(dirname(__DIR__) . '/upload/catalog/view/theme/default/template/account/return_form.twig');
$default_info_template = file_get_contents(dirname(__DIR__) . '/upload/catalog/view/theme/default/template/account/return_info.twig');
assertReturnRequest(strpos($controller, 'hash_equals($session_token, $post_token)') !== false, 'Public form validates a session CSRF token.');
assertReturnRequest(strpos($controller, 'getRecentSubmissionCount($submitted_ip, $post[\'email\'], 60) >= 5') !== false, 'Public form rate-limits successful submissions by IP or e-mail.');
assertReturnRequest(strpos($catalog_model, 'DATE_SUB(NOW(), INTERVAL " . $minutes . " MINUTE)') !== false, 'The return model enforces the bounded recent-submission window.');
assertReturnRequest(substr_count($controller, "\$this->error['email']") === 1, 'The public controller assigns the e-mail validation error once.');
assertReturnRequest(substr_count($controller, '$this->sendMail(') >= 2, 'Submission sends customer and administrator messages.');
assertReturnRequest(strpos($controller, "'information_id=5'") !== false, 'The public form links to the WOB general terms by default.');
assertReturnRequest(strpos($controller, "'information_id=17'") === false, 'The Dryzen policy page ID is not retained in WOB.');
assertReturnRequest(strpos($admin_controller, 'public function export()') !== false, 'Administration provides CSV export.');
assertReturnRequest(stripos($migration, 'return_items` mediumtext') !== false, 'Structured return items use MEDIUMTEXT storage.');
$date_column_alter = strpos($migration, 'ALTER TABLE `oc_return` MODIFY `date_ordered` date NULL DEFAULT NULL');
$zero_date_cleanup = strpos($migration, "UPDATE `oc_return` SET `date_ordered` = NULL WHERE `date_ordered` = '0000-00-00'");
assertReturnRequest($date_column_alter !== false && $zero_date_cleanup !== false && $date_column_alter < $zero_date_cleanup, 'The legacy date column becomes nullable before zero dates are cleaned.');
assertReturnRequest(strpos($croatian_language, 'Kopiju predanih podataka poslali smo Vam e-poštom') === false, 'Success copy does not promise successful e-mail delivery.');
assertReturnRequest(strpos($croatian_language, 'odmah ćete primiti potvrdu') === false && strpos($english_language, 'immediately send an e-mail confirmation') === false, 'Form descriptions do not promise successful e-mail delivery.');
assertReturnRequest(strpos($admin_english_language, 'text_history_add') !== false && strpos($admin_croatian_language, 'text_history_add') !== false, 'Both admin languages define the add-history legend.');
assertReturnRequest(strpos($admin_list_template, 'formaction="{{ export }}"') !== false && strpos($admin_list_template, ".attr('action'") === false, 'CSV export does not mutate the delete form action.');
assertReturnRequest(strpos($admin_form_template, '<option value=""{% if not return_reason_id %} selected="selected"{% endif %}>{{ text_none }}</option>') !== false, 'Withdrawal editing keeps an explicitly empty return reason.');
assertReturnRequest(substr_count($admin_controller, "\$this->request->post['return_reason_id'] = 0;") === 2, 'Admin add and edit preserve an empty reason for withdrawals.');
assertReturnRequest(substr_count($default_form_template, 'href="{{ back }}"') === 2 && strpos($default_form_template, 'btn-contrast') === false, 'The default-theme form preserves WOB navigation and button classes.');
assertReturnRequest(strpos($default_info_template, 'class="table-responsive"') !== false && strpos($default_info_template, 'href="{{ continue }}"') !== false, 'The default-theme detail view preserves responsive tables and navigation.');

echo "Return request tests passed.\n";
