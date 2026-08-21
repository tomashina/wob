-- World of Beauty: ActiveShop importer + global price adjustment
-- Live installation migration, 2026-08-21
--
-- IMPORTANT:
--   1. Deploy the matching application files before running this migration.
--   2. This file uses the OpenCart table prefix `oc_`. Replace every `oc_`
--      below if the live shop uses a different DB_PREFIX.
--   3. Take a database backup before any live deployment.
--   4. The Administrator-permission block uses JSON functions available in
--      MySQL 5.7+/8.0 (the test shop runs MySQL 8.0.33).
--   5. After installation, copy the authenticated HTTP cron URL from the
--      ActiveShop module Settings page. A web call needs `?key=...`; it is a
--      dry run unless the URL also contains `&mode=live`.
--
-- Safety: this migration does not insert, update, or delete catalog products,
-- prices, specials, orders, customers, staged feed products, import audit
-- items, or price-adjustment runs. It installs only module schema/metadata,
-- one supplier definition, defaults, menu events, and Administrator routes.

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- ActiveShop importer schema
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `oc_wob_supplier` (
  `supplier_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT '1',
  `date_added` DATETIME NOT NULL,
  `date_modified` DATETIME NOT NULL,
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_supplier_product` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_supplier_category_map` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_import_run` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_import_item` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The only business-data seed in this migration. Re-running updates the
-- supplier label/status without creating a duplicate.
INSERT INTO `oc_wob_supplier`
  (`code`, `name`, `status`, `date_added`, `date_modified`)
VALUES
  ('activeshop', 'ActiveShop', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `status` = 1,
  `date_modified` = NOW();

-- --------------------------------------------------------------------------
-- Global price-adjustment schema
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `oc_wob_price_exclusion` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_price_adjustment_run` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oc_wob_price_adjustment_item` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent upgrade for shops where the first version of the global-price
-- audit tables already exists. Legacy previews retain basis_version=0 and the
-- matching PHP code refuses to apply them; completed runs remain rollbackable.
DROP PROCEDURE IF EXISTS `wob_add_column_if_missing`;
DELIMITER $$
CREATE PROCEDURE `wob_add_column_if_missing`(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @wob_column_ddl = CONCAT(
      'ALTER TABLE `', REPLACE(p_table, '`', '``'),
      '` ADD COLUMN `', REPLACE(p_column, '`', '``'), '` ',
      p_definition
    );
    PREPARE wob_column_statement FROM @wob_column_ddl;
    EXECUTE wob_column_statement;
    DEALLOCATE PREPARE wob_column_statement;
  END IF;
END$$
DELIMITER ;

CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_run', 'basis_version', 'TINYINT UNSIGNED NOT NULL DEFAULT ''0'' AFTER `percent`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_run', 'feed_source_count', 'INT UNSIGNED NOT NULL DEFAULT ''0'' AFTER `special_count`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_run', 'catalog_source_count', 'INT UNSIGNED NOT NULL DEFAULT ''0'' AFTER `feed_source_count`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_run', 'source_conflict_count', 'INT UNSIGNED NOT NULL DEFAULT ''0'' AFTER `catalog_source_count`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_run', 'base_total', 'DECIMAL(20,4) NOT NULL DEFAULT ''0.0000'' AFTER `before_total`');

CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'base_price', 'DECIMAL(15,4) NOT NULL DEFAULT ''0.0000'' AFTER `before_price`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'price_source', 'VARCHAR(32) NOT NULL DEFAULT ''legacy'' AFTER `base_price`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'feed_price', 'DECIMAL(15,4) DEFAULT NULL AFTER `price_source`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'supplier_product_id', 'BIGINT UNSIGNED NOT NULL DEFAULT ''0'' AFTER `feed_price`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'source_external_id', 'VARCHAR(128) NOT NULL DEFAULT '''' AFTER `supplier_product_id`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'source_hash', 'CHAR(64) NOT NULL DEFAULT '''' AFTER `source_external_id`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'feed_token', 'CHAR(64) NOT NULL DEFAULT '''' AFTER `source_hash`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'before_markup', 'DECIMAL(9,4) DEFAULT NULL AFTER `feed_token`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'target_markup', 'DECIMAL(9,4) DEFAULT NULL AFTER `before_markup`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'before_calculated_price', 'DECIMAL(15,4) DEFAULT NULL AFTER `target_markup`');
CALL `wob_add_column_if_missing`('oc_wob_price_adjustment_item', 'target_calculated_price', 'DECIMAL(15,4) DEFAULT NULL AFTER `before_calculated_price`');

DROP PROCEDURE `wob_add_column_if_missing`;

-- Exclusion rows are intentionally not hard-coded here. On the first visit to
-- the module, its fail-closed PHP identity checks discover and persist Emovex
-- and Manuela Picard exclusions from the live catalog before any preview can
-- be created.

-- --------------------------------------------------------------------------
-- OpenCart extension registrations
-- --------------------------------------------------------------------------

INSERT INTO `oc_extension` (`type`, `code`)
SELECT 'module', 'activeshop_importer'
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_extension`
  WHERE `type` = 'module' AND `code` = 'activeshop_importer'
);

