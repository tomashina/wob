<?php
class ModelExtensionModuleActiveshopImporter extends Model {
	const SUPPLIER_CODE = 'activeshop';
	const RECONCILE_BATCH_SIZE = 500;

	private $supplier_id_cache = null;

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_supplier` (
			`supplier_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`code` VARCHAR(64) NOT NULL,
			`name` VARCHAR(128) NOT NULL,
			`status` TINYINT(1) NOT NULL DEFAULT '1',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`supplier_id`),
			UNIQUE KEY `code` (`code`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_supplier_product` (
			`supplier_product_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`supplier_id` INT UNSIGNED NOT NULL,
			`product_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`external_id` VARCHAR(128) NOT NULL,
			`sku` VARCHAR(64) NOT NULL,
			`ean` VARCHAR(32) NOT NULL DEFAULT '',
			`name` VARCHAR(255) NOT NULL DEFAULT '',
			`brand` VARCHAR(128) NOT NULL DEFAULT '',
			`category_path` VARCHAR(512) NOT NULL DEFAULT '',
			`feed_price` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
			`quantity` INT NOT NULL DEFAULT '0',
			`weight` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
			`dimensions` TEXT NOT NULL,
			`images` MEDIUMTEXT NOT NULL,
			`payload` MEDIUMTEXT NOT NULL,
			`source_hash` CHAR(64) NOT NULL DEFAULT '',
			`feed_token` CHAR(64) NOT NULL DEFAULT '',
			`is_current` TINYINT(1) NOT NULL DEFAULT '1',
			`match_status` VARCHAR(32) NOT NULL DEFAULT 'new',
			`match_message` VARCHAR(255) NOT NULL DEFAULT '',
			`last_markup` DECIMAL(9,4) DEFAULT NULL,
			`last_calculated_price` DECIMAL(15,4) DEFAULT NULL,
			`last_seen` DATETIME DEFAULT NULL,
			`last_imported` DATETIME DEFAULT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`supplier_product_id`),
			UNIQUE KEY `supplier_external` (`supplier_id`, `external_id`),
			KEY `product_id` (`product_id`),
			KEY `sku` (`sku`),
			KEY `ean` (`ean`),
			KEY `current_category` (`is_current`, `category_path`(191)),
			KEY `last_seen` (`last_seen`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_supplier_category_map` (
			`supplier_category_map_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`supplier_id` INT UNSIGNED NOT NULL,
			`path_hash` CHAR(64) NOT NULL,
			`category_path` TEXT NOT NULL,
			`category_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`supplier_category_map_id`),
			UNIQUE KEY `supplier_path` (`supplier_id`, `path_hash`),
			KEY `category_id` (`category_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_import_run` (
			`import_run_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`supplier_id` INT UNSIGNED NOT NULL,
			`user_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`type` VARCHAR(32) NOT NULL,
			`status` VARCHAR(32) NOT NULL DEFAULT 'running',
			`markup` DECIMAL(9,4) NOT NULL DEFAULT '0.0000',
			`settings_snapshot` MEDIUMTEXT NOT NULL,
			`counts` TEXT NOT NULL,
			`error` TEXT NOT NULL,
			`date_started` DATETIME NOT NULL,
			`date_finished` DATETIME DEFAULT NULL,
			PRIMARY KEY (`import_run_id`),
			KEY `supplier_date` (`supplier_id`, `date_started`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_import_item` (
			`import_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`import_run_id` BIGINT UNSIGNED NOT NULL,
			`supplier_product_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
			`external_id` VARCHAR(128) NOT NULL DEFAULT '',
			`product_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`action` VARCHAR(32) NOT NULL,
			`status` VARCHAR(32) NOT NULL,
			`before_json` MEDIUMTEXT NOT NULL,
			`after_json` MEDIUMTEXT NOT NULL,
			`message` TEXT NOT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`import_item_id`),
			KEY `run_id` (`import_run_id`),
			KEY `supplier_product_id` (`supplier_product_id`),
			KEY `product_id` (`product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_supplier` SET `code` = '" . self::SUPPLIER_CODE . "', `name` = 'ActiveShop', `status` = '1', `date_added` = NOW(), `date_modified` = NOW() ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = '1', `date_modified` = NOW()");
		$this->supplier_id_cache = null;

		// Existing installations can retain staged feed rows while the module is
		// reinstalled. Fill only category paths which have never been mapped; a
		// saved manual choice (including an explicit empty choice) always wins.
		$this->autoMapSupplierCategories();
	}

	public function getSupplierId() {
		if ($this->supplier_id_cache !== null) {
			return $this->supplier_id_cache;
		}

		$query = $this->db->query("SELECT `supplier_id` FROM `" . DB_PREFIX . "wob_supplier` WHERE `code` = '" . self::SUPPLIER_CODE . "' LIMIT 1");
		$this->supplier_id_cache = $query->num_rows ? (int)$query->row['supplier_id'] : 0;

		return $this->supplier_id_cache;
	}

	public function stageFeedItem(array $item, $feed_token) {
		$this->stageFeedItems(array($item), $feed_token);
	}

	public function stageFeedItems(array $items, $feed_token) {
		if (!$items) {
			return 0;
		}

		$supplier_id = $this->requireSupplierId();
		$feed_token = $this->db->escape($this->truncate($feed_token, 64));
		$values = array();

		foreach ($items as $item) {
			if (!is_array($item)) {
				throw new InvalidArgumentException('ActiveShop staging batch requires item arrays.');
			}

			$sku = trim(isset($item['sku']) ? (string)$item['sku'] : '');
			if ($sku === '') {
				throw new InvalidArgumentException('ActiveShop staging item requires a SKU.');
			}

			$category_path = $this->categoryPathToString(isset($item['category_path']) ? $item['category_path'] : '');
			$payload = $this->encodeJson($item);
			$images = $this->encodeJson(isset($item['images']) && is_array($item['images']) ? $item['images'] : array());
			$dimensions = $this->encodeJson(isset($item['dimensions']) && is_array($item['dimensions']) ? $item['dimensions'] : array());

			$values[] = "('" . $supplier_id . "',
				'" . $this->db->escape($this->truncate($sku, 128)) . "',
				'" . $this->db->escape($this->truncate($sku, 64)) . "',
				'" . $this->db->escape($this->truncate(isset($item['ean']) ? $item['ean'] : '', 32)) . "',
				'" . $this->db->escape($this->truncate(isset($item['name']) ? $item['name'] : '', 255)) . "',
				'" . $this->db->escape($this->truncate(isset($item['brand']) ? $item['brand'] : '', 128)) . "',
				'" . $this->db->escape($this->truncate($category_path, 512)) . "',
				'" . $this->decimal(isset($item['feed_price']) ? $item['feed_price'] : 0, 4) . "',
				'" . max(0, isset($item['quantity']) ? (int)$item['quantity'] : 0) . "',
				'" . $this->decimal(max(0, isset($item['weight']) ? (float)$item['weight'] : 0), 4) . "',
				'" . $this->db->escape($dimensions) . "',
				'" . $this->db->escape($images) . "',
				'" . $this->db->escape($payload) . "',
				'" . $this->db->escape($this->truncate(isset($item['source_hash']) ? $item['source_hash'] : '', 64)) . "',
				'" . $feed_token . "',
				'1', NOW(), NOW(), NOW())";
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_supplier_product` (
			`supplier_id`, `external_id`, `sku`, `ean`, `name`, `brand`, `category_path`, `feed_price`,
			`quantity`, `weight`, `dimensions`, `images`, `payload`, `source_hash`, `feed_token`,
			`is_current`, `last_seen`, `date_added`, `date_modified`
		) VALUES
			" . implode(",\n\t\t\t", $values) . "
			ON DUPLICATE KEY UPDATE
			`sku` = VALUES(`sku`), `ean` = VALUES(`ean`), `name` = VALUES(`name`), `brand` = VALUES(`brand`),
			`category_path` = VALUES(`category_path`), `feed_price` = VALUES(`feed_price`), `quantity` = VALUES(`quantity`),
			`weight` = VALUES(`weight`), `dimensions` = VALUES(`dimensions`), `images` = VALUES(`images`),
			`payload` = VALUES(`payload`), `source_hash` = VALUES(`source_hash`), `feed_token` = VALUES(`feed_token`),
			`is_current` = '1', `last_seen` = NOW(), `date_modified` = NOW()");

		return count($values);
	}

	public function finishFeedRefresh($feed_token) {
		$supplier_id = $this->requireSupplierId();
		$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` SET `is_current` = '0', `date_modified` = NOW() WHERE `supplier_id` = '" . $supplier_id . "' AND `feed_token` <> '" . $this->db->escape($this->truncate($feed_token, 64)) . "'");
	}

	public function reconcileExistingProducts(array $supplier_product_ids = array()) {
		$supplier_id = $this->requireSupplierId();
		$supplier_product_ids = array_values(array_unique(array_filter(array_map('intval', $supplier_product_ids))));
		$product_query = $this->db->query("SELECT `product_id`, `sku`, `model`, `ean` FROM `" . DB_PREFIX . "product`");
		$products = array();
		$by_sku = array();
		$by_model = array();
		$by_ean = array();

		foreach ($product_query->rows as $product) {
			$product_id = (int)$product['product_id'];
			$products[$product_id] = true;
			$sku_key = $this->identifierKey($product['sku']);
			$model_key = $this->identifierKey($product['model']);
			$ean_key = $this->identifierKey($product['ean']);
			if ($sku_key !== '') {
				$by_sku[$sku_key][] = $product_id;
			}
			if ($model_key !== '') {
				$by_model[$model_key][] = $product_id;
			}
			if ($ean_key !== '') {
				$by_ean[$ean_key][] = $product_id;
			}
		}

		$sql = "SELECT sp.`supplier_product_id`, sp.`product_id`, sp.`sku`, sp.`ean`, sp.`last_imported` FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.`supplier_id` = '" . $supplier_id . "' AND sp.`is_current` = '1' AND " . $this->validStagedItemSql('sp');
		if ($supplier_product_ids) {
			$sql .= " AND `supplier_product_id` IN (" . implode(',', $supplier_product_ids) . ")";
		}
		$staged = $this->db->query($sql);
		$counts = array('matched' => 0, 'conflicts' => 0, 'new' => 0, 'linked' => 0);
		$updates = array();

		foreach ($staged->rows as $row) {
			$supplier_product_id = (int)$row['supplier_product_id'];
			$product_id = (int)$row['product_id'];
			$status = 'new';
			$message = '';

			if ($product_id && isset($products[$product_id]) && !empty($row['last_imported'])) {
				$status = 'linked';
				$counts['linked']++;
			} else {
				$product_id = 0;
				$sku_key = $this->identifierKey($row['sku']);
				$ean_key = $this->identifierKey($row['ean']);
				$sku_matches = $sku_key !== '' && isset($by_sku[$sku_key]) ? array_values(array_unique($by_sku[$sku_key])) : array();
				$ean_matches = $ean_key !== '' && isset($by_ean[$ean_key]) ? array_values(array_unique($by_ean[$ean_key])) : array();
				$model_matches = $sku_key !== '' && isset($by_model[$sku_key]) ? array_values(array_unique($by_model[$sku_key])) : array();
				$candidate_id = 0;
				$candidate_source = '';

				if (count($sku_matches) > 1) {
					$status = 'conflict_sku';
					$message = 'SKU matches product IDs: ' . implode(', ', $sku_matches);
				} elseif (count($sku_matches) === 1) {
					$candidate_id = (int)$sku_matches[0];
					$candidate_source = 'sku';
				}

				if ($status === 'new' && count($ean_matches) > 1) {
					$status = 'conflict_ean';
					$message = 'EAN matches product IDs: ' . implode(', ', $ean_matches);
				} elseif ($status === 'new' && count($ean_matches) === 1) {
					$ean_product_id = (int)$ean_matches[0];
					if ($candidate_id && $candidate_id !== $ean_product_id) {
						$status = 'conflict_identifiers';
						$message = 'SKU points to product ID ' . $candidate_id . ', but EAN points to product ID ' . $ean_product_id . '.';
					} elseif (!$candidate_id) {
						$candidate_id = $ean_product_id;
						$candidate_source = 'ean';
					}
				}

				if ($status === 'new' && !$sku_matches && count($model_matches) > 1) {
					$status = 'conflict_model';
					$message = 'Model matches product IDs: ' . implode(', ', $model_matches);
				} elseif ($status === 'new' && !$sku_matches && count($model_matches) === 1) {
					$model_product_id = (int)$model_matches[0];
					if ($candidate_id && $candidate_id !== $model_product_id) {
						$status = 'conflict_identifiers';
						$message = 'EAN points to product ID ' . $candidate_id . ', but model points to product ID ' . $model_product_id . '.';
					} elseif (!$candidate_id) {
						$candidate_id = $model_product_id;
						$candidate_source = 'model';
					}
				}

				if (strpos($status, 'conflict') === 0) {
					$counts['conflicts']++;
				} elseif ($candidate_id) {
					$product_id = $candidate_id;
					$status = 'matched_' . $candidate_source;
					$counts['matched']++;
				} else {
					$counts['new']++;
				}
			}

			$updates[] = array(
				'supplier_product_id' => $supplier_product_id,
				'product_id' => $product_id,
				'match_status' => $status,
				'match_message' => $this->truncate($message, 255)
			);

			if (count($updates) >= self::RECONCILE_BATCH_SIZE) {
				$this->applyReconciliationUpdates($supplier_id, $updates);
				$updates = array();
			}
		}

		if ($updates) {
			$this->applyReconciliationUpdates($supplier_id, $updates);
		}

		return $counts;
	}

	public function getProducts($filters = array()) {
		$sql = $this->getProductSelectSql() . " AND " . $this->validStagedItemSql('sp') . $this->buildProductWhere($filters);
		$sort_map = array(
			'name' => 'sp.name', 'sku' => 'sp.sku', 'brand' => 'sp.brand', 'category_path' => 'sp.category_path',
			'feed_price' => 'sp.feed_price', 'quantity' => 'sp.quantity', 'date_modified' => 'sp.date_modified'
		);
		$sort = isset($filters['sort']) && isset($sort_map[$filters['sort']]) ? $sort_map[$filters['sort']] : 'sp.name';
		$order = isset($filters['order']) && strtoupper($filters['order']) === 'DESC' ? 'DESC' : 'ASC';
		$sql .= " ORDER BY " . $sort . " " . $order;

		if (isset($filters['start']) || isset($filters['limit'])) {
			$start = max(0, isset($filters['start']) ? (int)$filters['start'] : 0);
			$limit = max(1, isset($filters['limit']) ? (int)$filters['limit'] : 20);
			$sql .= " LIMIT " . $start . "," . $limit;
		}

		return $this->db->query($sql)->rows;
	}

	public function getTotalProducts($filters = array()) {
		$sql = "SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_supplier_product` sp LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = sp.product_id) WHERE sp.supplier_id = '" . $this->requireSupplierId() . "' AND " . $this->validStagedItemSql('sp') . $this->buildProductWhereConditions($filters);
		$query = $this->db->query($sql);
		return (int)$query->row['total'];
	}

	public function getStatusCounts() {
		$counts = array('new' => 0, 'existing' => 0, 'imported' => 0, 'conflict' => 0, 'missing' => 0);
		$sql = "SELECT " . $this->statusSql() . " AS `ui_status`, COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.supplier_id = '" . $this->requireSupplierId() . "' AND " . $this->validStagedItemSql('sp') . " GROUP BY `ui_status`";
		foreach ($this->db->query($sql)->rows as $row) {
			$counts[$row['ui_status']] = (int)$row['total'];
		}
		return $counts;
	}

	public function getCurrentFeedEligibilityCounts() {
		$valid_sql = $this->validStagedItemSql('sp');
		$query = $this->db->query("SELECT COUNT(*) AS `staged`, SUM(" . $valid_sql . ") AS `importable` FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.supplier_id = '" . $this->requireSupplierId() . "' AND sp.is_current = '1'");
		$staged = isset($query->row['staged']) ? (int)$query->row['staged'] : 0;
		$importable = isset($query->row['importable']) ? (int)$query->row['importable'] : 0;

		return array(
			'staged' => $staged,
			'importable' => $importable,
			'excluded_invalid' => max(0, $staged - $importable)
		);
	}

	public function getSupplierCategories($filters = array()) {
		$sql = "SELECT sp.category_path, COUNT(*) AS product_count FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.supplier_id = '" . $this->requireSupplierId() . "' AND sp.category_path <> '' AND " . $this->validStagedItemSql('sp');
		if (isset($filters['is_current'])) {
			$sql .= " AND sp.is_current = '" . (!empty($filters['is_current']) ? 1 : 0) . "'";
		}
		$search = isset($filters['filter_search']) ? trim($filters['filter_search']) : '';
		if ($search !== '') {
			$sql .= " AND sp.category_path LIKE '%" . $this->db->escape($search) . "%'";
		}
		$sql .= " GROUP BY sp.category_path ORDER BY sp.category_path ASC";
		if (isset($filters['start']) || isset($filters['limit'])) {
			$start = max(0, isset($filters['start']) ? (int)$filters['start'] : 0);
			$limit = max(1, isset($filters['limit']) ? (int)$filters['limit'] : 50);
			$sql .= " LIMIT " . $start . "," . $limit;
		}
		return $this->db->query($sql)->rows;
	}

	public function getTotalSupplierCategories($filters = array()) {
		$sql = "SELECT COUNT(DISTINCT sp.category_path) AS total FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.supplier_id = '" . $this->requireSupplierId() . "' AND sp.category_path <> '' AND " . $this->validStagedItemSql('sp');
		if (isset($filters['is_current'])) {
			$sql .= " AND sp.is_current = '" . (!empty($filters['is_current']) ? 1 : 0) . "'";
		}
		$search = isset($filters['filter_search']) ? trim($filters['filter_search']) : '';
		if ($search !== '') {
			$sql .= " AND sp.category_path LIKE '%" . $this->db->escape($search) . "%'";
		}
		return (int)$this->db->query($sql)->row['total'];
	}

	public function getCategoryMappings(array $paths = array()) {
		$sql = "SELECT scm.category_path, scm.category_id, cd.name AS category_name FROM `" . DB_PREFIX . "wob_supplier_category_map` scm LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = scm.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE scm.supplier_id = '" . $this->requireSupplierId() . "'";
		if ($paths) {
			$escaped = array();
			foreach ($paths as $path) {
				$escaped[] = "'" . $this->db->escape($this->categoryPathToString($path)) . "'";
			}
			$sql .= " AND scm.category_path IN (" . implode(',', $escaped) . ")";
		}
		$result = array();
		foreach ($this->db->query($sql)->rows as $row) {
			$result[$row['category_path']] = $row;
		}
		return $result;
	}

	public function saveCategoryMappings(array $map) {
		$supplier_id = $this->requireSupplierId();
		foreach ($map as $path => $category_id) {
			$path = $this->categoryPathToString($path);
			if ($path === '') {
				continue;
			}
			$path_hash = hash('sha256', $path);
			$category_id = max(0, (int)$category_id);
			if (!$category_id) {
				// Keep a zero-value row only when a mapping already exists. This lets an
				// administrator explicitly clear an automatic mapping without it being
				// recreated on the next feed refresh, while untouched empty rows remain
				// eligible for a future automatic match.
				$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_category_map` SET `category_path` = '" . $this->db->escape($path) . "', `category_id` = '0', `date_modified` = NOW() WHERE `supplier_id` = '" . $supplier_id . "' AND `path_hash` = '" . $path_hash . "'");
				continue;
			}
			if (!$this->categoryExists($category_id)) {
				continue;
			}
			$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_supplier_category_map` SET `supplier_id` = '" . $supplier_id . "', `path_hash` = '" . $path_hash . "', `category_path` = '" . $this->db->escape($path) . "', `category_id` = '" . $category_id . "', `date_added` = NOW(), `date_modified` = NOW() ON DUPLICATE KEY UPDATE `category_path` = VALUES(`category_path`), `category_id` = VALUES(`category_id`), `date_modified` = NOW()");
		}
	}

	/**
	 * Automatically maps previously unseen supplier category paths.
	 *
	 * Matching is deliberately conservative: an exact normalized complete path
	 * is preferred, followed by an exact normalized leaf name only when that leaf
	 * identifies one local category across every installed language. Ambiguous
	 * candidates are left unmapped for manual review. INSERT IGNORE plus the
	 * up-front existing-map check make existing manual mappings race-safe.
	 */
	public function autoMapSupplierCategories() {
		$supplier_id = $this->requireSupplierId();
		$counts = array(
			'considered' => 0,
			'mapped' => 0,
			'mapped_existing_products' => 0,
			'mapped_full_path' => 0,
			'mapped_leaf' => 0,
			'preserved' => 0,
			'evidence_ambiguous' => 0,
			'evidence_insufficient' => 0,
			'ambiguous' => 0,
			'unmatched' => 0
		);

		$path_query = $this->db->query("SELECT DISTINCT sp.`category_path` FROM `" . DB_PREFIX . "wob_supplier_product` sp WHERE sp.`supplier_id` = '" . $supplier_id . "' AND sp.`is_current` = '1' AND sp.`category_path` <> '' AND " . $this->validStagedItemSql('sp') . " ORDER BY sp.`category_path` ASC");
		if (!$path_query->num_rows) {
			return $counts;
		}

		$existing = array();
		foreach ($this->db->query("SELECT `path_hash` FROM `" . DB_PREFIX . "wob_supplier_category_map` WHERE `supplier_id` = '" . $supplier_id . "'")->rows as $row) {
			$existing[$row['path_hash']] = true;
		}

		$evidence = $this->getExistingProductCategoryEvidence();
		$indexes = $this->getLocalCategoryMatchIndexes();
		foreach ($path_query->rows as $row) {
			$path = $this->categoryPathToString($row['category_path']);
			if ($path === '') {
				continue;
			}

			$counts['considered']++;
			$path_hash = hash('sha256', $path);
			if (isset($existing[$path_hash])) {
				$counts['preserved']++;
				continue;
			}

			if (isset($evidence[$path])) {
				if (!empty($evidence[$path]['ambiguous'])) {
					// Existing catalog assignments disagree or point to multiple equally
					// specific categories. Do not hide that conflict with a weaker name match.
					$counts['evidence_ambiguous']++;
					$counts['ambiguous']++;
					continue;
				}

				// One historical product is not enough to classify its entire supplier
				// path unless the exact normalized path/leaf independently confirms the
				// same category. This prevents one stale SKU match or old categorization
				// from being propagated to every new product in that feed branch.
				if ((int)$evidence[$path]['product_count'] < 2) {
					$name_match = $this->resolveAutomaticCategoryMatch($path, $indexes);
					if (empty($name_match['category_id']) || (int)$name_match['category_id'] !== (int)$evidence[$path]['category_id']) {
						$counts['evidence_insufficient']++;
						if (!empty($name_match['ambiguous']) || !empty($name_match['category_id'])) {
							$counts['ambiguous']++;
						} else {
							$counts['unmatched']++;
						}
						continue;
					}
				}
				$match = array(
					'category_id' => (int)$evidence[$path]['category_id'],
					'type' => 'existing_products',
					'ambiguous' => false
				);
			} else {
				$match = $this->resolveAutomaticCategoryMatch($path, $indexes);
			}
			if (!empty($match['ambiguous'])) {
				$counts['ambiguous']++;
				continue;
			}
			$category_id = (int)$match['category_id'];
			if (!$category_id) {
				$counts['unmatched']++;
				continue;
			}

			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "wob_supplier_category_map` SET `supplier_id` = '" . $supplier_id . "', `path_hash` = '" . $path_hash . "', `category_path` = '" . $this->db->escape($path) . "', `category_id` = '" . $category_id . "', `date_added` = NOW(), `date_modified` = NOW()");
			if ($this->db->countAffected()) {
				$counts['mapped']++;
				$counts['mapped_' . $match['type']]++;
				$existing[$path_hash] = true;
			} else {
				// Another request (or a manual save) inserted the same path first.
				$counts['preserved']++;
			}
		}

		return $counts;
	}

	public function getStagedProductsByIds(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (!$ids) {
			return array();
		}
		$sql = $this->getProductSelectSql() . " AND sp.supplier_product_id IN (" . implode(',', $ids) . ") AND sp.is_current = '1' AND " . $this->validStagedItemSql('sp') . " ORDER BY FIELD(sp.supplier_product_id," . implode(',', $ids) . ")";
		return $this->db->query($sql)->rows;
	}

	public function beginRun(array $data) {
		$supplier_id = $this->requireSupplierId();
		$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_import_run` SET `supplier_id` = '" . $supplier_id . "', `user_id` = '" . max(0, isset($data['user_id']) ? (int)$data['user_id'] : 0) . "', `type` = '" . $this->db->escape($this->truncate(isset($data['type']) ? $data['type'] : 'import', 32)) . "', `status` = 'running', `markup` = '" . $this->decimal(isset($data['markup']) ? $data['markup'] : 0, 4) . "', `settings_snapshot` = '" . $this->db->escape($this->encodeJson(isset($data['settings']) ? $data['settings'] : array())) . "', `counts` = '{}', `error` = '', `date_started` = NOW()");
		return (int)$this->db->getLastId();
	}

	public function recoverRunningRefreshRuns() {
		$this->db->query("UPDATE `" . DB_PREFIX . "wob_import_run` SET
			`status` = 'failed',
			`error` = 'Previous refresh was interrupted before it completed.',
			`date_finished` = NOW()
			WHERE `supplier_id` = '" . $this->requireSupplierId() . "' AND `type` = 'refresh' AND `status` = 'running'");
	}

	public function logRunItem($run_id, array $data) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_import_item` SET `import_run_id` = '" . max(0, (int)$run_id) . "', `supplier_product_id` = '" . max(0, isset($data['supplier_product_id']) ? (int)$data['supplier_product_id'] : 0) . "', `external_id` = '" . $this->db->escape($this->truncate(isset($data['external_id']) ? $data['external_id'] : '', 128)) . "', `product_id` = '" . max(0, isset($data['product_id']) ? (int)$data['product_id'] : 0) . "', `action` = '" . $this->db->escape($this->truncate(isset($data['action']) ? $data['action'] : 'import', 32)) . "', `status` = '" . $this->db->escape($this->truncate(isset($data['status']) ? $data['status'] : 'failed', 32)) . "', `before_json` = '" . $this->db->escape($this->encodeJson(isset($data['before']) ? $data['before'] : array())) . "', `after_json` = '" . $this->db->escape($this->encodeJson(isset($data['after']) ? $data['after'] : array())) . "', `message` = '" . $this->db->escape(isset($data['message']) ? (string)$data['message'] : '') . "', `date_added` = NOW()");
	}

	public function finishRun($run_id, array $counts, $status, $error = '') {
		$this->db->query("UPDATE `" . DB_PREFIX . "wob_import_run` SET `status` = '" . $this->db->escape($this->truncate($status, 32)) . "', `counts` = '" . $this->db->escape($this->encodeJson($counts)) . "', `error` = '" . $this->db->escape((string)$error) . "', `date_finished` = NOW() WHERE `import_run_id` = '" . max(0, (int)$run_id) . "' AND `supplier_id` = '" . $this->requireSupplierId() . "'");
	}

	public function linkProduct($supplier_product_id, $product_id, array $source) {
		$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` SET `product_id` = '" . max(0, (int)$product_id) . "', `match_status` = 'linked', `match_message` = '', `feed_price` = '" . $this->decimal(isset($source['feed_price']) ? $source['feed_price'] : 0, 4) . "', `last_markup` = '" . $this->decimal(isset($source['markup']) ? $source['markup'] : 0, 4) . "', `last_calculated_price` = '" . $this->decimal(isset($source['calculated_price']) ? $source['calculated_price'] : 0, 4) . "', `quantity` = '" . max(0, isset($source['quantity']) ? (int)$source['quantity'] : 0) . "', `last_imported` = NOW(), `date_modified` = NOW() WHERE `supplier_product_id` = '" . max(0, (int)$supplier_product_id) . "' AND `supplier_id` = '" . $this->requireSupplierId() . "'");
	}

	public function updateExistingProductTargeted($product_id, array $values) {
		$set = array();
		if (array_key_exists('price', $values)) {
			$set[] = "`price` = '" . $this->decimal(max(0, (float)$values['price']), 4) . "'";
		}
		if (array_key_exists('quantity', $values)) {
			$set[] = "`quantity` = '" . max(0, (int)$values['quantity']) . "'";
		}
		if (array_key_exists('stock_status_id', $values)) {
			$set[] = "`stock_status_id` = '" . max(0, (int)$values['stock_status_id']) . "'";
		}
		if (!$set) {
			return;
		}
		$set[] = "`date_modified` = NOW()";
		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $set) . " WHERE `product_id` = '" . max(0, (int)$product_id) . "' LIMIT 1");
		$this->cache->delete('product');
	}

	public function getRecentRuns($limit = 10) {
		$limit = max(1, min(100, (int)$limit));
		$rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "wob_import_run` WHERE `supplier_id` = '" . $this->requireSupplierId() . "' ORDER BY `import_run_id` DESC LIMIT " . $limit)->rows;
		foreach ($rows as &$row) {
			$row['counts_data'] = json_decode($row['counts'], true);
			if (!is_array($row['counts_data'])) {
				$row['counts_data'] = array();
			}
			$row['error_items'] = $this->db->query("SELECT `external_id`, `message` FROM `" . DB_PREFIX . "wob_import_item` WHERE `import_run_id` = '" . (int)$row['import_run_id'] . "' AND `status` = 'failed' ORDER BY `import_item_id` ASC LIMIT 5")->rows;
		}
		unset($row);
		return $rows;
	}

	public function getDefaultCategoryId() {
		$language_id = (int)$this->config->get('config_language_id');
		$query = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category_description` WHERE LCASE(`name`) = 'novo privremeno' ORDER BY (`language_id` = '" . $language_id . "') DESC LIMIT 1");
		return $query->num_rows ? (int)$query->row['category_id'] : 0;
	}

	public function ensureManufacturer($name) {
		$name = trim((string)$name);
		if ($name === '') {
			return 0;
		}
		$name = $this->truncate($name, 64);
		$query = $this->db->query("SELECT `manufacturer_id` FROM `" . DB_PREFIX . "manufacturer` WHERE LCASE(`name`) = '" . $this->db->escape(utf8_strtolower($name)) . "' LIMIT 1");
		if ($query->num_rows) {
			return (int)$query->row['manufacturer_id'];
		}
		$this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET `name` = '" . $this->db->escape($name) . "', `sort_order` = '0'");
		$manufacturer_id = (int)$this->db->getLastId();
		$this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET `manufacturer_id` = '" . $manufacturer_id . "', `store_id` = '0'");
		$this->cache->delete('manufacturer');
		return $manufacturer_id;
	}

	public function getLanguages() {
		return $this->db->query("SELECT `language_id`, `name`, `code` FROM `" . DB_PREFIX . "language` WHERE `status` = '1' ORDER BY `sort_order`, `name`")->rows;
	}

	public function buildUniqueSeoUrls($name, $sku) {
		$result = array(0 => array());
		$base = $this->slugify($name);
		$sku_slug = $this->slugify($sku);
		if ($sku_slug !== '' && substr($base, -strlen($sku_slug)) !== $sku_slug) {
			$base = trim($base . '-' . $sku_slug, '-');
		}
		$base = $this->truncate($base !== '' ? $base : 'activeshop-artikl', 230);
		foreach ($this->getLanguages() as $language) {
			$language_id = (int)$language['language_id'];
			$keyword = $base;
			$counter = 2;
			while ($this->seoKeywordExists($keyword, $language_id)) {
				$suffix = '-' . $counter++;
				$keyword = $this->truncate($base, 240 - strlen($suffix)) . $suffix;
			}
			$result[0][$language_id] = $keyword;
		}
		return $result;
	}

	private function getProductSelectSql() {
		return "SELECT sp.*, p.price AS local_price, p.quantity AS local_quantity, p.status AS local_status, p.image AS local_image, pd.name AS local_name,
			COALESCE(scm.category_id, 0) AS mapped_category_id, COALESCE(mapped_cd.name, '') AS mapped_category_name,
			EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1) AS has_special
			FROM `" . DB_PREFIX . "wob_supplier_product` sp
			LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = sp.product_id)
			LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "')
			LEFT JOIN `" . DB_PREFIX . "wob_supplier_category_map` scm ON (scm.supplier_id = sp.supplier_id AND scm.path_hash = SHA2(sp.category_path, 256))
			LEFT JOIN `" . DB_PREFIX . "category_description` mapped_cd ON (mapped_cd.category_id = scm.category_id AND mapped_cd.language_id = '" . (int)$this->config->get('config_language_id') . "')
			WHERE sp.supplier_id = '" . $this->requireSupplierId() . "'";
	}

	private function buildProductWhere($filters) {
		return $this->buildProductWhereConditions($filters);
	}

	private function buildProductWhereConditions($filters) {
		$sql = '';
		if (isset($filters['is_current'])) {
			$sql .= " AND sp.is_current = '" . (!empty($filters['is_current']) ? 1 : 0) . "'";
		}
		$search = isset($filters['filter_search']) ? trim($filters['filter_search']) : (isset($filters['search']) ? trim($filters['search']) : '');
		if ($search !== '') {
			$escaped = $this->db->escape($search);
			$sql .= " AND (sp.name LIKE '%" . $escaped . "%' OR sp.sku LIKE '%" . $escaped . "%' OR sp.ean LIKE '%" . $escaped . "%')";
		}
		$status = isset($filters['filter_status']) ? trim($filters['filter_status']) : (isset($filters['status']) ? trim($filters['status']) : '');
		if (in_array($status, array('new', 'existing', 'imported', 'conflict', 'missing'), true)) {
			$sql .= " AND " . $this->statusSql() . " = '" . $status . "'";
		}
		$category = isset($filters['filter_category']) ? trim($filters['filter_category']) : (isset($filters['category_path']) ? trim($filters['category_path']) : '');
		if ($category !== '') {
			$sql .= " AND sp.category_path = '" . $this->db->escape($category) . "'";
		}
		$brand = isset($filters['filter_brand']) ? trim($filters['filter_brand']) : (isset($filters['brand']) ? trim($filters['brand']) : '');
		if ($brand !== '') {
			$sql .= " AND sp.brand LIKE '%" . $this->db->escape($brand) . "%'";
		}
		return $sql;
	}

	private function statusSql() {
		return "(CASE WHEN sp.is_current = '0' THEN 'missing' WHEN sp.match_status LIKE 'conflict%' THEN 'conflict' WHEN sp.product_id > 0 AND sp.last_imported IS NOT NULL THEN 'imported' WHEN sp.product_id > 0 THEN 'existing' ELSE 'new' END)";
	}

	private function validStagedItemSql($alias) {
		$alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias);
		return "TRIM(" . $alias . ".sku) <> '' AND TRIM(" . $alias . ".name) <> '' AND " . $alias . ".feed_price > '0.0000'";
	}

	private function applyReconciliationUpdates($supplier_id, array $updates) {
		if (!$updates) {
			return;
		}

		$ids = array();
		$product_id_cases = array();
		$status_cases = array();
		$message_cases = array();

		foreach ($updates as $update) {
			$supplier_product_id = (int)$update['supplier_product_id'];
			$ids[] = $supplier_product_id;
			$product_id_cases[] = "WHEN '" . $supplier_product_id . "' THEN '" . max(0, (int)$update['product_id']) . "'";
			$status_cases[] = "WHEN '" . $supplier_product_id . "' THEN '" . $this->db->escape($this->truncate($update['match_status'], 32)) . "'";
			$message_cases[] = "WHEN '" . $supplier_product_id . "' THEN '" . $this->db->escape($this->truncate($update['match_message'], 255)) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` SET
			`product_id` = CASE `supplier_product_id` " . implode(' ', $product_id_cases) . " ELSE `product_id` END,
			`match_status` = CASE `supplier_product_id` " . implode(' ', $status_cases) . " ELSE `match_status` END,
			`match_message` = CASE `supplier_product_id` " . implode(' ', $message_cases) . " ELSE `match_message` END,
			`date_modified` = NOW()
			WHERE `supplier_id` = '" . max(0, (int)$supplier_id) . "' AND `supplier_product_id` IN (" . implode(',', $ids) . ")");
	}

	private function requireSupplierId() {
		$supplier_id = $this->getSupplierId();
		if (!$supplier_id) {
			throw new RuntimeException('ActiveShop supplier is not installed.');
		}
		return $supplier_id;
	}

	/**
	 * Builds conservative mapping evidence from products already reconciled by
	 * SKU/EAN/model. Each product contributes only active, named leaf categories
	 * which are neither top-level nor the configured fallback. When both an
	 * ancestor and its descendant are assigned, only the deepest assignment is
	 * retained. A supplier path resolves only when exactly one category remains
	 * common to every product which has usable evidence.
	 */
	private function getExistingProductCategoryEvidence() {
		$supplier_id = $this->requireSupplierId();
		$default_category_id = (int)$this->config->get('module_activeshop_importer_default_category_id');
		if (!$default_category_id) {
			$default_category_id = $this->getDefaultCategoryId();
		}
		$category_meta = array();
		$ancestor_paths = array();
		$has_children = array();

		$category_query = $this->db->query("SELECT c.`category_id`, c.`parent_id`, c.`status`, cp.`path_id`, cp.`level` FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_path` cp ON (cp.`category_id` = c.`category_id`) ORDER BY c.`category_id`, cp.`level`");
		foreach ($category_query->rows as $row) {
			$category_id = (int)$row['category_id'];
			$category_meta[$category_id] = array(
				'parent_id' => (int)$row['parent_id'],
				'status' => (int)$row['status']
			);
			if ((int)$row['parent_id'] > 0) {
				$has_children[(int)$row['parent_id']] = true;
			}
			if ($row['path_id'] !== null) {
				$ancestor_paths[$category_id][(int)$row['path_id']] = true;
			}
		}

		$named_categories = array();
		$name_query = $this->db->query("SELECT `category_id`, `name` FROM `" . DB_PREFIX . "category_description`");
		foreach ($name_query->rows as $row) {
			if ($this->normalizeCategoryMatch($row['name']) !== '') {
				$named_categories[(int)$row['category_id']] = true;
			}
		}

		$product_sets = array();
		$sql = "SELECT sp.`category_path`, sp.`product_id`, p2c.`category_id`
			FROM `" . DB_PREFIX . "wob_supplier_product` sp
			INNER JOIN `" . DB_PREFIX . "product` p ON (p.`product_id` = sp.`product_id`)
			INNER JOIN `" . DB_PREFIX . "product_to_category` p2c ON (p2c.`product_id` = sp.`product_id`)
			WHERE sp.`supplier_id` = '" . $supplier_id . "' AND sp.`is_current` = '1' AND sp.`product_id` > 0
			AND " . $this->validStagedItemSql('sp') . "
			AND sp.`category_path` <> '' AND sp.`match_status` NOT LIKE 'conflict%'
			ORDER BY sp.`category_path`, sp.`supplier_product_id`, p2c.`category_id`";
		foreach ($this->db->query($sql)->rows as $row) {
			$category_id = (int)$row['category_id'];
			if (!isset($category_meta[$category_id]) || !$category_meta[$category_id]['status'] || !$category_meta[$category_id]['parent_id'] || isset($has_children[$category_id])) {
				continue;
			}
			if ($category_id === $default_category_id || !isset($named_categories[$category_id])) {
				continue;
			}
			$path = $this->categoryPathToString($row['category_path']);
			$product_sets[$path][(int)$row['product_id']][$category_id] = true;
		}

		$result = array();
		foreach ($product_sets as $path => $sets_by_product) {
			$resolved = $this->resolveCategoryEvidenceSets(array_values($sets_by_product), $ancestor_paths);
			if (!empty($resolved['has_evidence'])) {
				$result[$path] = $resolved;
			}
		}
		return $result;
	}

	private function resolveCategoryEvidenceSets(array $product_sets, array $ancestor_paths) {
		$result = array(
			'has_evidence' => false,
			'category_id' => 0,
			'ambiguous' => false,
			'product_count' => 0
		);
		$leaf_sets = array();

		foreach ($product_sets as $set) {
			$candidates = array();
			foreach ((array)$set as $key => $value) {
				// Accept both [category_id => true] and a simple [category_id, ...]
				// list so this consensus rule stays straightforward to test.
				$category_id = is_int($key) && $value === true ? $key : $value;
				$category_id = (int)$category_id;
				if ($category_id > 0) {
					$candidates[$category_id] = true;
				}
			}
			if (!$candidates) {
				continue;
			}

			foreach (array_keys($candidates) as $possible_ancestor) {
				foreach (array_keys($candidates) as $possible_descendant) {
					if ($possible_ancestor !== $possible_descendant && isset($ancestor_paths[$possible_descendant][$possible_ancestor])) {
						unset($candidates[$possible_ancestor]);
						break;
					}
				}
			}
			if ($candidates) {
				$leaf_sets[] = $candidates;
			}
		}

		if (!$leaf_sets) {
			return $result;
		}

		$result['has_evidence'] = true;
		$result['product_count'] = count($leaf_sets);
		$consensus = array_shift($leaf_sets);
		foreach ($leaf_sets as $set) {
			$consensus = array_intersect_key($consensus, $set);
			if (!$consensus) {
				break;
			}
		}

		if (count($consensus) === 1) {
			$result['category_id'] = (int)key($consensus);
		} else {
			$result['ambiguous'] = true;
		}
		return $result;
	}

	private function getLocalCategoryMatchIndexes() {
		$indexes = array('full' => array(), 'leaf' => array());
		$descriptions = array();
		$languages = array();

		$description_query = $this->db->query("SELECT cd.`category_id`, cd.`language_id`, cd.`name` FROM `" . DB_PREFIX . "category_description` cd INNER JOIN `" . DB_PREFIX . "category` c ON (c.`category_id` = cd.`category_id`) ORDER BY cd.`category_id`, cd.`language_id`");
		foreach ($description_query->rows as $row) {
			$category_id = (int)$row['category_id'];
			$language_id = (int)$row['language_id'];
			$name = trim((string)$row['name']);
			$descriptions[$category_id][$language_id] = $name;
			$languages[$language_id] = true;

			$leaf_key = $this->normalizeCategoryMatch($name);
			if ($leaf_key !== '') {
				$indexes['leaf'][$leaf_key][$category_id] = true;
			}
		}

		$category_paths = array();
		$path_query = $this->db->query("SELECT `category_id`, `path_id`, `level` FROM `" . DB_PREFIX . "category_path` ORDER BY `category_id`, `level`");
		foreach ($path_query->rows as $row) {
			$category_paths[(int)$row['category_id']][(int)$row['level']] = (int)$row['path_id'];
		}

		foreach ($category_paths as $category_id => $path_ids) {
			ksort($path_ids, SORT_NUMERIC);
			foreach (array_keys($languages) as $language_id) {
				$parts = array();
				foreach ($path_ids as $path_id) {
					if (!isset($descriptions[$path_id][$language_id]) || trim($descriptions[$path_id][$language_id]) === '') {
						$parts = array();
						break;
					}
					$parts[] = $descriptions[$path_id][$language_id];
				}
				if (!$parts || count($parts) !== count($path_ids)) {
					continue;
				}
				$full_key = $this->normalizeCategoryMatch(implode(' > ', $parts));
				if ($full_key !== '') {
					$indexes['full'][$full_key][(int)$category_id] = true;
				}
			}
		}

		return $indexes;
	}

	private function resolveAutomaticCategoryMatch($path, array $indexes) {
		$result = array('category_id' => 0, 'type' => '', 'ambiguous' => false);
		$segments = $this->splitCategoryPath($path);
		if (!$segments) {
			return $result;
		}

		$full_key = $this->normalizeCategoryMatch(implode(' > ', $segments));
		$full_candidates = $full_key !== '' && isset($indexes['full'][$full_key]) ? array_keys($indexes['full'][$full_key]) : array();
		if (count($full_candidates) === 1) {
			$result['category_id'] = (int)$full_candidates[0];
			$result['type'] = 'full_path';
			return $result;
		}
		if (count($full_candidates) > 1) {
			$result['ambiguous'] = true;
			return $result;
		}

		$leaf_key = $this->normalizeCategoryMatch(end($segments));
		$leaf_candidates = $leaf_key !== '' && isset($indexes['leaf'][$leaf_key]) ? array_keys($indexes['leaf'][$leaf_key]) : array();
		if (count($leaf_candidates) === 1) {
			$result['category_id'] = (int)$leaf_candidates[0];
			$result['type'] = 'leaf';
		} elseif (count($leaf_candidates) > 1) {
			$result['ambiguous'] = true;
		}

		return $result;
	}

	private function splitCategoryPath($path) {
		if (is_array($path)) {
			$raw_parts = $path;
		} else {
			$value = html_entity_decode((string)$path, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$raw_parts = preg_split('/\s*(?:>|›|»)\s*/u', $value);
		}

		$parts = array();
		foreach ((array)$raw_parts as $part) {
			$part = trim((string)$part);
			if ($part !== '') {
				$parts[] = $part;
			}
		}
		return $parts;
	}

	private function normalizeCategoryMatch($value) {
		$value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (class_exists('Normalizer')) {
			$normalized = Normalizer::normalize($value, Normalizer::FORM_C);
			if ($normalized !== false) {
				$value = $normalized;
			}
		}
		$value = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
		$value = trim($value === null ? '' : $value);
		if (function_exists('utf8_strtolower')) {
			return utf8_strtolower($value);
		}
		return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
	}

	private function categoryExists($category_id) {
		return $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category` WHERE `category_id` = '" . (int)$category_id . "' LIMIT 1")->num_rows > 0;
	}

	private function seoKeywordExists($keyword, $language_id) {
		return $this->db->query("SELECT `seo_url_id` FROM `" . DB_PREFIX . "seo_url` WHERE `store_id` = '0' AND `language_id` = '" . (int)$language_id . "' AND `keyword` = '" . $this->db->escape($keyword) . "' LIMIT 1")->num_rows > 0;
	}

	private function slugify($value) {
		$value = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
		$value = strtr($value, array('Š'=>'S','Đ'=>'D','Č'=>'C','Ć'=>'C','Ž'=>'Z','š'=>'s','đ'=>'d','č'=>'c','ć'=>'c','ž'=>'z'));
		if (function_exists('iconv')) {
			$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if ($converted !== false) {
				$value = $converted;
			}
		}
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9]+/', '-', $value);
		return trim($value, '-');
	}

	private function categoryPathToString($path) {
		if (is_array($path)) {
			$parts = array();
			foreach ($path as $part) {
				$part = trim((string)$part);
				if ($part !== '') {
					$parts[] = $part;
				}
			}
			return implode(' > ', $parts);
		}
		return trim((string)$path);
	}

	private function identifierKey($value) {
		$value = trim((string)$value);
		return $value === '' ? '' : utf8_strtolower($value);
	}

	private function encodeJson($value) {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new RuntimeException('Unable to encode ActiveShop data as JSON.');
		}
		return $json;
	}

	private function decimal($value, $scale) {
		$value = is_numeric($value) ? (float)$value : 0;
		return number_format($value, $scale, '.', '');
	}

	private function truncate($value, $length) {
		$value = (string)$value;
		return utf8_strlen($value) > $length ? utf8_substr($value, 0, $length) : $value;
	}
}
