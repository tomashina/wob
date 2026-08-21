<?php

/**
 * Small, keyless Google Translate web-client adapter used by supplier imports.
 *
 * The endpoint is not a paid Cloud Translation API and can rate-limit or change
 * without notice. Failures are therefore explicit and successful translations
 * are cached locally so retries do not repeatedly call the service.
 */
class WobSupplierFreeGoogleTranslate {
	const ENDPOINT = 'https://translate.googleapis.com/translate_a/single';
	const CACHE_VERSION = 1;
	const CONNECT_TIMEOUT = 5;
	const REQUEST_TIMEOUT = 12;
	const MAX_INPUT_BYTES = 100000;
	const MAX_RESPONSE_BYTES = 2097152;

	private $cache_directory;
	private $transport;

	/**
	 * @param string        $cache_directory
	 * @param callable|null $transport Test transport. Receives ($url, $fields).
	 */
	public function __construct($cache_directory, $transport = null) {
		$this->cache_directory = rtrim((string)$cache_directory, '/\\');
		$this->transport = is_callable($transport) ? $transport : null;
	}

	/**
	 * Translate a product name and sanitized HTML description in one request.
	 *
	 * @return array{name:string,description:string}
	 */
	public function translateProduct($name, $description, $source = 'en', $target = 'hr') {
		$name = trim((string)$name);
		$description = trim((string)$description);

		if ($name === '') {
			throw new InvalidArgumentException('A product name is required for translation.');
		}

		$envelope = '<div data-wob-translation="name">'
			. htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
			. '</div><div data-wob-translation="description">'
			. $description
			. '</div>';

		$cache_key = $this->buildCacheKey($envelope, $source, $target);
		$translated = $this->readCache($cache_key);

		if ($translated !== null) {
			try {
				$parts = $this->extractProductParts($translated);
				$this->assertProductParts($parts, $description);
			} catch (Throwable $exception) {
				$this->deleteCache($cache_key);
				$translated = null;
			}
		}

		if ($translated === null) {
			$translated = $this->translateHtml($envelope, $source, $target);
			$parts = $this->extractProductParts($translated);
		}

		$this->assertProductParts($parts, $description);

		$this->writeCache($cache_key, $translated);

		return $parts;
	}

	private function assertProductParts(array $parts, $source_description) {
		if (!isset($parts['name']) || trim((string)$parts['name']) === '') {
			throw new RuntimeException('Google returned an empty translated product name.');
		}

		if (trim((string)$source_description) !== '' && (!isset($parts['description']) || trim((string)$parts['description']) === '')) {
			throw new RuntimeException('Google returned an empty translated product description.');
		}
	}

	/**
	 * @return string
	 */
	public function translateHtml($html, $source = 'en', $target = 'hr') {
		$html = trim((string)$html);
		$source = strtolower(trim((string)$source));
		$target = strtolower(trim((string)$target));

		if ($html === '') {
			return '';
		}

		if (!preg_match('/^[a-z]{2,3}$/', $source) || !preg_match('/^[a-z]{2,3}$/', $target)) {
			throw new InvalidArgumentException('Translation languages must use short ISO codes.');
		}

		if (strlen($html) > self::MAX_INPUT_BYTES) {
			throw new RuntimeException('Product text is too large for automatic translation.');
		}

		$url = self::ENDPOINT . '?client=gtx&sl=' . rawurlencode($source) . '&tl=' . rawurlencode($target) . '&dt=t';
		$fields = array('q' => $html);
		$response = $this->transport
			? call_user_func($this->transport, $url, $fields)
			: $this->request($url, $fields);

		if (!is_array($response) || !isset($response['status']) || !array_key_exists('body', $response)) {
			throw new RuntimeException('Google translation transport returned an invalid response.');
		}

		$status = (int)$response['status'];
		$body = (string)$response['body'];

		if ($status !== 200) {
			throw new RuntimeException('Free Google Translate returned HTTP ' . $status . '.');
		}

		if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
			throw new RuntimeException('Free Google Translate returned an invalid response size.');
		}

		$decoded = json_decode($body, true);

		if (!is_array($decoded) || empty($decoded[0]) || !is_array($decoded[0])) {
			throw new RuntimeException('Free Google Translate returned malformed JSON.');
		}