INSERT INTO `oc_extension` (`type`, `code`)
SELECT 'module', 'global_price_adjustment'
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_extension`
  WHERE `type` = 'module' AND `code` = 'global_price_adjustment'
);

-- The old dashboard extension is superseded by the thin shortcut strip in
-- common/dashboard. These deletes mirror the ActiveShop installer cleanup.
DELETE FROM `oc_extension`
WHERE `type` = 'dashboard' AND `code` = 'activeshop_importer';

DELETE FROM `oc_setting`
WHERE `code` = 'dashboard_activeshop_importer';

-- --------------------------------------------------------------------------
-- Module defaults (insert missing keys only; preserve live configuration)
-- --------------------------------------------------------------------------

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_status', '1', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_status'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_markup', '0', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_markup'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_default_category_id',
       COALESCE((
         SELECT CAST(MIN(`category_id`) AS CHAR)
         FROM `oc_category_description`
         WHERE LOWER(`name`) = 'novo privremeno'
       ), '0'), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_default_category_id'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_tax_class_id',
       IF(EXISTS(SELECT 1 FROM `oc_tax_class` WHERE `tax_class_id` = 11), '11', '0'), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_tax_class_id'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_stock_status_id',
       IF(EXISTS(SELECT 1 FROM `oc_stock_status` WHERE `stock_status_id` = 5), '5', '0'), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_stock_status_id'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_weight_class_id',
       IF(EXISTS(SELECT 1 FROM `oc_weight_class` WHERE `weight_class_id` = 1), '1', '0'), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_weight_class_id'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_new_product_status', '0', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_new_product_status'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_import_images', '1', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_import_images'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_existing_action', 'skip', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_existing_action'
);

-- Keep an existing strong cron key. Replace only a missing/short value; no key
-- value is hard-coded in this migration or printed by its verification output.
UPDATE `oc_setting`
SET `value` = LOWER(HEX(RANDOM_BYTES(32))),
    `serialized` = 0
WHERE `store_id` = 0
  AND `code` = 'module_activeshop_importer'
  AND `key` = 'module_activeshop_importer_cron_key'
  AND CHAR_LENGTH(TRIM(`value`)) < 32;

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_activeshop_importer', 'module_activeshop_importer_cron_key',
       LOWER(HEX(RANDOM_BYTES(32))), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_activeshop_importer'
    AND `key` = 'module_activeshop_importer_cron_key'
);

INSERT INTO `oc_setting` (`store_id`, `code`, `key`, `value`, `serialized`)
SELECT 0, 'module_global_price_adjustment', 'module_global_price_adjustment_status', '1', 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_setting`
  WHERE `store_id` = 0
    AND `code` = 'module_global_price_adjustment'
    AND `key` = 'module_global_price_adjustment_status'
);

