<?php

/**
 * Audited global regular-price adjustments for OpenCart 3.
 *
 * The catalog product table in this shop is MyISAM, so a SQL transaction
 * cannot make product changes and audit changes atomic. Each product update
 * therefore uses compare-and-swap semantics and every audit item is
 * resumable: current == target means the update already happened, current ==
 * before means it may be attempted, and every other value is a conflict.
 */
class ModelExtensionModuleGlobalPriceAdjustment extends Model {
	const BATCH_SIZE = 250;
	const MIN_PERCENT = 0.01;
	const MAX_PERCENT = 1000.0;
	const BASIS_VERSION = 1;
	const SOURCE_ACTIVESHOP_FEED = 'activeshop_feed';
	const SOURCE_CATALOG_REGULAR = 'catalog_regular';
	const SOURCE_LEGACY = 'legacy';

	const STATUS_PREVIEW = 'preview';
	const STATUS_RUNNING = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_COMPLETED_WITH_CONFLICTS = 'completed_with_conflicts';
	const STATUS_FAILED = 'failed';
	const STATUS_ROLLED_BACK = 'rolled_back';
	const STATUS_ROLLBACK_PARTIAL = 'rollback_partial';

	const ITEM_PREVIEW = 'preview';
	const ITEM_APPLYING = 'applying';
	const ITEM_UPDATED = 'updated';
	const ITEM_CONFLICT = 'conflict';
	const ITEM_FAILED = 'failed';
	const ITEM_ROLLED_BACK = 'rolled_back';
	const ITEM_ROLLING_BACK = 'rolling_back';
	const ITEM_ROLLBACK_CONFLICT = 'rollback_conflict';

	const RULE_MANUELA_MANUFACTURER = 'manuela_picard_manufacturer';
	const RULE_MANUELA_CATEGORY = 'manuela_picard_category';
	const RULE_EMOVEX_MANUFACTURER = 'emovex_manufacturer';
	const RULE_EMOVEX_CATEGORY = 'emovex_category';
	const RULE_MANUELA_ANOMALY_MP = 'manuela_picard_anomaly_mpwai604';
	const RULE_MANUELA_ANOMALY_6BS = 'manuela_picard_anomaly_6bs';

	/**
	 * These two legacy bundles cannot be identified through manufacturer or
	 * category. Keep the identity assertions deliberately store-specific and
	 * fail closed if the expected record cannot be identified unambiguously.
	 */
	private $legacy_anomalies = array(
		array(
			'rule_code' => self::RULE_MANUELA_ANOMALY_MP,
			'product_id' => 5164,
			'model' => 'MPwai604',
			'hr_name' => 'HIDRATACIJA I OBNOVA KOŽE – 24/7 Hidratanatna krema + Retinol ulje 1+1 GRATIS',
			'reason' => 'Manuela Picard legacy bundle (MPwai604)'
		),
		array(
			'rule_code' => self::RULE_MANUELA_ANOMALY_6BS,
			'product_id' => 5168,
			'model' => '6bs',
			'hr_name' => 'NEW SKIN – SET ZA DEHIDRIRANU I SUHU KOŽU SKLONU BORAMA I PIGMENTACIJI',
			'reason' => 'Manuela Picard legacy bundle (6bs)'
		)
	);