		$translation = '';
		foreach ($decoded[0] as $segment) {
			if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
				$translation .= $segment[0];
			}
		}

		$translation = trim($translation);

		if ($translation === '') {
			throw new RuntimeException('Free Google Translate returned an empty translation.');
		}

		return $translation;
	}

	private function request($url, array $fields) {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('The cURL extension is required for automatic translation.');
		}

		$curl = curl_init($url);
		$options = array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_ENCODING => '',
				CURLOPT_USERAGENT => 'WOB-ActiveShop-Importer/1.0',
				CURLOPT_HTTPHEADER => array(
					'Accept: application/json',
					'Content-Type: application/x-www-form-urlencoded'
				)
		);

		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
		}

		curl_setopt_array($curl, $options);
		$body = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);
		curl_close($curl);

		if ($body === false) {
			throw new RuntimeException($error !== ''
				? 'Free Google Translate request failed: ' . $error
				: 'Free Google Translate is temporarily unavailable.');
		}

		return array('status' => $status, 'body' => $body);
	}

	private function extractProductParts($html) {
		if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
			throw new RuntimeException('The DOM extension is required to read translated product content.');
		}

		$document = new DOMDocument('1.0', 'UTF-8');
		$previous_errors = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$loaded = $document->loadHTML(
			'<?xml encoding="UTF-8"><div id="wob-google-translation-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);

		if (!$loaded) {
			throw new RuntimeException('Unable to parse translated product content.');
		}

		$xpath = new DOMXPath($document);
		$name_nodes = $xpath->query('//*[@data-wob-translation="name"]');
		$description_nodes = $xpath->query('//*[@data-wob-translation="description"]');

		if (!$name_nodes || $name_nodes->length !== 1 || !$description_nodes || $description_nodes->length !== 1) {
			throw new RuntimeException('Google changed the translated product structure.');
		}

		return array(
			'name' => trim(html_entity_decode($name_nodes->item(0)->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
			'description' => trim($this->innerHtml($description_nodes->item(0)))
		);
	}

	private function innerHtml(DOMNode $node) {
		$html = '';
		foreach ($node->childNodes as $child) {
			$html .= $node->ownerDocument->saveHTML($child);
		}
		return $html;
	}

	private function readCache($cache_key) {
		$file = $this->cacheFile($cache_key);

		if ($file === '' || !is_file($file) || !is_readable($file)) {
			return null;
		}

		$size = filesize($file);
		if ($size === false || $size < 1 || $size > self::MAX_RESPONSE_BYTES) {
			return null;
		}

		$data = json_decode((string)file_get_contents($file), true);
		return is_array($data) && isset($data['translation']) && is_string($data['translation']) && $data['translation'] !== ''
			? $data['translation']
			: null;
	}

	private function deleteCache($cache_key) {
		$file = $this->cacheFile($cache_key);
		if ($file !== '' && is_file($file)) {
			@unlink($file);
		}
	}

	private function buildCacheKey($html, $source, $target) {
		return hash('sha256', self::CACHE_VERSION . "\n" . $source . "\n" . $target . "\n" . $html);
	}

	private function writeCache($cache_key, $translation) {
		if ($this->cache_directory === '') {
			return;
		}

		if (!is_dir($this->cache_directory) && !@mkdir($this->cache_directory, 0755, true) && !is_dir($this->cache_directory)) {
			return;
		}

		if (!is_writable($this->cache_directory)) {
			return;
		}

		$file = $this->cacheFile($cache_key);
		$temp = tempnam($this->cache_directory, '.translation-');
		if ($file === '' || $temp === false) {
			return;
		}

		$payload = json_encode(array(
			'version' => self::CACHE_VERSION,
			'translation' => $translation,
			'created_at' => gmdate('c')
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($payload === false || file_put_contents($temp, $payload, LOCK_EX) === false || !@rename($temp, $file)) {
			@unlink($temp);
		}
	}

	private function cacheFile($cache_key) {
		return $this->cache_directory === '' ? '' : $this->cache_directory . DIRECTORY_SEPARATOR . $cache_key . '.json';
	}
}
