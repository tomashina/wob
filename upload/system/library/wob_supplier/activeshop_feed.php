<?php

/**
 * ActiveShop feed reader and media helper.
 *
 * This class is deliberately independent from OpenCart's registry so the feed
 * can be validated before any product or database work begins.
 */
class WobSupplierActiveShopFeed {
	const FEED_URL = 'https://b2b.activeshop.com.pl/media/productsfeed/b2b-eng.xml';

	const MAX_FEED_BYTES = 104857600; // 100 MiB
	const MAX_IMAGE_BYTES = 15728640; // 15 MiB
	const MAX_REDIRECTS = 3;
	const CONNECT_TIMEOUT = 10;
	const FEED_TIMEOUT = 120;
	const IMAGE_TIMEOUT = 30;

	private $image_hosts = array(
		'b2b.activeshop.com.pl',
		'activeshop.com.pl'
	);

	/**
	 * Return the only feed URL this helper is allowed to fetch.
	 *
	 * @return string
	 */
	public function getFeedUrl() {
		return self::FEED_URL;
	}

	/**
	 * Download, validate and atomically replace a local feed cache.
	 *
	 * @param string $cache_file
	 * @return array
	 */
	public function refreshCache($cache_file) {
		$this->assertLocalPath($cache_file, 'Feed cache');

		$directory = dirname($cache_file);

		if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
			throw new RuntimeException('Unable to create feed cache directory: ' . $directory);
		}

		if (!is_writable($directory)) {
			throw new RuntimeException('Feed cache directory is not writable: ' . $directory);
		}

		$temp_file = tempnam($directory, '.activeshop-feed-');

		if ($temp_file === false) {
			throw new RuntimeException('Unable to create a temporary feed cache file.');
		}

