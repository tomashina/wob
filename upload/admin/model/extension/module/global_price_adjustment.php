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
			`status` VARCHAR(32) NOT NULL DEFAULT 'preview',
			`total_products` INT UNSIGNED NOT NULL DEFAULT '0',
			`eligible_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_total` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_emovex` INT UNSIGNED NOT NULL DEFAULT '0',
			`excluded_manuela_picard` INT UNSIGNED NOT NULL DEFAULT '0',
			`zero_price_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`rounded_no_change_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`special_count` INT UNSIGNED NOT NULL DEFAULT '0',
			`before_total` DECIMAL(20,4) NOT NULL DEFAULT '0.0000',
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
		$target_sql = $this->targetPriceSql('p.price', $percent_sql);
		$language_id = (int)$this->config->get('config_language_id');

		$lock = $this->acquireLock();
		try {
			$exclusion_snapshot = $this->syncExclusionsUnsafe();

			$overflow = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product` p WHERE p.price > '0.0000' AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND " . $target_sql . " > '99999999999.9999'");
			if ((int)$overflow->row['total'] > 0) {
				throw new OverflowException('The selected percentage would exceed the OpenCart price column limit.');
			}

			$this->db->query('START TRANSACTION');
			try {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_run` SET
					`user_id` = '" . $user_id . "', `percent` = '" . $percent_sql . "', `status` = '" . self::STATUS_PREVIEW . "',
					`exclusion_snapshot` = '" . $this->db->escape($this->encodeJson($exclusion_snapshot)) . "', `error` = '', `date_created` = NOW()");
				$run_id = (int)$this->db->getLastId();

				$this->db->query("INSERT INTO `" . DB_PREFIX . "wob_price_adjustment_item`
					(`run_id`, `product_id`, `manufacturer_id`, `manufacturer_name`, `model`, `sku`, `product_name`, `before_price`, `target_price`, `had_special`, `status`, `message`, `date_created`, `date_modified`)
					SELECT '" . $run_id . "', p.product_id, p.manufacturer_id, COALESCE(m.name, ''), p.model, p.sku, COALESCE(pd.name, ''), p.price, " . $target_sql . ",
					EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1),
					'" . self::ITEM_PREVIEW . "', '', NOW(), NOW()
					FROM `" . DB_PREFIX . "product` p
					LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)
					LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "')
					WHERE p.price > '0.0000'
					AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)
					AND " . $target_sql . " <> p.price");

				$summary = $this->getCurrentSummaryUnsafe();
				$item_summary = $this->db->query("SELECT COUNT(*) AS `eligible_count`, COALESCE(SUM(`before_price`), 0) AS `before_total`, COALESCE(SUM(`target_price`), 0) AS `after_total`, COALESCE(SUM(`target_price` - `before_price`), 0) AS `delta_total`, COALESCE(SUM(`had_special` = '1'), 0) AS `special_count` FROM `" . DB_PREFIX . "wob_price_adjustment_item` WHERE `run_id` = '" . $run_id . "'")->row;
				$rounded = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product` p WHERE p.price > '0.0000' AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND " . $target_sql . " = p.price")->row;

				$this->db->query("UPDATE `" . DB_PREFIX . "wob_price_adjustment_run` SET
					`total_products` = '" . (int)$summary['total_products'] . "',
					`eligible_count` = '" . (int)$item_summary['eligible_count'] . "',
					`excluded_total` = '" . (int)$summary['excluded_total'] . "',
					`excluded_emovex` = '" . (int)$summary['excluded_emovex'] . "',
					`excluded_manuela_picard` = '" . (int)$summary['excluded_manuela_picard'] . "',
					`zero_price_count` = '" . (int)$summary['zero_price_count'] . "',
					`rounded_no_change_count` = '" . (int)$rounded['total'] . "',
					`special_count` = '" . (int)$item_summary['special_count'] . "',
					`before_total` = '" . $this->decimal($item_summary['before_total'], 4) . "',
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
		if (!empty($current['is_target'])) {
			if ($is_recovery) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Already at the target price; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Target price was reached outside this run before its update attempt.');
			}
			return;
		}
		if (empty($current['is_before'])) {
			$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Price changed after preview.');
			return;
		}

		try {
			if (!$is_recovery) {
				$this->markItem($item_id, self::ITEM_APPLYING, $current['current_price'], 'CAS update prepared.');
			}
			$this->db->query("UPDATE `" . DB_PREFIX . "product` p
				INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.product_id = p.product_id)
				SET p.price = i.target_price, p.date_modified = NOW()
				WHERE i.item_id = '" . $item_id . "' AND i.status = '" . self::ITEM_APPLYING . "'
				AND p.price = i.before_price AND p.manufacturer_id = i.manufacturer_id
				AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)");

			if ((int)$this->db->countAffected() === 1) {
				$this->markItem($item_id, self::ITEM_UPDATED, $item['target_price'], '');
				return;
			}

			$current = $this->getCurrentItemState($item_id);
			if ($current && !empty($current['is_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Already at the target price; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_CONFLICT, $current ? $current['current_price'] : null, 'Compare-and-swap update was not applied.');
			}
		} catch (Throwable $exception) {
			$after_error = $this->getCurrentItemState($item_id);
			if ($after_error && !empty($after_error['is_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $after_error['current_price'], 'Update completed before an error response; recovered idempotently.');
			} else {
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
		if (!empty($current['is_before'])) {
			if ($is_recovery || $apply_was_in_flight) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $current['current_price'], 'Already restored; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current['current_price'], 'Price was already changed outside this rollback.');
			}
			return;
		}
		if (empty($current['is_target'])) {
			$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current['current_price'], 'Current price differs from the price written by this run.');
			return;
		}

		try {
			if (!$is_recovery) {
				$this->markItem($item_id, self::ITEM_ROLLING_BACK, $current['current_price'], 'CAS rollback prepared.');
			}
			$this->db->query("UPDATE `" . DB_PREFIX . "product` p
				INNER JOIN `" . DB_PREFIX . "wob_price_adjustment_item` i ON (i.product_id = p.product_id)
				SET p.price = i.before_price, p.date_modified = NOW()
				WHERE i.item_id = '" . $item_id . "' AND i.status = '" . self::ITEM_ROLLING_BACK . "' AND p.price = i.target_price
				AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)");
			if ((int)$this->db->countAffected() === 1) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $item['before_price'], '');
				return;
			}

			$current = $this->getCurrentItemState($item_id);
			if ($current && !empty($current['is_before'])) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $current['current_price'], 'Already restored; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $current ? $current['current_price'] : null, 'Compare-and-swap rollback was not applied.');
			}
		} catch (Throwable $exception) {
			$after_error = $this->getCurrentItemState($item_id);
			if ($after_error && !empty($after_error['is_before'])) {
				$this->markItem($item_id, self::ITEM_ROLLED_BACK, $after_error['current_price'], 'Rollback completed before an error response; recovered idempotently.');
			} else {
				$this->markItem($item_id, self::ITEM_ROLLBACK_CONFLICT, $after_error ? $after_error['current_price'] : $current['current_price'], $exception->getMessage());
			}
		}
	}

	private function getCurrentItemState($item_id) {
		$query = $this->db->query("SELECT i.item_id, p.product_id AS `product_exists`, p.price AS `current_price`, p.manufacturer_id AS `current_manufacturer_id`,
			(p.price = i.before_price) AS `is_before`, (p.price = i.target_price) AS `is_target`, (p.manufacturer_id = i.manufacturer_id) AS `is_same_manufacturer`,
			EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AS `is_excluded`
			FROM `" . DB_PREFIX . "wob_price_adjustment_item` i LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = i.product_id)
			WHERE i.item_id = '" . max(0, (int)$item_id) . "' LIMIT 1");
		return $query->num_rows ? $query->row : array();
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
			if (!$current || empty($current['product_exists'])) {
				$this->markItem($item_id, self::ITEM_CONFLICT, null, 'Product disappeared while the interrupted update was being reconciled.');
			} elseif (!empty($current['is_target'])) {
				$this->markItem($item_id, self::ITEM_UPDATED, $current['current_price'], 'Interrupted update completed; reconciled from the catalog price.');
			} elseif (!empty($current['is_before'])) {
				$this->markItem($item_id, self::ITEM_FAILED, $current['current_price'], 'Interrupted before the catalog price changed.');
			} else {
				$this->markItem($item_id, self::ITEM_CONFLICT, $current['current_price'], 'Catalog price changed during the interrupted update.');
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
		$query = $this->db->query("SELECT
			COUNT(*) AS `total_products`,
			COALESCE(SUM(p.price > '0.0000' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `eligible_count`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `excluded_total`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'emovex_%')), 0) AS `excluded_emovex`,
			COALESCE(SUM(EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id AND e.rule_code LIKE 'manuela_picard_%')), 0) AS `excluded_manuela_picard`,
			COALESCE(SUM(p.price <= '0.0000' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `zero_price_count`,
			COALESCE(SUM(p.price > '0.0000' AND p.status = '1' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `eligible_enabled`,
			COALESCE(SUM(p.price > '0.0000' AND p.status = '0' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `eligible_disabled`,
			COALESCE(SUM(p.price > '0.0000' AND p.manufacturer_id = '0' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id)), 0) AS `eligible_without_supplier`,
			COALESCE(SUM(CASE WHEN p.price > '0.0000' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) THEN p.price ELSE 0 END), 0) AS `current_total`,
			COALESCE(SUM(p.price > '0.0000' AND NOT EXISTS(SELECT 1 FROM `" . DB_PREFIX . "wob_price_exclusion` e WHERE e.product_id = p.product_id) AND EXISTS(SELECT 1 FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id LIMIT 1)), 0) AS `special_count`
			FROM `" . DB_PREFIX . "product` p");
		$row = $query->row;
		foreach (array('total_products', 'eligible_count', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard', 'zero_price_count', 'eligible_enabled', 'eligible_disabled', 'eligible_without_supplier', 'special_count') as $key) {
			$row[$key] = (int)$row[$key];
		}
		$row['current_total'] = $this->decimal($row['current_total'], 4);
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

	private function targetPriceSql($column, $percent_sql) {
		return "ROUND(" . $column . " * (100.0000 + CAST('" . $percent_sql . "' AS DECIMAL(9,4))) / 100.0000, 4)";
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
		foreach (array('run_id', 'user_id', 'rollback_user_id', 'total_products', 'eligible_count', 'excluded_total', 'excluded_emovex', 'excluded_manuela_picard', 'zero_price_count', 'rounded_no_change_count', 'special_count', 'updated_count', 'conflict_count', 'failed_count', 'rolled_back_count', 'rollback_conflict_count') as $key) {
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
		$run['can_apply'] = in_array($run['status'], array(self::STATUS_PREVIEW, self::STATUS_RUNNING), true) && empty($run['rollback_started']);
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