	private $schema_ready = false;
	private $activeshop_tables_available = null;

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_price_exclusion` (
			`exclusion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`product_id` INT UNSIGNED NOT NULL,
			`rule_code` VARCHAR(64) NOT NULL,
			`reason` VARCHAR(255) NOT NULL DEFAULT '',
			`source_type` VARCHAR(32) NOT NULL DEFAULT '',
			`source_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`model_snapshot` VARCHAR(64) NOT NULL DEFAULT '',
			`sku_snapshot` VARCHAR(64) NOT NULL DEFAULT '',
			`name_snapshot` VARCHAR(255) NOT NULL DEFAULT '',
			`source_snapshot` MEDIUMTEXT NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_verified` DATETIME NOT NULL,
			PRIMARY KEY (`exclusion_id`),
			UNIQUE KEY `product_rule` (`product_id`, `rule_code`),
			KEY `product_id` (`product_id`),
			KEY `rule_code` (`rule_code`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_price_adjustment_run` (
			`run_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`rollback_user_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`percent` DECIMAL(9,4) NOT NULL,
			`basis_version` TINYINT UNSIGNED NOT NULL DEFAULT '0',
			`status` VARCHAR(32) NOT NULL DEFAULT 'preview',
			`total_products` INT UNSIGNED NOT NULL DEFAULT '0',
			`eligible_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_total` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_emovex` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_manuela_picard` INT UNSIGNED NOT NULL DEFAULT '0',
			`zero_price_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`rounded_no_change_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`special_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`feed_source_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`catalog_source_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`source_conflict_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`before_total` DECIMAL(20,4) NOT NULL DEFAULT '0.0000',
			`base_total` DECIMAL(20,4) NOT NULL DEFAULT '0.0000',
			`after_total` DECIMAL(20,4) NOT NULL DEFAULT '0.0000',
			`delta_total` DECIMAL(20,4) NOT NULL DEFAULT '0.0000',
			`updated_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`conflict_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`failed_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`rolled_back_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`rollback_conflict_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`exclusion_snapshot` MEDIUMTEXT NOT NULL,
			`error` TEXT NOT NULL,
			`date_created` DATETIME NOT NULL,
			`date_started` DATETIME DEFAULT NULL,
			`date_finished` DATETIME DEFAULT NULL,
			`rollback_started` DATETIME DEFAULT NULL,
			`rollback_finished` DATETIME DEFAULT NULL,
			PRIMARY KEY (`run_id`),
			KEY `user_date` (`user_id`, `date_created`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wob_price_adjustment_item` (
			`item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`run_id` BIGINT UNSIGNED NOT NULL,
			`product_id` INT UNSIGNED NOT NULL,
			`manufacturer_id` INT UNSIGNED NOT NULL DEFAULT '0',
			`manufacturer_name` VARCHAR(128) NOT NULL DEFAULT '',
			`model` VARCHAR(64) NOT NULL DEFAULT '',
			`sku` VARCHAR(64) NOT NULL DEFAULT '',
			`product_name` VARCHAR(255) NOT NULL DEFAULT '',
			`before_price` DECIMAL(15,4) NOT NULL,
			`base_price` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
			`price_source` VARCHAR(32) NOT NULL DEFAULT 'legacy',
			`feed_price` DECIMAL(15,4) DEFAULT NULL,
			`supplier_product_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
			`source_external_id` VARCHAR(128) NOT NULL DEFAULT '',
			`source_hash` CHAR(64) NOT NULL DEFAULT '',
			`feed_token` CHAR(64) NOT NULL DEFAULT '',
			`before_markup` DECIMAL(9,4) DEFAULT NULL,
			`target_markup` DECIMAL(9,4) DEFAULT NULL,
			`before_calculated_price` DECIMAL(15,4) DEFAULT NULL,
			`target_calculated_price` DECIMAL(15,4) DEFAULT NULL,
			`target_price` DECIMAL(15,4) NOT NULL,
			`after_price` DECIMAL(15,4) DEFAULT NULL,
			`had_special` TINYINT(1) NOT NULL DEFAULT '0',
			`status` VARCHAR(32) NOT NULL DEFAULT 'preview',
			`message` TEXT NOT NULL,
			`date_created` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`item_id`),
			UNIQUE KEY `run_product` (`run_id`, `product_id`),
			KEY `run_status` (`run_id`, `status`),
			KEY `product_id` (`product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->ensureSchema();

		$lock = $this->acquireLock();
		try {
			$this->syncExclusionsUnsafe();
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Refreshes permanent product exclusions. Previously discovered exclusions
	 * are intentionally never deleted.
	 */
	public function syncExclusions() {
		$this->assertInstalled();
		$lock = $this->acquireLock();
		try {
			return $this->syncExclusionsUnsafe();
		} finally {
			$this->releaseLock($lock);
		}
	}

	public function getCurrentSummary() {
		$this->assertInstalled();
		$lock = $this->acquireLock();
		try {
			$this->syncExclusionsUnsafe();
			return $this->getCurrentSummaryUnsafe();
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Creates an immutable preview snapshot. No catalog product is changed.
	 * Returns the new run ID.
	 */
	public function createPreview($user_id, $percent) {
		$this->assertInstalled();
		$user_id = max(0, (int)$user_id);
		$percent = $this->normalizePercent($percent);
		$percent_sql = $this->decimal($percent, 4);
		$feed_join_sql = $this->activeShopFeedJoinSql('af');
		$has_feed_source = $feed_join_sql !== '';
		$valid_feed_sql = $has_feed_source ? $this->validFeedSourceSql('af', 'p') : '0 = 1';
		$base_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.feed_price WHEN af.link_count IS NULL THEN p.price ELSE 0 END" : 'p.price';
		$source_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN '" . self::SOURCE_ACTIVESHOP_FEED . "' ELSE '" . self::SOURCE_CATALOG_REGULAR . "' END" : "'" . self::SOURCE_CATALOG_REGULAR . "'";
		$feed_price_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.feed_price ELSE NULL END" : 'NULL';
		$supplier_product_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.supplier_product_id ELSE 0 END" : '0';
		$source_external_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.external_id ELSE '' END" : "''";
		$source_hash_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.source_hash ELSE '' END" : "''";
		$feed_token_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.feed_token ELSE '' END" : "''";
		$before_markup_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.last_markup ELSE NULL END" : 'NULL';
		$target_markup_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN CAST('" . $percent_sql . "' AS DECIMAL(9,4)) ELSE NULL END" : 'NULL';
		$before_calculated_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.last_calculated_price ELSE NULL END" : 'NULL';
		$feed_target_sql = $has_feed_source ? $this->targetPriceSql('af.feed_price', $percent_sql, 2) : '0';
		$catalog_target_sql = $this->targetPriceSql('p.price', $percent_sql, 4);
		$target_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN " . $feed_target_sql . " ELSE " . $catalog_target_sql . " END" : $catalog_target_sql;
		$target_calculated_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN " . $feed_target_sql . " ELSE NULL END" : 'NULL';
		$feed_pricing_drift_sql = $has_feed_source
			? "(" . $valid_feed_sql . " AND NOT ((af.last_markup <=> CAST('" . $percent_sql . "' AS DECIMAL(9,4))) AND (af.last_calculated_price <=> " . $feed_target_sql . ")))"
			: '0 = 1';
		$language_id = (int)$this->config->get('config_language_id');

		$lock = $this->acquireLock();
		try {
			$exclusion_snapshot = $this->syncExclusionsUnsafe();

			$overflow = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product` p" . $feed_join_sql . " WHERE " . $base_sql . " > '0.0000' AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND " . $target_sql . " > '99999999999.9999'");
			if ((int)$overflow->row['total'] > 0) {
				throw new OverflowException('The selected percentage would exceed the OpenCart price column limit.');
			}

			$this->db->query('START TRANSACTION');
			try {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_run` SET
					`user_id` = '" . $user_id . "', `percent` = '" . $percent_sql . "', `basis_version` = '" . self::BASIS_VERSION . "', `status` = '" . self::STATUS_PREVIEW . "',
					`exclusion_snapshot` = '" . $this->db->escape($this->encodeJson($exclusion_snapshot)) . "', `error` = '', `date_created` = NOW()");
				$run_id = (int)$this->db->getLastId();

				$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_item`
					(`run_id`, `product_id`, `manufacturer_id`, `manufacturer_name`, `model`, `sku`, `product_name`, `before_price`, `base_price`, `price_source`, `feed_price`, `supplier_product_id`, `source_external_id`, `source_hash`, `feed_token`, `before_markup`, `target_markup`, `before_calculated_price`, `target_calculated_price`, `target_price`, `had_special`, `status`, `message`, `date_created`, `date_modified`)
					SELECT '" . $run_id . "', p.product_id, p.manufacturer_id, COALESCE(m.name, ''), p.model, p.sku, COALESCE(pd.name, ''), p.price,
					" . $base_sql . ", " . $source_sql . ", " . $feed_price_sql . ", " . $supplier_product_sql . ", " . $source_external_sql . ", " . $source_hash_sql . ", " . $feed_token_sql . ",
					" . $before_markup_sql . ", " . $target_markup_sql . ", " . $before_calculated_sql . ", " . $target_calculated_sql . ", " . $target_sql . ",
					EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1),
					'" . self::ITEM_PREVIEW . "', '', NOW(), NOW()
					FROM `" . DB_PREFIX . "product` p" . $feed_join_sql . "
					LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)
					LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "')
					WHERE " . $base_sql . " > '0.0000'
					AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)
					AND (" . $target_sql . " <> p.price OR " . $feed_pricing_drift_sql . ")");

				$summary = $this->getCurrentSummaryUnsafe();
				$item_summary = $this->db->query("SELECT COUNT(*) AS `eligible_count`, COALESCE(SUM(`before_price`), 0) AS `before_total`, COALESCE(SUM(`base_price`), 0) AS `base_total`, COALESCE(SUM(`target_price`), 0) AS `after_total`, COALESCE(SUM(`target_price` - `before_price`), 0) AS `delta_total`, COALESCE(SUM(`had_special` = '1'), 0) AS `special_count`, COALESCE(SUM(`price_source` = '" . self::SOURCE_ACTIVESHOP_FEED . "'), 0) AS `feed_source_count`, COALESCE(SUM(`price_source` = '" . self::SOURCE_CATALOG_REGULAR . "'), 0) AS `catalog_source_count` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . $run_id . "'")->row;
				$rounded = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product` p" . $feed_join_sql . " WHERE " . $base_sql . " > '0.0000' AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND " . $target_sql . " = p.price AND NOT (" . $feed_pricing_drift_sql . ")")->row;

				$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET
					`total_products` = '" . (int)$summary['total_products'] . "',
					`eligible_count` = '" . (int)$item_summary['eligible_count'] . "',
					`excluded_total` = '" . (int)$summary['excluded_total'] . "',
					`excluded_emovex` = '" . (int)$summary['excluded_emovex'] . "',
					`excluded_manuela_picard` = '" . (int)$summary['excluded_manuela_picard'] . "',
					`zero_price_count` = '" . (int)$summary['zero_price_count'] . "',
					`rounded_no_change_count` = '" . (int)$rounded['total'] . "',
					`special_count` = '" . (int)$item_summary['special_count'] . "',
					`feed_source_count` = '" . (int)$item_summary['feed_source_count'] . "',
					`catalog_source_count` = '" . (int)$item_summary['catalog_source_count'] . "',
					`source_conflict_count` = '" . (int)$summary['source_conflict_count'] . "',
					`before_total` = '" . $this->decimal($item_summary['before_total'], 4) . "',
					`base_total` = '" . $this->decimal($item_summary['base_total'], 4) . "',
					`after_total` = '" . $this->decimal($item_summary['after_total'], 4) . "',
					`delta_total` = '" . $this->decimal($item_summary['delta_total'], 4) . "'
					WHERE `run_id` = '" . $run_id . "' LIMIT 1");

				$this->db->query('COMMIT');
			} catch (Throwable $exception) {
				$this->db->query('ROLLBACK');
				throw $exception;
			}

			return $run_id;
		} finally {
			$this->releaseLock($lock);
		}
	}

	public function getRun($run_id, $user_id = 0) {
		$this->assertInstalled();
		$sql = "SELECT * FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . max(0, (int)$run_id) . "'";
		if ((int)$user_id > 0) {
			$sql .= " AND `user_id` = '" . (int)$user_id . "'";
		}
		$sql .= ' LIMIT 1';
		$query = $this->db->query($sql);
		return $query->num_rows ? $this->decorateRun($query->row) : array();
	}

	public function getRunSample($run_id, $limit = 50) {
		$this->assertInstalled();
		$limit = max(1, min(200, (int)$limit));
		return $this->db->query("SELECT i.*, i.product_name AS `name`, i.before_price AS `old_price`, i.target_price AS `new_price`, p.price AS `current_price`, p.manufacturer_id AS `current_manufacturer_id` FROM `" . DB_PREFIX . "wob_price_adjustment_item` i LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = i.product_id) WHERE i.run_id = '" . max(0, (int)$run_id) . "' ORDER BY i.item_id ASC LIMIT " . $limit)->rows;
	}

	public function getRunItems($run_id, $limit = 50) {
		return $this->getRunSample($run_id, $limit);
	}

	public function getRecentRuns($limit = 10, $user_id = 0) {
		$this->assertInstalled();
		$limit = max(1, min(100, (int)$limit));
		$sql = "SELECT * FROM `" . DB_PREFIX . "wob_price_adjustment_run`";
		if ((int)$user_id > 0) {
			$sql .= " WHERE `user_id` = '" . (int)$user_id . "'";
		}
		$sql .= " ORDER BY `run_id` DESC LIMIT " . $limit;
		$rows = $this->db->query($sql)->rows;
		$url = $this->registry->get('url');
		$session = $this->registry->get('session');
		foreach ($rows as &$row) {
			$row = $this->decorateRun($row);
			if ($url && $session && isset($session->data['user_token'])) {
				$row['view_url'] = $url->link('extension/module/global_price_adjustment', 'user_token=' . $session->data['user_token'] . '&price_run_id=' . (int)$row['price_run_id'], true);
			} else {
				$row['view_url'] = '';
			}
		}
		unset($row);
		return $rows;
	}

	public function getExclusions($limit = 100) {
		$this->assertInstalled();
		$limit = max(1, min(500, (int)$limit));
		$lock = $this->acquireLock();
		try {
			$language_id = (int)$this->config->get('config_language_id');
			return $this->db->query("SELECT e.product_id,
				GROUP_CONCAT(DISTINCT e.rule_code ORDER BY e.rule_code SEPARATOR ',') AS `rule_codes`,
				GROUP_CONCAT(DISTINCT e.reason ORDER BY e.reason SEPARATOR ' | ') AS `reasons`,
					MIN(e.date_added) AS `date_added`, MAX(e.date_verified) AS `date_verified`,
					MAX(p.model) AS `model`, MAX(p.sku) AS `sku`, MAX(p.price) AS `price`, MAX(p.status) AS `status`, COALESCE(MAX(pd.name), MAX(e.name_snapshot)) AS `name`,
					GROUP_CONCAT(DISTINCT e.reason ORDER BY e.reason SEPARATOR ' | ') AS `reason`,
					GROUP_CONCAT(DISTINCT e.reason ORDER BY e.reason SEPARATOR ' | ') AS `reason_text`, COALESCE(MAX(m.name), '') AS `manufacturer_name`
				FROM `" . DB_PREFIX . "wob_price_exclusion` e
				LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = e.product_id)
				LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "')
				LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)
				GROUP BY e.product_id
					ORDER BY `name`, e.product_id
				LIMIT " . $limit)->rows;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Applies all pending snapshot rows with compare-and-swap protection.
	 * The same run can be called again after interruption without compounding.
	 */
	public function applyRun($run_id, $user_id) {
		$this->assertInstalled();
		$run_id = max(0, (int)$run_id);
		$user_id = max(0, (int)$user_id);
		$lock = $this->acquireLock();

		try {
			$this->syncExclusionsUnsafe();
			$run = $this->getRunForMutation($run_id, $user_id);

			if (in_array($run['status'], array(self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_CONFLICTS, self::STATUS_ROLLED_BACK, self::STATUS_ROLLBACK_PARTIAL), true)) {
				return $this->decorateRun($run);
			}
			if (!isset($run['basis_version']) || (int)$run['basis_version'] !== self::BASIS_VERSION) {
				throw new RuntimeException('This legacy preview predates audited feed-price bases and cannot be applied. Create a new preview.');
			}
			if ($run['status'] === self::STATUS_FAILED) {
				throw new RuntimeException('A failed price run cannot be applied. Create a new preview.');
			}
			if (!in_array($run['status'], array(self::STATUS_PREVIEW, self::STATUS_RUNNING), true) || !empty($run['rollback_started'])) {
				throw new RuntimeException('The price run is not available for applying.');
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = '" . self::STATUS_RUNNING . "', `date_started` = COALESCE(`date_started`, NOW()), `error` = '' WHERE `run_id` = '" . $run_id . "' AND `user_id` = '" . $user_id . "' LIMIT 1");

			do {
				$items = $this->db->query("SELECT * FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . $run_id . "' AND `status` IN ('" . self::ITEM_PREVIEW . "','" . self::ITEM_APPLYING . "') ORDER BY `item_id` ASC LIMIT " . self::BATCH_SIZE)->rows;
				foreach ($items as $item) {
					$this->applyItem($item);
				}
			} while ($items);

			$counts = $this->getItemStatusCounts($run_id);
			$status = ($counts['conflict'] || $counts['failed']) ? self::STATUS_COMPLETED_WITH_CONFLICTS : self::STATUS_COMPLETED;
			$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET
				`status` = '" . $status . "', `updated_count` = '" . $counts['updated'] . "', `conflict_count` = '" . $counts['conflict'] . "', `failed_count` = '" . $counts['failed'] . "', `date_finished` = NOW()
				WHERE `run_id` = '" . $run_id . "' AND `user_id` = '" . $user_id . "' LIMIT 1");

			if ($counts['updated']) {
				$this->cache->delete('product');
			}

			return $this->getRun($run_id, $user_id);
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Restores only rows whose current regular price still equals the price
	 * written by this run. Later/manual changes are never overwritten.
	 */
	public function rollbackRun($run_id, $user_id) {
		$this->assertInstalled();
		$run_id = max(0, (int)$run_id);
		$user_id = max(0, (int)$user_id);
		$lock = $this->acquireLock();

		try {
			$this->syncExclusionsUnsafe();
			$run = $this->getRunForMutation($run_id, $user_id);
			if ($run['status'] === self::STATUS_ROLLED_BACK) {
				return $this->decorateRun($run);
			}
			$retrying = ($run['status'] === self::STATUS_RUNNING && !empty($run['rollback_started']))
				|| ($run['status'] === self::STATUS_ROLLBACK_PARTIAL && empty($run['rollback_finished']));
			if (!$retrying && !in_array($run['status'], array(self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_CONFLICTS, self::STATUS_FAILED), true)) {
				throw new RuntimeException('Only a completed price run can be rolled back.');
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = '" . self::STATUS_RUNNING . "', `rollback_user_id` = '" . $user_id . "', `rollback_started` = COALESCE(`rollback_started`, NOW()), `error` = '' WHERE `run_id` = '" . $run_id . "' LIMIT 1");

			do {
				$items = $this->db->query("SELECT * FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . $run_id . "' AND `status` IN ('" . self::ITEM_APPLYING . "','" . self::ITEM_UPDATED . "','" . self::ITEM_ROLLING_BACK . "') ORDER BY `item_id` ASC LIMIT " . self::BATCH_SIZE)->rows;
				foreach ($items as $item) {
					$this->rollbackItem($item);
				}
			} while ($items);

			$counts = $this->getItemStatusCounts($run_id);
			$status = $counts['rollback_conflict'] ? self::STATUS_ROLLBACK_PARTIAL : self::STATUS_ROLLED_BACK;
			$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET
				`status` = '" . $status . "', `rolled_back_count` = '" . $counts['rolled_back'] . "', `rollback_conflict_count` = '" . $counts['rollback_conflict'] . "', `rollback_finished` = NOW()
				WHERE `run_id` = '" . $run_id . "' LIMIT 1");

			if ($counts['rolled_back']) {
				$this->cache->delete('product');
			}

			return $this->getRun($run_id, $user_id);
		} finally {
			$this->releaseLock($lock);
		}
	}

	public function failRun($run_id, $user_id, $message) {
		$this->assertInstalled();
		$run_id = max(0, (int)$run_id);
		$user_id = max(0, (int)$user_id);
		$lock = $this->acquireLock();
		try {
			$run = $this->getRun($run_id, $user_id);
			if (!$run) {
				return array();
			}
			if (in_array($run['status'], array(self::STATUS_PREVIEW, self::STATUS_RUNNING), true)) {
				$status = !empty($run['rollback_started']) ? self::STATUS_ROLLBACK_PARTIAL : self::STATUS_FAILED;
				if (empty($run['rollback_started'])) {
					$this->settleApplyingItemsAfterFailure($run_id);
				}
				$counts = $this->getItemStatusCounts($run_id);
				$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET `status` = '" . $status . "',
					`updated_count` = '" . $counts['updated'] . "', `conflict_count` = '" . $counts['conflict'] . "', `failed_count` = '" . $counts['failed'] . "',
					`rolled_back_count` = '" . $counts['rolled_back'] . "', `rollback_conflict_count` = '" . $counts['rollback_conflict'] . "',
					`error` = '" . $this->db->escape((string)$message) . "', `date_finished` = NOW()
					WHERE `run_id` = '" . $run_id . "' AND `user_id` = '" . $user_id . "' LIMIT 1");
			}
			return $this->getRun($run_id, $user_id);
		} finally {
			$this->releaseLock($lock);
		}
	}

	private function applyItem(array $item) {
		$item_id = (int)$item['item_id'];
		$is_recovery = $item['status'] === self::ITEM_APPLYING;
		$current = $this->getCurrentItemState($item_id);
		if ($is_recovery && $current) {
			$recovery = $this->reconcileApplyingState($item_id, $current);
			if (!empty($recovery['terminal'])) {
				return;
			}
			$current = $recovery['state'];
		}

		if (!$current || empty($current['product_exists'])) {
			$this->markItem($item_id, self::ITEM_CONFLICT, null, 'Product no longer exists.');
			return;
		}
		if (!empty($current['is_excluded'])) {
			$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Product became excluded after preview.');
			return;
		}
		if (empty($current['is_same_manufacturer'])) {
			$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Supplier changed after preview.');
			return;
		}
		if (!empty($current['is_target']) && !empty($current['is_supplier_target'])) {
			if ($is_recovery) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Already at the target price; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Target price was reached outside this run before its update attempt.');
			}
			return;
		}
		if (empty($current['is_same_price_source'])) {
			if ($is_recovery) {
				$this->compensatePartialApply($item_id, $current);
				$current = $this->getCurrentItemState($item_id);
			}
			$this->markItem($item_id, self::ITEM_CONFLICT, $current ? $current['current_price'] : null, 'Price source changed after preview.');
			return;
		}
		if (empty($current['is_before']) && (!$is_recovery || empty($current['is_target']))) {
			$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Price changed after preview.');
			return;
		}
		if (empty($current['is_supplier_before']) && (!$is_recovery || empty($current['is_supplier_target']))) {
			$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Managed supplier pricing changed after preview.');
			return;
		}

		try {
			if (!$is_recovery) {
				$this->markItem($item_id, self::ITEM_APPLYING, $current['current_price'], 'CAS update prepared.');
			}
			if (!empty($current['is_before']) && empty($current['is_target'])) {
				$this->updateProductPriceToTarget($item_id);
			}

			$current = $this->getCurrentItemState($item_id);
			if ($current && !empty($current['is_target']) && !empty($current['is_supplier_before'])) {
				$this->updateSupplierPricingToTarget($item_id);
				$current = $this->getCurrentItemState($item_id);
			}

			if ($current && !empty($current['is_target']) && !empty($current['is_supplier_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Already at the target price; recovered idempotently.');
			} else {
				$this->compensatePartialApply($item_id, $current);
				$current = $this->getCurrentItemState($item_id);
				$this->markItem($item_id, self::ITEM_CONFLICT, $current ? $current['current_price'] : null, 'Compare-and-swap update was not applied.');
			}
		} catch (Throwable $exception) {
			$after_error = $this->getCurrentItemState($item_id);
			if ($after_error && !empty($after_error['is_target']) && !empty($after_error['is_supplier_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $after_error['current_price'], 'Update completed before an error response; recovered idempotently.');
			} else {
				$this->compensatePartialApply($item_id, $after_error);
				$after_error = $this->getCurrentItemState($item_id);
				$this->markItem($item_id, self::ITEM_FAILED, $after_error ? $after_error['current_price'] : $current['current_price'], $exception->getMessage());
			}
		}
	}

	private function rollbackItem(array $item) {
		$item_id = (int)$item['item_id'];
		$is_recovery = $item['status'] === self::ITEM_ROLLING_BACK;
		$apply_was_in_flight = $item['status'] === self::ITEM_APPLYING;
		$current = $this->getCurrentItemState($item_id);
		if (!$current || empty($current['product_exists'])) {
			$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, null, 'Product no longer exists.');
			return;
		}
		if (!empty($current['is_excluded'])) {
			$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current['current_price'], 'Product is now permanently excluded.');
			return;
		}
		if (!empty($current['is_before']) && !empty($current['is_supplier_before'])) {
			if ($is_recovery || $apply_was_in_flight) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $current['current_price'], 'Already restored; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current['current_price'], 'Price was already changed outside this rollback.');
			}
			return;
		}
		if (empty($current['is_supplier_before']) && empty($current['is_supplier_target'])) {
			if (!empty($current['is_target']) && empty($current['is_before'])) {
				$this->restoreProductPriceToBefore($item_id, array(self::ITEM_UPDATED, self::ITEM_APPLYING, self::ITEM_ROLLING_BACK));
				$current = $this->getCurrentItemState($item_id);
			}
			$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current ? $current['current_price'] : null, 'Managed supplier pricing differs from this run.');
			return;
		}
		if (empty($current['is_target']) && empty($current['is_before'])) {
			if (!empty($current['is_supplier_target'])) {
				$this->restoreSupplierPricingToBefore($item_id, array(self::ITEM_UPDATED, self::ITEM_APPLYING, self::ITEM_ROLLING_BACK));
			}
			$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current['current_price'], 'Current price differs from the price written by this run.');
			return;
		}

		try {
			if (!$is_recovery) {
				$this->markItem($item_id, self::ITEM_ROLLING_BACK, $current['current_price'], 'CAS rollback prepared.');
			}
			if (!empty($current['is_supplier_target'])) {
				$this->restoreSupplierPricingToBefore($item_id, array(self::ITEM_ROLLING_BACK));
				$current = $this->getCurrentItemState($item_id);
			}
			if ($current && !empty($current['is_supplier_before']) && !empty($current['is_target']) && empty($current['is_before'])) {
				$this->restoreProductPriceToBefore($item_id, array(self::ITEM_ROLLING_BACK));
				$current = $this->getCurrentItemState($item_id);
			}

			if ($current && !empty($current['is_before']) && !empty($current['is_supplier_before'])) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $current['current_price'], 'Already restored; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current ? $current['current_price'] : null, 'Compare-and-swap rollback was not applied.');
			}
		} catch (Throwable $exception) {
			$after_error = $this->getCurrentItemState($item_id);
			if ($after_error && !empty($after_error['is_before']) && !empty($after_error['is_supplier_before'])) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $after_error['current_price'], 'Rollback completed before an error response; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $after_error ? $after_error['current_price'] : $current['current_price'], $exception->getMessage());
			}
		}
	}

	private function getCurrentItemState($item_id) {
		$query = $this->db->query("SELECT i.item_id, p.product_id AS `product_exists`, p.price AS `current_price`, p.manufacturer_id AS `current_manufacturer_id`,
			(p.price = i.before_price) AS `is_before`, (p.price = i.target_price) AS `is_target`, (p.manufacturer_id = i.manufacturer_id) AS `is_same_manufacturer`,
			(" . $this->priceSourceCasSql('i', 'p') . ") AS `is_same_price_source`,
			(" . $this->supplierPricingStateSql('i', 'before') . ") AS `is_supplier_before`,
			(" . $this->supplierPricingStateSql('i', 'target') . ") AS `is_supplier_target`,
			EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AS `is_excluded`
			FROM `" . DB_PREFIX . "wob_price_adjustment_item` i LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = i.product_id)
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' LIMIT 1");
		return $query->num_rows ? $query->row : array();
	}

	private function updateProductPriceToTarget($item_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "product` p
			INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.product_id = p.product_id)
			SET p.price = i.target_price, p.date_modified = NOW()
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' AND i.status = '" . self::ITEM_APPLYING . "'
			AND p.price = i.before_price AND p.manufacturer_id = i.manufacturer_id
			AND " . $this->priceSourceCasSql('i', 'p') . "
			AND (" . $this->supplierPricingStateSql('i', 'before') . " OR " . $this->supplierPricingStateSql('i', 'target') . ")
			AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)");
	}

	private function updateSupplierPricingToTarget($item_id) {
		if (!$this->hasActiveShopTables()) {
			throw new RuntimeException('ActiveShop pricing tables disappeared during apply.');
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` sp
			INNER JOIN `" . DB_PREFIX . "wob_supplier` s ON (s.supplier_id = sp.supplier_id AND s.code = 'activeshop' AND s.status = '1')
			INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.supplier_product_id = sp.supplier_product_id)
			INNER JOIN `" . DB_PREFIX . "product` p ON (p.product_id = i.product_id)
			LEFT JOIN `" . DB_PREFIX . "wob_supplier_product` sp_other ON (sp_other.supplier_id = sp.supplier_id AND sp_other.product_id = sp.product_id AND sp_other.supplier_product_id <> sp.supplier_product_id)
			SET sp.last_markup = i.target_markup, sp.last_calculated_price = i.target_calculated_price, sp.date_modified = NOW()
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' AND i.status = '" . self::ITEM_APPLYING . "'
			AND i.price_source = '" . self::SOURCE_ACTIVESHOP_FEED . "' AND p.price = i.target_price
			AND sp_other.supplier_product_id IS NULL AND sp.product_id = p.product_id AND sp.is_current = '1'
			AND sp.external_id = i.source_external_id AND sp.external_id = p.sku AND sp.feed_price = i.feed_price
			AND BINARY sp.source_hash = BINARY i.source_hash AND BINARY sp.feed_token = BINARY i.feed_token
			AND (sp.last_markup <=> i.before_markup) AND (sp.last_calculated_price <=> i.before_calculated_price)");
	}

	private function restoreProductPriceToBefore($item_id, array $statuses) {
		$this->db->query("UPDATE `" . DB_PREFIX . "product` p
			INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.product_id = p.product_id)
			SET p.price = i.before_price, p.date_modified = NOW()
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' AND i.status IN (" . $this->quotedList($statuses) . ")
			AND p.price = i.target_price
			AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)");
	}

	private function restoreSupplierPricingToBefore($item_id, array $statuses) {
		if (!$this->hasActiveShopTables()) {
			return;
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "wob_supplier_product` sp
			INNER JOIN `" . DB_PREFIX . "wob_supplier` s ON (s.supplier_id = sp.supplier_id AND s.code = 'activeshop')
			INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.supplier_product_id = sp.supplier_product_id)
			SET sp.last_markup = i.before_markup, sp.last_calculated_price = i.before_calculated_price, sp.date_modified = NOW()
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' AND i.status IN (" . $this->quotedList($statuses) . ")
			AND i.price_source = '" . self::SOURCE_ACTIVESHOP_FEED . "'
			AND sp.product_id = i.product_id AND sp.external_id = i.source_external_id
			AND (sp.last_markup <=> i.target_markup) AND (sp.last_calculated_price <=> i.target_calculated_price)");
	}

	private function compensatePartialApply($item_id, array $current) {
		if (!$current) {
			return;
		}
		if (!empty($current['is_target']) && empty($current['is_before']) && empty($current['is_supplier_target'])) {
			$this->restoreProductPriceToBefore($item_id, array(self::ITEM_APPLYING));
		}
		if ((empty($current['is_target']) || !empty($current['is_before'])) && !empty($current['is_supplier_target']) && empty($current['is_supplier_before'])) {
			$this->restoreSupplierPricingToBefore($item_id, array(self::ITEM_APPLYING));
		}
	}

	private function reconcileApplyingState($item_id, array $current) {
		if (!empty($current['is_target']) && !empty($current['is_supplier_target'])) {
			$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Already at the target price; recovered idempotently.');
			return array('terminal' => true, 'state' => $current);
		}

		if ($this->hasOutstandingRunMutation($current)) {
			$this->compensatePartialApply($item_id, $current);
			$current = $this->getCurrentItemState($item_id);
			if ($current && !empty($current['is_target']) && !empty($current['is_supplier_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Interrupted update completed; reconciled from catalog and supplier pricing.');
				return array('terminal' => true, 'state' => $current);
			}
			if ($current && $this->hasOutstandingRunMutation($current)) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Interrupted partial update remains rollbackable.');
				return array('terminal' => true, 'state' => $current);
			}
		}

		return array('terminal' => false, 'state' => $current);
	}

	private function hasOutstandingRunMutation(array $current) {
		$product_written = !empty($current['is_target']) && empty($current['is_before']);
		$supplier_written = !empty($current['is_supplier_target']) && empty($current['is_supplier_before']);
		return $product_written || $supplier_written;
	}

	private function markItem($item_id, $status, $after_price, $message) {
		$after_sql = $after_price === null ? 'NULL' : "'" . $this->decimal($after_price, 4) . "'";
		$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_item` SET `status` = '" . $this->db->escape($status) . "', `after_price` = " . $after_sql . ", `message` = '" . $this->db->escape((string)$message) . "', `date_modified` = NOW() WHERE `item_id` = '" . max(0, (int)$item_id) . "' LIMIT 1");
	}

	private function getItemStatusCounts($run_id) {
		$counts = array('preview' => 0, 'applying' => 0, 'updated' => 0, 'conflict' => 0, 'failed' => 0, 'rolled_back' => 0, 'rolling_back' => 0, 'rollback_conflict' => 0);
		$query = $this->db->query("SELECT `status`, COUNT(*) AS `total` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . max(0, (int)$run_id) . "' GROUP BY `status`");
		foreach ($query->rows as $row) {
			if (array_key_exists($row['status'], $counts)) {
				$counts[$row['status']] = (int)$row['total'];
			}
		}
		return $counts;
	}

	/**
	 * A controller-level failure may occur after an item was marked applying
	 * but before its final audit status was written. Resolve only what is
	 * observable; never attempt another catalog mutation from failRun().
	 */
	private function settleApplyingItemsAfterFailure($run_id) {
		$items = $this->db->query("SELECT `item_id` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . max(0, (int)$run_id) . "' AND `status` = '" . self::ITEM_APPLYING . "' ORDER BY `item_id`")->rows;
		foreach ($items as $item) {
			$item_id = (int)$item['item_id'];
			$current = $this->getCurrentItemState($item_id);
			if ($current) {
				$recovery = $this->reconcileApplyingState($item_id, $current);
				if (!empty($recovery['terminal'])) {
					continue;
				}
				$current = $recovery['state'];
			}
			if (!$current || empty($current['product_exists'])) {
				$this->markItem($item_id, self::ITEM_CONFLICT, null, 'Product disappeared while the interrupted update was being reconciled.');
			} elseif (!empty($current['is_target']) && !empty($current['is_supplier_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Interrupted update completed; reconciled from the catalog price.');
			} elseif (!empty($current['is_before']) && !empty($current['is_supplier_before'])) {
				$this->markItem($item_id, self::ITEM_FAILED, $current['current_price'], 'Interrupted before the catalog price changed.');
			} else {
				$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Catalog or managed supplier pricing changed during the interrupted update.');
			}
		}
	}

	private function getRunForMutation($run_id, $user_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "wob_price_adjustment_run` WHERE `run_id` = '" . max(0, (int)$run_id) . "' AND `user_id` = '" . max(0, (int)$user_id) . "' LIMIT 1");
		if (!$query->num_rows) {
			throw new RuntimeException('Price run was not found for this user.');
		}
		return $query->row;
	}

	private function getCurrentSummaryUnsafe() {
		$feed_join_sql = $this->activeShopFeedJoinSql('af');
		$has_feed_source = $feed_join_sql !== '';
		$valid_feed_sql = $has_feed_source ? $this->validFeedSourceSql('af', 'p') : '0 = 1';
		$base_sql = $has_feed_source ? "CASE WHEN " . $valid_feed_sql . " THEN af.feed_price WHEN af.link_count IS NULL THEN p.price ELSE 0 END" : 'p.price';
		$is_feed_sql = $valid_feed_sql;
		$is_catalog_sql = $has_feed_source ? 'af.link_count IS NULL' : '1 = 1';
		$is_source_conflict_sql = $has_feed_source ? '(af.link_count IS NOT NULL AND NOT (' . $valid_feed_sql . '))' : '0 = 1';
		$not_excluded_sql = "NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)";
		$query = $this->db->query("SELECT
			COUNT(*) AS `total_products`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND " . $not_excluded_sql . "), 0) AS `eligible_count`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `excluded_total`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'emovex_%')), 0) AS `excluded_emovex`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'manuela_picard_%')), 0) AS `excluded_manuela_picard`,
			COALESCE(SUM(" . $is_catalog_sql . " AND p.price <= '0.0000' AND " . $not_excluded_sql . "), 0) AS `zero_price_count`,
			COALESCE(SUM(" . $is_source_conflict_sql . " AND " . $not_excluded_sql . "), 0) AS `source_conflict_count`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND p.status = '1' AND " . $not_excluded_sql . "), 0) AS `eligible_enabled`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND p.status = '0' AND " . $not_excluded_sql . "), 0) AS `eligible_disabled`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND p.manufacturer_id = '0' AND " . $not_excluded_sql . "), 0) AS `eligible_without_supplier`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND " . $is_feed_sql . " AND " . $not_excluded_sql . "), 0) AS `feed_base_count`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND " . $is_catalog_sql . " AND " . $not_excluded_sql . "), 0) AS `catalog_base_count`,
			COALESCE(SUM(CASE WHEN " . $base_sql . " > '0.0000' AND " . $not_excluded_sql . " THEN p.price ELSE 0 END), 0) AS `current_total`,
			COALESCE(SUM(CASE WHEN " . $base_sql . " > '0.0000' AND " . $not_excluded_sql . " THEN " . $base_sql . " ELSE 0 END), 0) AS `base_total`,
			COALESCE(SUM(" . $base_sql . " > '0.0000' AND " . $not_excluded_sql . " AND EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1)), 0) AS `special_count`
			FROM `" . DB_PREFIX . "product` p" . $feed_join_sql);
		$row = $query->row;
		foreach (array('total_products', 'eligible_count', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard', 'zero_price_count', 'source_conflict_count', 'eligible_enabled', 'eligible_disabled', 'eligible_without_supplier', 'feed_base_count', 'catalog_base_count', 'special_count') as $key) {
			$row[$key] = (int)$row[$key];
		}
		$row['current_total'] = $this->decimal($row['current_total'], 4);
		$row['base_total'] = $this->decimal($row['base_total'], 4);
		return $row;
	}

	/**
	 * Resolves canonical sources first, then atomically upserts all matching
	 * products into the persistent exclusion registry.
	 */
	private function syncExclusionsUnsafe() {
		$sources = $this->resolveCanonicalSources();
		$this->db->query('START TRANSACTION');
		try {
			$this->upsertManufacturerRule(self::RULE_MANUELA_MANUFACTURER, 'Manuela Picard manufacturer', $sources['manuela_manufacturer_ids']);
			$this->upsertCategoryRule(self::RULE_MANUELA_CATEGORY, 'Manuela Picard category subtree', $sources['manuela_category_ids'], $sources['manuela_category_root_id']);
			if ($sources['emovex_manufacturer_ids']) {
				$this->upsertManufacturerRule(self::RULE_EMOVEX_MANUFACTURER, 'Emovex manufacturer', $sources['emovex_manufacturer_ids']);
			}
			$this->upsertCategoryRule(self::RULE_EMOVEX_CATEGORY, 'Emovex category subtree', $sources['emovex_category_ids'], $sources['emovex_category_root_id']);
			foreach ($sources['legacy_anomalies'] as $anomaly) {
				$this->upsertProductExclusion($anomaly, $anomaly['rule_code'], $anomaly['reason'], 'legacy_identity', (int)$anomaly['product_id'], array('model' => $anomaly['model'], 'hr_name' => $anomaly['hr_name']));
			}
			$this->db->query('COMMIT');
		} catch (Throwable $exception) {
			$this->db->query('ROLLBACK');
			throw $exception;
		}

		$counts = $this->db->query("SELECT COUNT(DISTINCT `product_id`) AS `total`, COUNT(DISTINCT CASE WHEN `rule_code` LIKE 'emovex_%' THEN `product_id` END) AS `emovex`, COUNT(DISTINCT CASE WHEN `rule_code` LIKE 'manuela_picard_%' THEN `product_id` END) AS `manuela_picard` FROM `" . DB_PREFIX . "wob_price_exclusion`")->row;
		return array(
			'rules' => array(
				'manuela_picard' => array('manufacturer_ids' => $sources['manuela_manufacturer_ids'], 'category_root_id' => $sources['manuela_category_root_id']),
				'emovex' => array('manufacturer_ids' => $sources['emovex_manufacturer_ids'], 'category_root_id' => $sources['emovex_category_root_id']),
				'legacy_anomalies' => array_map(function ($row) { return (int)$row['product_id']; }, $sources['legacy_anomalies'])
			),
			'persisted_counts' => array('total' => (int)$counts['total'], 'emovex' => (int)$counts['emovex'], 'manuela_picard' => (int)$counts['manuela_picard']),
			'verified_at' => date('c')
		);
	}

	private function resolveCanonicalSources() {
		$manufacturer_rows = $this->db->query("SELECT `manufacturer_id`, `name` FROM `" . DB_PREFIX . "manufacturer` ORDER BY `manufacturer_id`")->rows;
		$manuela_manufacturers = array();
		$emovex_manufacturers = array();
		foreach ($manufacturer_rows as $row) {
			$name = $this->normalizeIdentity($row['name']);
			if ($name === 'manuela picard') {
				$manuela_manufacturers[] = (int)$row['manufacturer_id'];
			}
			if ($name === 'emovex') {
				$emovex_manufacturers[] = (int)$row['manufacturer_id'];
			}
		}
		if (count($manuela_manufacturers) !== 1) {
			throw new RuntimeException('Canonical Manuela Picard manufacturer is missing or ambiguous. Price changes are blocked.');
		}
		if (count($emovex_manufacturers) > 1) {
			throw new RuntimeException('Canonical Emovex manufacturer is ambiguous. Price changes are blocked.');
		}

		$category_rows = $this->db->query("SELECT `category_id`, `parent_id` FROM `" . DB_PREFIX . "category` ORDER BY `category_id`")->rows;
		$category_names = $this->db->query("SELECT `category_id`, `name` FROM `" . DB_PREFIX . "category_description` ORDER BY `category_id`, `language_id`")->rows;
		$parents = array();
		$children = array();
		foreach ($category_rows as $row) {
			$category_id = (int)$row['category_id'];
			$parent_id = (int)$row['parent_id'];
			$parents[$category_id] = $parent_id;
			$children[$parent_id][] = $category_id;
		}

		$manuela_matches = array();
		$emovex_matches = array();
		foreach ($category_names as $row) {
			$category_id = (int)$row['category_id'];
			$name = ' ' . $this->normalizeIdentity($row['name']) . ' ';
			if (strpos($name, ' manuela picard ') !== false) {
				$manuela_matches[$category_id] = true;
			}
			if (strpos($name, ' emovex ') !== false) {
				$emovex_matches[$category_id] = true;
			}
		}

		$manuela_root = $this->resolveSingleCategoryRoot(array_keys($manuela_matches), $parents, 'Manuela Picard');
		$emovex_root = $this->resolveSingleCategoryRoot(array_keys($emovex_matches), $parents, 'Emovex');

		return array(
			'manuela_manufacturer_ids' => $manuela_manufacturers,
			'emovex_manufacturer_ids' => $emovex_manufacturers,
			'manuela_category_root_id' => $manuela_root,
			'emovex_category_root_id' => $emovex_root,
			'manuela_category_ids' => $this->collectCategorySubtree($manuela_root, $children),
			'emovex_category_ids' => $this->collectCategorySubtree($emovex_root, $children),
			'legacy_anomalies' => $this->resolveLegacyAnomalies()
		);
	}

	private function resolveSingleCategoryRoot(array $matches, array $parents, $label) {
		$matches = array_values(array_unique(array_map('intval', $matches)));
		if (!$matches) {
			throw new RuntimeException('Canonical ' . $label . ' category is missing. Price changes are blocked.');
		}
		$match_map = array_fill_keys($matches, true);
		$roots = array();
		foreach ($matches as $category_id) {
			$ancestor = isset($parents[$category_id]) ? (int)$parents[$category_id] : 0;
			$visited = array();
			$has_matched_ancestor = false;
			while ($ancestor > 0 && !isset($visited[$ancestor])) {
				$visited[$ancestor] = true;
				if (isset($match_map[$ancestor])) {
					$has_matched_ancestor = true;
					break;
				}
				$ancestor = isset($parents[$ancestor]) ? (int)$parents[$ancestor] : 0;
			}
			if (!$has_matched_ancestor) {
				$roots[] = $category_id;
			}
		}
		$roots = array_values(array_unique($roots));
		if (count($roots) !== 1) {
			throw new RuntimeException('Canonical ' . $label . ' category is ambiguous. Price changes are blocked.');
		}
		return (int)$roots[0];
	}

	private function collectCategorySubtree($root_id, array $children) {
		$result = array();
		$queue = array((int)$root_id);
		while ($queue) {
			$category_id = array_shift($queue);
			if ($category_id <= 0 || isset($result[$category_id])) {
				continue;
			}
			$result[$category_id] = true;
			if (!empty($children[$category_id])) {
				foreach ($children[$category_id] as $child_id) {
					$queue[] = (int)$child_id;
				}
			}
		}
		return array_keys($result);
	}

	private function resolveLegacyAnomalies() {
		$language = $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language` WHERE `code` = 'hr-hr' ORDER BY `language_id` LIMIT 2");
		if ($language->num_rows !== 1) {
			throw new RuntimeException('The Croatian language identity is missing or ambiguous. Price changes are blocked.');
		}
		$language_id = (int)$language->row['language_id'];
		$result = array();

		foreach ($this->legacy_anomalies as $expected) {
			$matches = $this->db->query("SELECT p.product_id, p.model, p.sku, p.price, p.manufacturer_id, pd.name FROM `" . DB_PREFIX . "product` p INNER JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') WHERE p.model = '" . $this->db->escape($expected['model']) . "' ORDER BY p.product_id")->rows;
			$identity_matches = array();
			foreach ($matches as $row) {
				if ($this->normalizeIdentity($row['name']) === $this->normalizeIdentity($expected['hr_name'])) {
					$identity_matches[] = $row;
				}
			}

			if (count($identity_matches) > 1) {
				throw new RuntimeException('Legacy Manuela Picard product ' . $expected['model'] . ' is ambiguous. Price changes are blocked.');
			}

			if (count($identity_matches) === 1) {
				$product = $identity_matches[0];
			} else {
				$fallback = $this->db->query("SELECT p.product_id, p.model, p.sku, p.price, p.manufacturer_id, pd.name FROM `" . DB_PREFIX . "product` p INNER JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') WHERE p.product_id = '" . (int)$expected['product_id'] . "' LIMIT 1");
				if (!$fallback->num_rows || $fallback->row['model'] !== $expected['model'] || $this->normalizeIdentity($fallback->row['name']) !== $this->normalizeIdentity($expected['hr_name'])) {
					throw new RuntimeException('Legacy Manuela Picard product ' . $expected['model'] . ' is missing or ambiguous. Price changes are blocked.');
				}
				$product = $fallback->row;
			}

			$product['rule_code'] = $expected['rule_code'];
			$product['reason'] = $expected['reason'];
			$product['hr_name'] = $expected['hr_name'];
			$result[] = $product;
		}
		return $result;
	}

	private function upsertManufacturerRule($rule_code, $reason, array $manufacturer_ids) {
		if (!$manufacturer_ids) {
			return;
		}
		$ids = $this->integerList($manufacturer_ids);
		$language_id = (int)$this->config->get('config_language_id');
		$rows = $this->db->query("SELECT p.product_id, p.model, p.sku, p.price, p.manufacturer_id, COALESCE(pd.name, '') AS `name`, COALESCE(m.name, '') AS `manufacturer_name` FROM `" . DB_PREFIX . "product` p LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id) WHERE p.manufacturer_id IN (" . $ids . ")")->rows;
		foreach ($rows as $row) {
			$this->upsertProductExclusion($row, $rule_code, $reason, 'manufacturer', (int)$row['manufacturer_id'], array('manufacturer_name' => $row['manufacturer_name']));
		}
	}

	private function upsertCategoryRule($rule_code, $reason, array $category_ids, $root_id) {
		$ids = $this->integerList($category_ids);
		$language_id = (int)$this->config->get('config_language_id');
		$rows = $this->db->query("SELECT DISTINCT p.product_id, p.model, p.sku, p.price, p.manufacturer_id, COALESCE(pd.name, '') AS `name` FROM `" . DB_PREFIX . "product` p INNER JOIN `" . DB_PREFIX . "product_to_category` ptc ON (ptc.product_id = p.product_id) LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') WHERE ptc.category_id IN (" . $ids . ")")->rows;
		foreach ($rows as $row) {
			$this->upsertProductExclusion($row, $rule_code, $reason, 'category_subtree', (int)$root_id, array('category_root_id' => (int)$root_id, 'category_ids' => array_values(array_map('intval', $category_ids))));
		}
	}

	private function upsertProductExclusion(array $product, $rule_code, $reason, $source_type, $source_id, array $source_snapshot) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_price_exclusion` SET
			`product_id` = '" . max(0, (int)$product['product_id']) . "',
			`rule_code` = '" . $this->db->escape($this->truncate($rule_code, 64)) . "',
			`reason` = '" . $this->db->escape($this->truncate($reason, 255)) . "',
			`source_type` = '" . $this->db->escape($this->truncate($source_type, 32)) . "',
			`source_id` = '" . max(0, (int)$source_id) . "',
			`model_snapshot` = '" . $this->db->escape($this->truncate(isset($product['model']) ? $product['model'] : '', 64)) . "',
			`sku_snapshot` = '" . $this->db->escape($this->truncate(isset($product['sku']) ? $product['sku'] : '', 64)) . "',
			`name_snapshot` = '" . $this->db->escape($this->truncate(isset($product['name']) ? $product['name'] : '', 255)) . "',
			`source_snapshot` = '" . $this->db->escape($this->encodeJson($source_snapshot)) . "',
			`date_added` = NOW(), `date_verified` = NOW()
			ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`), `source_type` = VALUES(`source_type`), `source_id` = VALUES(`source_id`),
			`model_snapshot` = VALUES(`model_snapshot`), `sku_snapshot` = VALUES(`sku_snapshot`), `name_snapshot` = VALUES(`name_snapshot`),
			`source_snapshot` = VALUES(`source_snapshot`), `date_verified` = NOW()");
	}

	private function activeShopFeedJoinSql($alias) {
		if (!$this->hasActiveShopTables()) {
			return '';
		}

		return " LEFT JOIN (
			SELECT sp.product_id, COUNT(*) AS `link_count`, SUM(sp.is_current = '1') AS `current_count`, MAX(s.status) AS `supplier_status`,
				MAX(sp.supplier_product_id) AS `supplier_product_id`, MAX(sp.external_id) AS `external_id`, MAX(sp.feed_price) AS `feed_price`,
				MAX(sp.source_hash) AS `source_hash`, MAX(sp.feed_token) AS `feed_token`, MAX(sp.last_markup) AS `last_markup`,
				MAX(sp.last_calculated_price) AS `last_calculated_price`
			FROM `" . DB_PREFIX . "wob_supplier_product` sp
			INNER JOIN `" . DB_PREFIX . "wob_supplier` s ON (s.supplier_id = sp.supplier_id AND s.code = 'activeshop')
			WHERE sp.product_id > '0'
			GROUP BY sp.product_id
		) " . $alias . " ON (" . $alias . ".product_id = p.product_id)";
	}

	private function validFeedSourceSql($source_alias, $product_alias) {
		return "(" . $source_alias . ".link_count = '1' AND " . $source_alias . ".current_count = '1' AND " . $source_alias . ".supplier_status = '1'
			AND " . $source_alias . ".feed_price > '0.0000' AND " . $source_alias . ".supplier_product_id > '0'
			AND " . $source_alias . ".external_id <> '' AND " . $source_alias . ".external_id = " . $product_alias . ".sku
			AND CHAR_LENGTH(" . $source_alias . ".source_hash) = '64' AND CHAR_LENGTH(" . $source_alias . ".feed_token) = '64')";
	}

	private function priceSourceCasSql($item_alias, $product_alias) {
		if (!$this->hasActiveShopTables()) {
			return $item_alias . ".price_source = '" . self::SOURCE_CATALOG_REGULAR . "'";
		}

		$any_link_sql = "SELECT COUNT(*) FROM `" . DB_PREFIX . "wob_supplier_product` sp_count
			INNER JOIN `" . DB_PREFIX . "wob_supplier` s_count ON (s_count.supplier_id = sp_count.supplier_id AND s_count.code = 'activeshop')
			WHERE sp_count.product_id = " . $product_alias . ".product_id";

		return "((" . $item_alias . ".price_source = '" . self::SOURCE_CATALOG_REGULAR . "' AND (" . $any_link_sql . ") = '0') OR
			(" . $item_alias . ".price_source = '" . self::SOURCE_ACTIVESHOP_FEED . "' AND (" . $any_link_sql . ") = '1' AND EXISTS(
				SELECT 1 FROM `" . DB_PREFIX . "wob_supplier_product` sp_source
				INNER JOIN `" . DB_PREFIX . "wob_supplier` s_source ON (s_source.supplier_id = sp_source.supplier_id AND s_source.code = 'activeshop' AND s_source.status = '1')
				WHERE sp_source.supplier_product_id = " . $item_alias . ".supplier_product_id
				AND sp_source.product_id = " . $product_alias . ".product_id AND sp_source.is_current = '1'
				AND sp_source.external_id = " . $item_alias . ".source_external_id AND sp_source.external_id = " . $product_alias . ".sku
				AND sp_source.feed_price = " . $item_alias . ".feed_price
				AND BINARY sp_source.source_hash = BINARY " . $item_alias . ".source_hash
				AND BINARY sp_source.feed_token = BINARY " . $item_alias . ".feed_token
			)))";
	}

	private function supplierPricingStateSql($item_alias, $state) {
		if (!$this->hasActiveShopTables()) {
			return $item_alias . ".price_source <> '" . self::SOURCE_ACTIVESHOP_FEED . "'";
		}

		$markup_column = $state === 'target' ? 'target_markup' : 'before_markup';
		$calculated_column = $state === 'target' ? 'target_calculated_price' : 'before_calculated_price';
		return "(" . $item_alias . ".price_source <> '" . self::SOURCE_ACTIVESHOP_FEED . "' OR EXISTS(
			SELECT 1 FROM `" . DB_PREFIX . "wob_supplier_product` sp_pricing
			INNER JOIN `" . DB_PREFIX . "wob_supplier` s_pricing ON (s_pricing.supplier_id = sp_pricing.supplier_id AND s_pricing.code = 'activeshop')
			WHERE sp_pricing.supplier_product_id = " . $item_alias . ".supplier_product_id
			AND sp_pricing.product_id = " . $item_alias . ".product_id AND sp_pricing.external_id = " . $item_alias . ".source_external_id
			AND (sp_pricing.last_markup <=> " . $item_alias . "." . $markup_column . ")
			AND (sp_pricing.last_calculated_price <=> " . $item_alias . "." . $calculated_column . ")
		))";
	}

	private function targetPriceSql($column, $percent_sql, $scale = 4) {
		$scale = (int)$scale === 2 ? 2 : 4;
		return "ROUND(" . $column . " * (100.0000 + CAST('" . $percent_sql . "' AS DECIMAL(9,4))) / 100.0000, " . $scale . ")";
	}

	private function normalizePercent($value) {
		$value = str_replace(',', '.', trim((string)$value));
		if ($value === '' || !is_numeric($value)) {
			throw new InvalidArgumentException('Price increase percentage must be numeric.');
		}
		$value = (float)$value;
		if (!is_finite($value) || $value < self::MIN_PERCENT || $value > self::MAX_PERCENT) {
			throw new InvalidArgumentException('Price increase percentage must be between 0.01 and 1000.');
		}
		return round($value, 4);
	}

	private function normalizeIdentity($value) {
		$value = html_entity_decode(trim((string)$value), ENT_QUOTES, 'UTF-8');
		$value = function_exists('utf8_strtolower') ? utf8_strtolower($value) : strtolower($value);
		$value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
		return trim(preg_replace('/\s+/u', ' ', (string)$value));
	}

	private function decorateRun(array $run) {
		$run['exclusions'] = json_decode(isset($run['exclusion_snapshot']) ? $run['exclusion_snapshot'] : '', true);
		if (!is_array($run['exclusions'])) {
			$run['exclusions'] = array();
		}
		foreach (array('run_id', 'user_id', 'rollback_user_id', 'basis_version', 'total_products', 'eligible_count', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard', 'zero_price_count', 'rounded_no_change_count', 'special_count', 'feed_source_count', 'catalog_source_count', 'source_conflict_count', 'updated_count', 'conflict_count', 'failed_count', 'rolled_back_count', 'rollback_conflict_count') as $key) {
			if (isset($run[$key])) {
				$run[$key] = (int)$run[$key];
			}
		}
		// Controller/Twig compatibility aliases. Keep database column names
		// explicit while exposing the concise UI contract.
		$run['price_run_id'] = isset($run['run_id']) ? (int)$run['run_id'] : 0;
		$run['old_total'] = isset($run['before_total']) ? $run['before_total'] : '0.0000';
		$run['new_total'] = isset($run['after_total']) ? $run['after_total'] : '0.0000';
		$run['difference_total'] = isset($run['delta_total']) ? $run['delta_total'] : '0.0000';
		$run['applied_count'] = isset($run['updated_count']) ? (int)$run['updated_count'] : 0;
		$run['legacy_preview'] = (int)$run['basis_version'] !== self::BASIS_VERSION && in_array($run['status'], array(self::STATUS_PREVIEW, self::STATUS_RUNNING), true) && empty($run['rollback_started']);
		$run['can_apply'] = !$run['legacy_preview'] && in_array($run['status'], array(self::STATUS_PREVIEW, self::STATUS_RUNNING), true) && empty($run['rollback_started']);
		$run['can_rollback'] = (in_array($run['status'], array(self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_CONFLICTS, self::STATUS_FAILED), true) && $run['applied_count'] > 0)
			|| ($run['status'] === self::STATUS_RUNNING && !empty($run['rollback_started']))
			|| ($run['status'] === self::STATUS_ROLLBACK_PARTIAL && empty($run['rollback_finished']));
		return $run;
	}

	private function assertInstalled() {
		foreach (array('wob_price_exclusion', 'wob_price_adjustment_run', 'wob_price_adjustment_item') as $table) {
			$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'");
			if (!$query->num_rows) {
				throw new RuntimeException('Global price adjustment module is not installed.');
			}
		}
		$this->ensureSchema();
	}

	private function ensureSchema() {
		if ($this->schema_ready) {
			return;
		}

		$lock_name = $this->lockName() . '_schema';
		$lock = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lock_name) . "', 5) AS `acquired`");
		if (!$lock->num_rows || (int)$lock->row['acquired'] !== 1) {
			throw new RuntimeException('Unable to acquire the global price schema upgrade lock.');
		}

		try {
			$run_table = DB_PREFIX . 'wob_price_adjustment_run';
			$item_table = DB_PREFIX . 'wob_price_adjustment_item';
			$this->ensureColumn($run_table, 'basis_version', "TINYINT UNSIGNED NOT NULL DEFAULT '0' AFTER `percent`");
			$this->ensureColumn($run_table, 'feed_source_count', "INT UNSIGNED NOT NULL DEFAULT '0' AFTER `special_count`");
			$this->ensureColumn($run_table, 'catalog_source_count', "INT UNSIGNED NOT NULL DEFAULT '0' AFTER `feed_source_count`");
			$this->ensureColumn($run_table, 'source_conflict_count', "INT UNSIGNED NOT NULL DEFAULT '0' AFTER `catalog_source_count`");
			$this->ensureColumn($run_table, 'base_total', "DECIMAL(20,4) NOT NULL DEFAULT '0.0000' AFTER `before_total`");

			$this->ensureColumn($item_table, 'base_price', "DECIMAL(15,4) NOT NULL DEFAULT '0.0000' AFTER `before_price`");
			$this->ensureColumn($item_table, 'price_source', "VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER `base_price`");
			$this->ensureColumn($item_table, 'feed_price', "DECIMAL(15,4) DEFAULT NULL AFTER `price_source`");
			$this->ensureColumn($item_table, 'supplier_product_id', "BIGINT UNSIGNED NOT NULL DEFAULT '0' AFTER `feed_price`");
			$this->ensureColumn($item_table, 'source_external_id', "VARCHAR(128) NOT NULL DEFAULT '' AFTER `supplier_product_id`");
			$this->ensureColumn($item_table, 'source_hash', "CHAR(64) NOT NULL DEFAULT '' AFTER `source_external_id`");
			$this->ensureColumn($item_table, 'feed_token', "CHAR(64) NOT NULL DEFAULT '' AFTER `source_hash`");
			$this->ensureColumn($item_table, 'before_markup', "DECIMAL(9,4) DEFAULT NULL AFTER `feed_token`");
			$this->ensureColumn($item_table, 'target_markup', "DECIMAL(9,4) DEFAULT NULL AFTER `before_markup`");
			$this->ensureColumn($item_table, 'before_calculated_price', "DECIMAL(15,4) DEFAULT NULL AFTER `target_markup`");
			$this->ensureColumn($item_table, 'target_calculated_price', "DECIMAL(15,4) DEFAULT NULL AFTER `before_calculated_price`");
			$this->schema_ready = true;
		} finally {
			$this->releaseLock($lock_name);
		}
	}

	private function ensureColumn($table, $column, $definition) {
		$query = $this->db->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $this->db->escape($column) . "'");
		if (!$query->num_rows) {
			$this->db->query("ALTER TABLE `" . $table . "` ADD `" . $column . "` " . $definition);
		}
	}

	private function hasActiveShopTables() {
		if ($this->activeshop_tables_available === null) {
			$this->activeshop_tables_available = $this->tableExists(DB_PREFIX . 'wob_supplier') && $this->tableExists(DB_PREFIX . 'wob_supplier_product');
		}
		return $this->activeshop_tables_available;
	}

	private function tableExists($table) {
		$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");
		return (bool)$query->num_rows;
	}

	private function quotedList(array $values) {
		if (!$values) {
			throw new InvalidArgumentException('A non-empty SQL value list is required.');
		}
		return implode(',', array_map(function ($value) {
			return "'" . $this->db->escape((string)$value) . "'";
		}, $values));
	}

	private function acquireLock() {
		$name = $this->lockName();
		$query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($name) . "', 0) AS `acquired`");
		if (!$query->num_rows || (int)$query->row['acquired'] !== 1) {
			throw new RuntimeException('Another global price operation is already running.');
		}
		return $name;
	}

	private function releaseLock($name) {
		if ($name !== '') {
			try {
				$this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($name) . "')");
			} catch (Throwable $exception) {
				// Connection-scoped locks are released automatically if the DB
				// connection has already failed or closed.
			}
		}
	}

	private function lockName() {
		$database = defined('DB_DATABASE') ? DB_DATABASE : 'opencart';
		return 'wob_price_' . substr(hash('sha256', $database . '|' . DB_PREFIX), 0, 40);
	}

	private function integerList(array $values) {
		$values = array_values(array_unique(array_filter(array_map('intval', $values), function ($value) { return $value > 0; })));
		if (!$values) {
			throw new RuntimeException('A required exclusion identity has no valid IDs. Price changes are blocked.');
		}
		return implode(',', $values);
	}

	private function encodeJson($value) {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new RuntimeException('Unable to encode price adjustment audit data.');
		}
		return $json;
	}

	private function decimal($value, $scale) {
		$value = is_numeric($value) ? (float)$value : 0;
		return number_format($value, $scale, '.', '');
	}

	private function truncate($value, $length) {
		$value = (string)$value;
		if (function_exists('utf8_strlen') && function_exists('utf8_substr')) {
			return utf8_strlen($value) > $length ? utf8_substr($value, 0, $length) : $value;
		}
		return strlen($value) > $length ? substr($value, 0, $length) : $value;
	}
}
