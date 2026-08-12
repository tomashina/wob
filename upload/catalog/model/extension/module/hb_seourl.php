<?php
class ModelExtensionModuleHbSeourl extends Model {
	public function hreflang(string $route, array $url_parameters): array {
		if (!$this->config->get('hb_seourl_hreflang')) {
			return [];
		}

		$this->load->model('localisation/language');
		$languages = $this->model_localisation_language->getLanguages();
		$default_language = $this->getDefaultLanguageCode();
		$alternates = [];
		$default_href = '';

		foreach ($languages as $language) {
			$href = $this->getAlternateUrl($route, $url_parameters, $language, $default_language);

			if ($href === '') {
				continue;
			}

			$alternates[] = $this->alternateTag($language['code'], $href);

			if ($language['code'] === $default_language) {
				$default_href = $href;
			}
		}

		if ($default_href !== '') {
			$alternates[] = $this->alternateTag('x-default', $default_href);
		}

		return $alternates;
	}

	public function hreflang_home(): array {
		return $this->hreflang('common/home', []);
	}

	private function getAlternateUrl(string $route, array $parameters, array $language, string $default_language): string {
		$language_id = (int)$language['language_id'];
		$store_id = (int)$this->config->get('config_store_id');
		$base_url = $this->getStoreUrl();

		if ($route === 'common/home') {
			if ($language['code'] === $default_language) {
				return $base_url;
			}

			return $base_url . rawurlencode(substr($language['code'], 0, 2)) . '/';
		}

		$query = $this->getEntityQuery($route, $parameters);

		if ($query !== '') {
			$seo_url = $this->db->query(
				"SELECT `keyword` FROM `" . DB_PREFIX . "seo_url` " .
				"WHERE `query` = '" . $this->db->escape($query) . "' " .
				"AND `language_id` = '" . $language_id . "' " .
				"AND `store_id` = '" . $store_id . "' LIMIT 1"
			);

			if (!$seo_url->num_rows || trim($seo_url->row['keyword']) === '') {
				return '';
			}

			$href = $base_url . ltrim($seo_url->row['keyword'], '/');
			return $this->appendPagination($href, $parameters);
		}

		$hb_url = $this->db->query(
			"SELECT `keyword` FROM `" . DB_PREFIX . "hb_url` " .
			"WHERE `route` = '" . $this->db->escape($route) . "' " .
			"AND `language_id` = '" . $language_id . "' " .
			"AND `store_id` = '" . $store_id . "' LIMIT 1"
		);

		if ($hb_url->num_rows && trim($hb_url->row['keyword']) !== '') {
			return $base_url . ltrim($hb_url->row['keyword'], '/');
		}

		$seo_url = $this->db->query(
			"SELECT `keyword` FROM `" . DB_PREFIX . "seo_url` " .
			"WHERE `query` = '" . $this->db->escape($route) . "' " .
			"AND `language_id` = '" . $language_id . "' " .
			"AND `store_id` = '" . $store_id . "' LIMIT 1"
		);

		if (!$seo_url->num_rows || trim($seo_url->row['keyword']) === '') {
			return '';
		}

		return $base_url . ltrim($seo_url->row['keyword'], '/');
	}

	private function getEntityQuery(string $route, array $parameters): string {
		switch ($route) {
			case 'product/product':
				return isset($parameters['product_id']) ? 'product_id=' . (int)$parameters['product_id'] : '';

			case 'product/category':
				if (!isset($parameters['path'])) {
					return '';
				}

				$path = explode('_', (string)$parameters['path']);
				return 'category_id=' . (int)end($path);

			case 'product/manufacturer/info':
				return isset($parameters['manufacturer_id']) ? 'manufacturer_id=' . (int)$parameters['manufacturer_id'] : '';

			case 'information/information':
				return isset($parameters['information_id']) ? 'information_id=' . (int)$parameters['information_id'] : '';

			default:
				return '';
		}
	}

	private function appendPagination(string $href, array $parameters): string {
		if (!isset($parameters['page']) || (int)$parameters['page'] <= 1) {
			return $href;
		}

		return $href . '?page=' . (int)$parameters['page'];
	}

	private function getDefaultLanguageCode(): string {
		$query = $this->db->query(
			"SELECT `value` FROM `" . DB_PREFIX . "setting` " .
			"WHERE `code` = 'config' AND `key` = 'config_language' " .
			"AND `store_id` = '" . (int)$this->config->get('config_store_id') . "' " .
			"ORDER BY `setting_id` DESC LIMIT 1"
		);

		if ($query->num_rows && trim($query->row['value']) !== '') {
			return $query->row['value'];
		}

		return (string)$this->config->get('config_language');
	}

	private function getStoreUrl(): string {
		$url = (string)$this->config->get('config_ssl');

		if ($url === '') {
			$url = (string)$this->config->get('config_url');
		}

		return rtrim($url, '/') . '/';
	}

	private function alternateTag(string $language, string $href): string {
		return '<link rel="alternate" hreflang="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') .
			'" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" />';
	}
}