-- --------------------------------------------------------------------------
-- Admin sidebar events (update existing rows, insert only if missing)
-- --------------------------------------------------------------------------

UPDATE `oc_event`
SET `trigger` = 'admin/view/common/column_left/before',
    `action` = 'extension/module/activeshop_importer/menu',
    `status` = 1,
    `sort_order` = 0
WHERE `code` = 'wob_activeshop_importer_menu';

INSERT INTO `oc_event` (`code`, `trigger`, `action`, `status`, `sort_order`)
SELECT 'wob_activeshop_importer_menu',
       'admin/view/common/column_left/before',
       'extension/module/activeshop_importer/menu',
       1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_event`
  WHERE `code` = 'wob_activeshop_importer_menu'
);

UPDATE `oc_event`
SET `trigger` = 'admin/view/common/column_left/before',
    `action` = 'extension/module/global_price_adjustment/menu',
    `status` = 1,
    `sort_order` = 0
WHERE `code` = 'wob_global_price_adjustment_menu';

INSERT INTO `oc_event` (`code`, `trigger`, `action`, `status`, `sort_order`)
SELECT 'wob_global_price_adjustment_menu',
       'admin/view/common/column_left/before',
       'extension/module/global_price_adjustment/menu',
       1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `oc_event`
  WHERE `code` = 'wob_global_price_adjustment_menu'
);

-- --------------------------------------------------------------------------
-- Administrator permissions
-- --------------------------------------------------------------------------
-- The block deliberately touches only a valid JSON object belonging to the
-- group named exactly "Administrator". Invalid/nonstandard permission data is
-- left unchanged and will be reported by the verification query at the end.

UPDATE `oc_user_group`
SET `permission` = JSON_SET(
  `permission`,
  '$.access',
  CASE
    WHEN JSON_TYPE(JSON_EXTRACT(`permission`, '$.access')) = 'ARRAY'
      THEN JSON_EXTRACT(`permission`, '$.access')
    WHEN JSON_EXTRACT(`permission`, '$.access') IS NULL
      THEN JSON_ARRAY()
    ELSE JSON_EXTRACT(`permission`, '$.access')
  END,
  '$.modify',
  CASE
    WHEN JSON_TYPE(JSON_EXTRACT(`permission`, '$.modify')) = 'ARRAY'
      THEN JSON_EXTRACT(`permission`, '$.modify')
    WHEN JSON_EXTRACT(`permission`, '$.modify') IS NULL
      THEN JSON_ARRAY()
    ELSE JSON_EXTRACT(`permission`, '$.modify')
  END
)
WHERE `name` = 'Administrator'
  AND JSON_VALID(`permission`)
  AND JSON_TYPE(JSON_EXTRACT(
        CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
        END,
        '$'
      )) = 'OBJECT';

UPDATE `oc_user_group`
SET `permission` = JSON_ARRAY_APPEND(
  `permission`, '$.access', 'extension/module/activeshop_importer'
)
WHERE `name` = 'Administrator'
  AND JSON_VALID(`permission`)
  AND JSON_TYPE(JSON_EXTRACT(
        CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
        END,
        '$.access'
      )) = 'ARRAY'
  AND JSON_CONTAINS(
        JSON_EXTRACT(
          CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
          END,
          '$.access'
        ),
        JSON_QUOTE('extension/module/activeshop_importer')
      ) = 0;

UPDATE `oc_user_group`
SET `permission` = JSON_ARRAY_APPEND(
  `permission`, '$.modify', 'extension/module/activeshop_importer'
)
WHERE `name` = 'Administrator'
  AND JSON_VALID(`permission`)
  AND JSON_TYPE(JSON_EXTRACT(
        CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
        END,
        '$.modify'
      )) = 'ARRAY'
  AND JSON_CONTAINS(
        JSON_EXTRACT(
          CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
          END,
          '$.modify'
        ),
        JSON_QUOTE('extension/module/activeshop_importer')
      ) = 0;

UPDATE `oc_user_group`
SET `permission` = JSON_ARRAY_APPEND(
  `permission`, '$.access', 'extension/module/global_price_adjustment'
)
WHERE `name` = 'Administrator'
  AND JSON_VALID(`permission`)
  AND JSON_TYPE(JSON_EXTRACT(
        CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
        END,
        '$.access'
      )) = 'ARRAY'
  AND JSON_CONTAINS(
        JSON_EXTRACT(
          CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
          END,
          '$.access'
        ),
        JSON_QUOTE('extension/module/global_price_adjustment')
      ) = 0;

UPDATE `oc_user_group`
SET `permission` = JSON_ARRAY_APPEND(
  `permission`, '$.modify', 'extension/module/global_price_adjustment'
)
WHERE `name` = 'Administrator'
  AND JSON_VALID(`permission`)
  AND JSON_TYPE(JSON_EXTRACT(
        CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
        END,
        '$.modify'
      )) = 'ARRAY'
  AND JSON_CONTAINS(
        JSON_EXTRACT(
          CASE WHEN JSON_VALID(`permission`) THEN `permission` ELSE '{}'
          END,
          '$.modify'
        ),
        JSON_QUOTE('extension/module/global_price_adjustment')
      ) = 0;

-- --------------------------------------------------------------------------
-- Read-only deployment verification
-- --------------------------------------------------------------------------

SELECT `type`, `code`
FROM `oc_extension`
WHERE (`type` = 'module' AND `code` IN (
  'activeshop_importer', 'global_price_adjustment'
))
ORDER BY `code`;

SELECT `code`, `trigger`, `action`, `status`
FROM `oc_event`
WHERE `code` IN (
  'wob_activeshop_importer_menu', 'wob_global_price_adjustment_menu'
)
ORDER BY `code`;

SELECT
  COUNT(*) AS `cron_key_rows`,
  COALESCE(MIN(CHAR_LENGTH(TRIM(`value`))), 0) AS `cron_key_min_length`,
  COALESCE(MAX(CHAR_LENGTH(TRIM(`value`))), 0) AS `cron_key_max_length`
FROM `oc_setting`
WHERE `store_id` = 0
  AND `code` = 'module_activeshop_importer'
  AND `key` = 'module_activeshop_importer_cron_key';

SELECT
  `user_group_id`,
  `name`,
  JSON_VALID(`permission`) AS `permission_json_valid`,
  CASE WHEN JSON_VALID(`permission`) THEN COALESCE(JSON_CONTAINS(
    JSON_EXTRACT(`permission`, '$.access'),
    JSON_QUOTE('extension/module/activeshop_importer')
  ), 0) ELSE 0 END AS `activeshop_access`,
  CASE WHEN JSON_VALID(`permission`) THEN COALESCE(JSON_CONTAINS(
    JSON_EXTRACT(`permission`, '$.modify'),
    JSON_QUOTE('extension/module/activeshop_importer')
  ), 0) ELSE 0 END AS `activeshop_modify`,
  CASE WHEN JSON_VALID(`permission`) THEN COALESCE(JSON_CONTAINS(
    JSON_EXTRACT(`permission`, '$.access'),
    JSON_QUOTE('extension/module/global_price_adjustment')
  ), 0) ELSE 0 END AS `global_price_access`,
  CASE WHEN JSON_VALID(`permission`) THEN COALESCE(JSON_CONTAINS(
    JSON_EXTRACT(`permission`, '$.modify'),
    JSON_QUOTE('extension/module/global_price_adjustment')
  ), 0) ELSE 0 END AS `global_price_modify`
FROM `oc_user_group`
WHERE `name` = 'Administrator';