		try {
			$this->downloadToFile(self::FEED_URL, $temp_file, self::MAX_FEED_BYTES, self::FEED_TIMEOUT, 'feed');
			$metadata = $this->inspectCache($temp_file);

			// A successful but empty response must never replace the last good feed.
			if ($metadata['count'] < 1) {
				throw new RuntimeException('ActiveShop feed contains no products.');
			}

			if (!@rename($temp_file, $cache_file)) {
				throw new RuntimeException('Unable to atomically replace the ActiveShop feed cache.');
			}

			clearstatcache(true, $cache_file);

			return $this->getCacheMetadata($cache_file);
		} catch (Throwable $exception) {
			if (is_file($temp_file)) {
				@unlink($temp_file);
			}

			throw $exception;
		}
	}

	/**
	 * Validate a cached feed and return stable metadata.
	 *
	 * @param string $cache_file
	 * @return array
	 */
	public function getCacheMetadata($cache_file) {
		$this->assertLocalPath($cache_file, 'Feed cache');

		return $this->inspectCache($cache_file);
	}

	/**
	 * Stream normalized products from a previously validated local cache.
	 *
	 * @param string $cache_file
	 * @return Generator
	 */
	public function iterate($cache_file) {
		$metadata = $this->getCacheMetadata($cache_file);
		$source_hash = $metadata['hash'];
		$expected_count = $metadata['count'];
		$yielded_count = 0;
		$reader = $this->openXmlReader($cache_file);
		$previous_errors = libxml_use_internal_errors(true);

		libxml_clear_errors();

		try {
			while ($reader->read()) {
				if ($reader->nodeType === XMLReader::DOC_TYPE) {
					throw new RuntimeException('DOCTYPE declarations are not allowed in the ActiveShop feed.');
				}

				if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'item' || $reader->depth !== 1) {
					continue;
				}

				$item_xml = $reader->readOuterXml();

				if ($item_xml === '') {
					throw new RuntimeException('Unable to read an ActiveShop item from the cached feed.');
				}

				$item = $this->normalizeItem($item_xml);
				$item['source_hash'] = $source_hash;
				$yielded_count++;

				yield $item;
			}

			$this->throwOnLibxmlErrors('Invalid ActiveShop feed XML.');

			if ($yielded_count !== $expected_count) {
				throw new RuntimeException('ActiveShop feed changed while it was being read.');
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous_errors);
		}
	}

	/**
	 * Calculate a net selling price from feed price and markup percentage.
	 *
	 * @param float|string $feed_price
	 * @param float|string $markup
	 * @return float
	 */
	public function calculatePrice($feed_price, $markup) {
		if (!is_numeric($feed_price) || !is_numeric($markup)) {
			throw new InvalidArgumentException('Feed price and markup must be numeric.');
		}

		$feed_price = (float)$feed_price;
		$markup = (float)$markup;

		if (!is_finite($feed_price) || !is_finite($markup) || $feed_price < 0 || $markup < 0) {
			throw new InvalidArgumentException('Feed price and markup must be finite, non-negative numbers.');
		}

		return round($feed_price * (1 + ($markup / 100)), 2, PHP_ROUND_HALF_UP);
	}

	/**
	 * Convert supplier text fields that must never contain markup to plain text.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function sanitizePlainText($value) {
		$value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$without_active_content = preg_replace('#<(script|style|iframe|object|embed|svg|math|template)\b[^>]*>.*?</\1\s*>#isu', ' ', $value);
		if ($without_active_content === null) {
			return '';
		}
		$value = preg_replace('/<!--.*?-->/su', ' ', $without_active_content);
		if ($value === null) {
			return '';
		}
		$value = strip_tags($value);
		$value = str_replace(array('<', '>'), '', $value);
		$value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
		if ($value === null) {
			return '';
		}
		$value = preg_replace('/\s+/u', ' ', trim($value));
		return $value === null ? '' : $value;
	}

	/**
	 * Remove executable/unsafe markup while preserving useful product content.
	 *
	 * @param string $html
	 * @return string
	 */
	public function sanitizeDescription($html) {
		$html = (string)$html;

		if (trim($html) === '') {
			return '';
		}

		if (!class_exists('DOMDocument')) {
			throw new RuntimeException('The DOM extension is required to sanitize product descriptions.');
		}

		$document = new DOMDocument('1.0', 'UTF-8');
		$previous_errors = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$wrapped = '<?xml encoding="UTF-8"><div id="wob-activeshop-description">' . $html . '</div>';
		$loaded = $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);

		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);

		if (!$loaded) {
			throw new RuntimeException('Unable to sanitize the ActiveShop product description.');
		}

		$root = $document->getElementById('wob-activeshop-description');

		if (!$root) {
			throw new RuntimeException('Unable to locate sanitized ActiveShop product content.');
		}

		$allowed_tags = array(
			'a', 'b', 'blockquote', 'br', 'div', 'em', 'h1', 'h2', 'h3', 'h4',
			'i', 'img', 'li', 'ol', 'p', 'span', 'strong', 'sub', 'sup', 'table',
			'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul'
		);
		$remove_with_content = array(
			'base', 'button', 'embed', 'form', 'iframe', 'input', 'link', 'math',
			'meta', 'object', 'option', 'script', 'select', 'style', 'svg', 'template',
			'textarea'
		);

		$elements = array();
		foreach ($root->getElementsByTagName('*') as $element) {
			$elements[] = $element;
		}

		for ($index = count($elements) - 1; $index >= 0; $index--) {
			$element = $elements[$index];

			if (!$element->parentNode) {
				continue;
			}

			$tag = strtolower($element->nodeName);

			if (in_array($tag, $remove_with_content, true)) {
				$element->parentNode->removeChild($element);
				continue;
			}

			if (!in_array($tag, $allowed_tags, true)) {
				$this->unwrapElement($element);
				continue;
			}

			if (!$this->sanitizeElementAttributes($element, $tag)) {
				if ($element->parentNode) {
					$element->parentNode->removeChild($element);
				}
			}
		}

		$comments = array();
		$this->collectComments($root, $comments);
		foreach ($comments as $comment) {
			if ($comment->parentNode) {
				$comment->parentNode->removeChild($comment);
			}
		}

		$output = '';
		foreach ($root->childNodes as $child) {
			$output .= $document->saveHTML($child);
		}

		return trim($output);
	}

	/**
	 * Download and validate product gallery images.
	 *
	 * @param array $urls
	 * @param string $sku
	 * @param string $image_root Absolute OpenCart image directory
	 * @return array Relative OpenCart image paths
	 */
	public function downloadImages(array $urls, $sku, $image_root) {
		$this->assertLocalPath($image_root, 'Image root');

		$safe_sku = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)$sku));
		$safe_sku = trim($safe_sku, '._-');

		if ($safe_sku === '') {
			throw new InvalidArgumentException('A valid SKU is required to download ActiveShop images.');
		}

		if (!is_dir($image_root) && !@mkdir($image_root, 0755, true) && !is_dir($image_root)) {
			throw new RuntimeException('Unable to create the OpenCart image root: ' . $image_root);
		}

		$relative_directory = 'catalog/activeshop/' . $safe_sku;
		$target_directory = rtrim($image_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_directory);

		if (!is_dir($target_directory) && !@mkdir($target_directory, 0755, true) && !is_dir($target_directory)) {
			throw new RuntimeException('Unable to create the ActiveShop image directory.');
		}

		$paths = array();
		$seen_urls = array();

		foreach ($urls as $url) {
			$url = trim((string)$url);

			if ($url === '' || isset($seen_urls[$url])) {
				continue;
			}

			$seen_urls[$url] = true;
			$this->assertAllowedImageUrl($url);

			$temp_file = tempnam($target_directory, '.activeshop-image-');
			if ($temp_file === false) {
				throw new RuntimeException('Unable to create a temporary ActiveShop image file.');
			}

			try {
				$this->downloadToFile($url, $temp_file, self::MAX_IMAGE_BYTES, self::IMAGE_TIMEOUT, 'image');
				$image = $this->validateImageFile($temp_file);
				$filename = $this->buildImageFilename($url, $image['extension']);
				$target_file = $target_directory . DIRECTORY_SEPARATOR . $filename;

				if (!@rename($temp_file, $target_file)) {
					throw new RuntimeException('Unable to atomically save an ActiveShop image.');
				}

				$paths[] = $relative_directory . '/' . $filename;
			} catch (Throwable $exception) {
				if (is_file($temp_file)) {
					@unlink($temp_file);
				}

				throw $exception;
			}
		}

		return $paths;
	}

	private function inspectCache($cache_file) {
		if (!is_file($cache_file) || !is_readable($cache_file)) {
			throw new RuntimeException('ActiveShop feed cache is missing or unreadable: ' . $cache_file);
		}

		$size = filesize($cache_file);

		if ($size === false || $size < 1) {
			throw new RuntimeException('ActiveShop feed cache is empty.');
		}

		if ($size > self::MAX_FEED_BYTES) {
			throw new RuntimeException('ActiveShop feed cache exceeds the 100 MiB limit.');
		}

		$hash = hash_file('sha256', $cache_file);

		if ($hash === false) {
			throw new RuntimeException('Unable to hash the ActiveShop feed cache.');
		}

		$count = $this->countFeedItems($cache_file);
		$modified = filemtime($cache_file);

		return array(
			'source_url' => self::FEED_URL,
			'hash' => $hash,
			'count' => $count,
			'size' => $size,
			'modified_at' => $modified === false ? null : date('c', $modified)
		);
	}

	private function countFeedItems($cache_file) {
		$reader = $this->openXmlReader($cache_file);
		$previous_errors = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$count = 0;
		$root_seen = false;

		try {
			while ($reader->read()) {
				if ($reader->nodeType === XMLReader::DOC_TYPE) {
					throw new RuntimeException('DOCTYPE declarations are not allowed in the ActiveShop feed.');
				}

				if (!$root_seen && $reader->nodeType === XMLReader::ELEMENT) {
					$root_seen = true;

					if ($reader->depth !== 0 || $reader->localName !== 'offers') {
						throw new RuntimeException('ActiveShop feed root element must be <offers>.');
					}
				}

				if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item' && $reader->depth === 1) {
					$count++;
				}
			}

			$this->throwOnLibxmlErrors('Invalid ActiveShop feed XML.');

			if (!$root_seen) {
				throw new RuntimeException('ActiveShop feed has no root element.');
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous_errors);
		}

		return $count;
	}

	private function openXmlReader($cache_file) {
		if (!class_exists('XMLReader')) {
			throw new RuntimeException('The XMLReader extension is required to read the ActiveShop feed.');
		}

		$reader = new XMLReader();
		$flags = LIBXML_NONET;

		if (defined('LIBXML_COMPACT')) {
			$flags |= LIBXML_COMPACT;
		}

		if (!@$reader->open($cache_file, 'UTF-8', $flags)) {
			throw new RuntimeException('Unable to open the ActiveShop feed cache as XML.');
		}

		// Explicitly disable DTD loading, validation and entity substitution.
		@$reader->setParserProperty(XMLReader::LOADDTD, false);
		@$reader->setParserProperty(XMLReader::VALIDATE, false);
		@$reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

		return $reader;
	}

	private function normalizeItem($item_xml) {
		$previous_errors = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$flags = LIBXML_NONET | LIBXML_NOCDATA;

		if (defined('LIBXML_COMPACT')) {
			$flags |= LIBXML_COMPACT;
		}

		$item = simplexml_load_string($item_xml, 'SimpleXMLElement', $flags);
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);

		if ($item === false || $item->getName() !== 'item' || $errors) {
			throw new RuntimeException('Unable to parse an ActiveShop product item.');
		}

		$sku = $this->sanitizePlainText((string)$item->sku);
		if ($sku === '') {
			throw new RuntimeException('ActiveShop product is missing its SKU.');
		}

		$feed_price = $this->parseDecimal((string)$item->price, 'price');
		$quantity = $this->parseInteger((string)$item->qty, 'quantity');
		$weight = $this->parseDecimal((string)$item->weight, 'weight');

		if ($feed_price < 0 || $quantity < 0 || $weight < 0) {
			throw new RuntimeException('ActiveShop price, quantity and weight cannot be negative.');
		}

		$category_path = array();
		$category_raw = trim((string)$item->category_subcategory);

		if ($category_raw !== '') {
			$parts = preg_split('/\s*>\s*/u', $category_raw);
			foreach ($parts as $part) {
				$part = $this->sanitizePlainText($part);
				if ($part !== '') {
					$category_path[] = $part;
				}
			}
		}
		$category = implode(' > ', $category_path);

		$images = array();
		if (isset($item->images)) {
			foreach ($item->images->image as $image) {
				$url = trim((string)$image);
				if ($url !== '' && $this->isAllowedImageUrl($url) && !in_array($url, $images, true)) {
					$images[] = $url;
				}
			}
		}

		$dimensions = array(
			'length' => $this->parseOptionalDecimal(isset($item->dimensions->length) ? (string)$item->dimensions->length : ''),
			'width' => $this->parseOptionalDecimal(isset($item->dimensions->width) ? (string)$item->dimensions->width : ''),
			'height' => $this->parseOptionalDecimal(isset($item->dimensions->height) ? (string)$item->dimensions->height : '')
		);

		$payload = array(
			'tax' => trim((string)$item->tax),
			'volume' => trim((string)$item->volume),
			'pack_type' => trim((string)$item->pack_type),
			'category_subcategory' => $category,
			'gpsr' => array(
				'manufacturer' => trim((string)$item->gpsr->gpsr_manufacturer),
				'brand' => trim((string)$item->gpsr->gpsr_brand),
				'contact' => trim((string)$item->gpsr->gpsr_contact),
				'ean' => trim((string)$item->gpsr->gpsr_ean)
			)
		);

		return array(
			'sku' => $sku,
			'name' => $this->sanitizePlainText((string)$item->name),
			'description' => trim((string)$item->description),
			'feed_price' => $feed_price,
			'category_path' => $category_path,
			'quantity' => $quantity,
			'weight' => $weight,
			'brand' => $this->sanitizePlainText((string)$item->brand),
			'ean' => $this->sanitizePlainText((string)$item->EAN),
			'images' => $images,
			'dimensions' => $dimensions,
			'payload' => $payload
		);
	}

	private function parseDecimal($value, $field) {
		$value = trim($value);

		if ($value === '' || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value)) {
			throw new RuntimeException('ActiveShop product has an invalid ' . $field . '.');
		}

		$number = (float)$value;

		if (!is_finite($number)) {
			throw new RuntimeException('ActiveShop product has a non-finite ' . $field . '.');
		}

		return $number;
	}

	private function parseOptionalDecimal($value) {
		$value = trim($value);

		if ($value === '') {
			return 0.0;
		}

		$number = $this->parseDecimal($value, 'dimension');

		if ($number < 0) {
			throw new RuntimeException('ActiveShop product dimensions cannot be negative.');
		}

		return $number;
	}

	private function parseInteger($value, $field) {
		$value = trim($value);

		if ($value === '' || !preg_match('/^-?[0-9]+$/', $value)) {
			throw new RuntimeException('ActiveShop product has an invalid ' . $field . '.');
		}

		return (int)$value;
	}

	private function sanitizeElementAttributes(DOMElement $element, $tag) {
		$attributes = array();
		foreach ($element->attributes as $attribute) {
			$attributes[strtolower($attribute->name)] = $attribute->value;
		}

		foreach (array_keys($attributes) as $attribute) {
			$element->removeAttribute($attribute);
		}

		if ($tag === 'img') {
			if (!isset($attributes['src']) || !$this->isAllowedImageUrl(trim($attributes['src']))) {
				return false;
			}

			$element->setAttribute('src', trim($attributes['src']));

			foreach (array('alt', 'title') as $attribute) {
				if (isset($attributes[$attribute])) {
					$element->setAttribute($attribute, trim(strip_tags($attributes[$attribute])));
				}
			}

			foreach (array('width', 'height') as $attribute) {
				if (isset($attributes[$attribute]) && preg_match('/^[0-9]{1,5}$/', trim($attributes[$attribute]))) {
					$element->setAttribute($attribute, trim($attributes[$attribute]));
				}
			}

			$element->setAttribute('loading', 'lazy');
			return true;
		}

		if ($tag === 'a') {
			if (isset($attributes['href']) && $this->isAllowedLinkUrl(trim($attributes['href']))) {
				$element->setAttribute('href', trim($attributes['href']));
				$element->setAttribute('rel', 'nofollow noopener noreferrer');
			}

			if (isset($attributes['title'])) {
				$element->setAttribute('title', trim(strip_tags($attributes['title'])));
			}

			if (isset($attributes['target']) && $attributes['target'] === '_blank') {
				$element->setAttribute('target', '_blank');
			}
		}

		if ($tag === 'td' || $tag === 'th') {
			foreach (array('colspan', 'rowspan') as $attribute) {
				if (isset($attributes[$attribute]) && preg_match('/^[0-9]{1,2}$/', trim($attributes[$attribute]))) {
					$value = (int)$attributes[$attribute];
					if ($value >= 1 && $value <= 99) {
						$element->setAttribute($attribute, (string)$value);
					}
				}
			}
		}

		return true;
	}

	private function unwrapElement(DOMElement $element) {
		$parent = $element->parentNode;

		if (!$parent) {
			return;
		}

		while ($element->firstChild) {
			$parent->insertBefore($element->firstChild, $element);
		}

		$parent->removeChild($element);
	}

	private function collectComments(DOMNode $node, array &$comments) {
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_COMMENT_NODE) {
				$comments[] = $child;
			} elseif ($child->hasChildNodes()) {
				$this->collectComments($child, $comments);
			}
		}
	}

	private function assertAllowedImageUrl($url) {
		if (!$this->isAllowedImageUrl($url)) {
			throw new InvalidArgumentException('ActiveShop image URL is not allowed: ' . $url);
		}
	}

	private function isAllowedImageUrl($url) {
		$parts = @parse_url($url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https' || !in_array(strtolower($parts['host']), $this->image_hosts, true)) {
			return false;
		}

		if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
			return false;
		}

		return $parts['path'] !== '';
	}

	private function isAllowedLinkUrl($url) {
		$parts = @parse_url($url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		return strtolower($parts['scheme']) === 'https' && !isset($parts['user']) && !isset($parts['pass']);
	}

	private function validateImageFile($file) {
		$size = filesize($file);
		if ($size === false || $size < 1 || $size > self::MAX_IMAGE_BYTES) {
			throw new RuntimeException('Downloaded ActiveShop image has an invalid size.');
		}

		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($file);
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp'
		);

		if (!isset($extensions[$mime])) {
			throw new RuntimeException('Downloaded ActiveShop media is not an allowed raster image.');
		}

		$image_size = @getimagesize($file);
		if ($image_size === false || $image_size[0] < 1 || $image_size[1] < 1) {
			throw new RuntimeException('Downloaded ActiveShop image cannot be decoded.');
		}

		return array(
			'mime' => $mime,
			'extension' => $extensions[$mime],
			'width' => (int)$image_size[0],
			'height' => (int)$image_size[1]
		);
	}

	private function buildImageFilename($url, $extension) {
		$path = (string)parse_url($url, PHP_URL_PATH);
		$basename = rawurldecode(basename($path));
		$stem = pathinfo($basename, PATHINFO_FILENAME);
		$stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem);
		$stem = trim($stem, '._-');

		if ($stem === '') {
			$stem = 'image';
		}

		return $stem . '-' . substr(hash('sha256', $url), 0, 12) . '.' . $extension;
	}

	private function downloadToFile($url, $target_file, $max_bytes, $timeout, $type) {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('The cURL extension is required to download ActiveShop data.');
		}

		$current_url = $url;

		for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
			if ($type === 'feed') {
				$this->assertAllowedFeedUrl($current_url);
			} else {
				$this->assertAllowedImageUrl($current_url);
			}

			$handle = @fopen($target_file, 'wb');
			if ($handle === false) {
				throw new RuntimeException('Unable to write a temporary ActiveShop download.');
			}

			$headers = array();
			$bytes = 0;
			$too_large = false;
			$curl = curl_init($current_url);

			$options = array(
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT => $timeout,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT => 'WorldOfBeauty-ActiveShop-Importer/1.0',
				CURLOPT_ENCODING => '',
				CURLOPT_HEADERFUNCTION => function ($curl_handle, $line) use (&$headers) {
					$length = strlen($line);
					$line = trim($line);

					if ($line === '' || strpos($line, ':') === false) {
						return $length;
					}

					list($name, $value) = explode(':', $line, 2);
					$headers[strtolower(trim($name))] = trim($value);

					return $length;
				},
				CURLOPT_WRITEFUNCTION => function ($curl_handle, $data) use ($handle, &$bytes, &$too_large, $max_bytes) {
					$length = strlen($data);
					$bytes += $length;

					if ($bytes > $max_bytes) {
						$too_large = true;
						return 0;
					}

					$written = fwrite($handle, $data);

					return $written === false ? 0 : $written;
				}
			);

			if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
				$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
			}

			curl_setopt_array($curl, $options);
			$result = curl_exec($curl);
			$error = curl_error($curl);
			$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$effective_url = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
			curl_close($curl);
			fclose($handle);

			if ($too_large) {
				throw new RuntimeException('ActiveShop ' . $type . ' exceeds the allowed download size.');
			}

			if ($result === false) {
				throw new RuntimeException('Unable to download ActiveShop ' . $type . ': ' . $error);
			}

			if ($type === 'feed') {
				$this->assertAllowedFeedUrl($effective_url);
			} else {
				$this->assertAllowedImageUrl($effective_url);
			}

			if ($status >= 300 && $status < 400) {
				if (!isset($headers['location']) || $redirect === self::MAX_REDIRECTS) {
					throw new RuntimeException('ActiveShop ' . $type . ' returned an invalid redirect.');
				}

				$current_url = $this->resolveRedirectUrl($current_url, $headers['location']);
				continue;
			}

			if ($status !== 200) {
				throw new RuntimeException('ActiveShop ' . $type . ' returned HTTP ' . $status . '.');
			}

			return;
		}

		throw new RuntimeException('ActiveShop ' . $type . ' exceeded the redirect limit.');
	}

	private function assertAllowedFeedUrl($url) {
		$parts = @parse_url($url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
			throw new InvalidArgumentException('Invalid ActiveShop feed URL.');
		}

		if (strtolower($parts['scheme']) !== 'https' || strtolower($parts['host']) !== 'b2b.activeshop.com.pl' || $parts['path'] !== '/media/productsfeed/b2b-eng.xml') {
			throw new InvalidArgumentException('ActiveShop feed URL host or path is not allowed.');
		}

		if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
			throw new InvalidArgumentException('ActiveShop feed URL credentials or port are not allowed.');
		}
	}

	private function resolveRedirectUrl($base_url, $location) {
		$location = trim($location);

		if ($location === '') {
			throw new RuntimeException('ActiveShop returned an empty redirect URL.');
		}

		if (preg_match('#^https://#i', $location)) {
			return $location;
		}

		$base = parse_url($base_url);
		if (!is_array($base) || !isset($base['host'])) {
			throw new RuntimeException('Unable to resolve the ActiveShop redirect URL.');
		}

		if (strpos($location, '//') === 0) {
			return 'https:' . $location;
		}

		if (strpos($location, '/') === 0) {
			return 'https://' . $base['host'] . $location;
		}

		$path = isset($base['path']) ? $base['path'] : '/';
		$directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

		return 'https://' . $base['host'] . ($directory === '' ? '' : $directory) . '/' . $location;
	}

	private function throwOnLibxmlErrors($prefix) {
		$errors = libxml_get_errors();

		if (!$errors) {
			return;
		}

		$error = $errors[0];
		$message = trim($error->message);

		throw new RuntimeException($prefix . ' ' . $message . ' (line ' . (int)$error->line . ').');
	}

	private function assertLocalPath($path, $label) {
		if (!is_string($path) || trim($path) === '' || strpos($path, "\0") !== false) {
			throw new InvalidArgumentException($label . ' path is invalid.');
		}

		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
			throw new InvalidArgumentException($label . ' must be a local filesystem path.');
		}
	}
}
