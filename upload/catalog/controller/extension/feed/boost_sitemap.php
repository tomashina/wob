<?php
class ControllerExtensionFeedBoostSitemap extends Controller {
	const URLS_PER_SITEMAP = 20000;

	public function index() {
		if (!$this->config->get('feed_boost_sitemap_status')) {
			return $this->notFound();
		}

		$types = array('product', 'category', 'information');
		$output = '<?xml version="1.0" encoding="UTF-8"?>';
		$output .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ($types as $type) {
			$total = $this->getTotal($type);
			$pages = max(1, (int) ceil($total / self::URLS_PER_SITEMAP));
			$lastModified = $this->getLastModified($type);

			for ($page = 1; $page <= $pages; $page++) {
				$url = $this->url->link(
					'extension/feed/boost_sitemap/sitemap',
					'type=' . $type . '&page=' . $page,
					true
				);
				$output .= '<sitemap><loc>' . $this->xml($url) . '</loc>';

				if ($lastModified) {
					$output .= '<lastmod>' . $this->xml(date('c', strtotime($lastModified))) . '</lastmod>';
				}

				$output .= '</sitemap>';
			}
		}

		$output .= '</sitemapindex>';
		$this->xmlResponse($output);
	}

	public function sitemap() {
		if (!$this->config->get('feed_boost_sitemap_status')) {
			return $this->notFound();
		}

		$type = isset($this->request->get['type']) ? $this->request->get['type'] : '';
		$page = isset($this->request->get['page']) ? max(1, (int) $this->request->get['page']) : 1;

		if (!in_array($type, array('product', 'category', 'information'), true)) {
			return $this->notFound();
		}

		$start = ($page - 1) * self::URLS_PER_SITEMAP;
		$rows = $this->getRows($type, $start, self::URLS_PER_SITEMAP);
		$output = '<?xml version="1.0" encoding="UTF-8"?>';
		$output .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

		if ($type === 'information' && $page === 1) {
			$output .= $this->urlNode($this->storeUrl(), date('Y-m-d'), '', 'World of Beauty');
		}

		foreach ($rows as $row) {
			if ($type === 'product') {
				$url = $this->url->link('product/product', 'product_id=' . (int) $row['id'], true);
				$image = $row['image'] ? $this->storeUrl() . 'image/' . ltrim($row['image'], '/') : '';
			} elseif ($type === 'category') {
				$url = $this->url->link('product/category', 'path=' . (int) $row['id'], true);
				$image = $row['image'] ? $this->storeUrl() . 'image/' . ltrim($row['image'], '/') : '';
			} else {
				$url = $this->url->link('information/information', 'information_id=' . (int) $row['id'], true);
				$image = '';
			}

			$output .= $this->urlNode($url, $row['modified'], $image, $row['name']);
		}

		$output .= '</urlset>';
		$this->xmlResponse($output);
	}

	private function getTotal($type) {
		$storeId = (int) $this->config->get('config_store_id');

		if ($type === 'product') {
			$sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p.product_id = p2s.product_id) WHERE p.status = 1 AND p.date_available <= NOW() AND p2s.store_id = '" . $storeId . "'";
		} elseif ($type === 'category') {
			$sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c.category_id = c2s.category_id) WHERE c.status = 1 AND c2s.store_id = '" . $storeId . "'";
		} else {
			$sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "information` i JOIN `" . DB_PREFIX . "information_to_store` i2s ON (i.information_id = i2s.information_id) WHERE i.status = 1 AND i2s.store_id = '" . $storeId . "'";
		}

		$query = $this->db->query($sql);
		return isset($query->row['total']) ? (int) $query->row['total'] : 0;
	}

	private function getLastModified($type) {
		if ($type === 'product') {
			$query = $this->db->query("SELECT MAX(GREATEST(date_added, date_modified)) AS modified FROM `" . DB_PREFIX . "product` WHERE status = 1");
		} elseif ($type === 'category') {
			$query = $this->db->query("SELECT MAX(GREATEST(date_added, date_modified)) AS modified FROM `" . DB_PREFIX . "category` WHERE status = 1");
		} else {
			return null;
		}

		return !empty($query->row['modified']) ? $query->row['modified'] : null;
	}

	private function getRows($type, $start, $limit) {
		$storeId = (int) $this->config->get('config_store_id');
		$languageId = (int) $this->config->get('config_language_id');

		if ($type === 'product') {
			$sql = "SELECT p.product_id AS id, pd.name, p.image, GREATEST(p.date_added, p.date_modified) AS modified FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id = pd.product_id AND pd.language_id = '" . $languageId . "') JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p.product_id = p2s.product_id AND p2s.store_id = '" . $storeId . "') WHERE p.status = 1 AND p.date_available <= NOW() ORDER BY p.product_id ASC LIMIT " . (int) $start . "," . (int) $limit;
		} elseif ($type === 'category') {
			$sql = "SELECT c.category_id AS id, cd.name, c.image, GREATEST(c.date_added, c.date_modified) AS modified FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id AND cd.language_id = '" . $languageId . "') JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c.category_id = c2s.category_id AND c2s.store_id = '" . $storeId . "') WHERE c.status = 1 ORDER BY c.category_id ASC LIMIT " . (int) $start . "," . (int) $limit;
		} else {
			$sql = "SELECT i.information_id AS id, id.title AS name, '' AS image, '' AS modified FROM `" . DB_PREFIX . "information` i JOIN `" . DB_PREFIX . "information_description` id ON (i.information_id = id.information_id AND id.language_id = '" . $languageId . "') JOIN `" . DB_PREFIX . "information_to_store` i2s ON (i.information_id = i2s.information_id AND i2s.store_id = '" . $storeId . "') WHERE i.status = 1 ORDER BY i.information_id ASC LIMIT " . (int) $start . "," . (int) $limit;
		}

		return $this->db->query($sql)->rows;
	}

	private function urlNode($url, $modified, $image, $name) {
		$output = '<url><loc>' . $this->xml($url) . '</loc>';

		if ($modified) {
			$output .= '<lastmod>' . $this->xml(date('c', strtotime($modified))) . '</lastmod>';
		}

		if ($image) {
			$output .= '<image:image><image:loc>' . $this->xml($image) . '</image:loc>';
			$output .= '<image:title>' . $this->xml($name) . '</image:title></image:image>';
		}

		return $output . '</url>';
	}

	private function storeUrl() {
		$url = $this->config->get('config_ssl');

		if (!$url) {
			$url = $this->config->get('config_url');
		}

		if (!$url && defined('HTTPS_SERVER')) {
			$url = HTTPS_SERVER;
		}

		return rtrim($url, '/') . '/';
	}

	private function xml($value) {
		$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	private function xmlResponse($output) {
		$this->response->addHeader('Content-Type: application/xml; charset=UTF-8');
		$this->response->addHeader('Cache-Control: public, max-age=21600');
		$this->response->setOutput($output);
	}

	private function notFound() {
		$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
		$this->response->setOutput('404 Not Found');
	}
}
