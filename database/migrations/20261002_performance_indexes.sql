-- World of Beauty / OpenCart 3.0.3.8
-- Indexes for the most frequent catalog and maintenance lookups.
-- Safe to run repeatedly on the production schema.

SET @wob_performance_schema := DATABASE();

SET @wob_performance_sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @wob_performance_schema
      AND TABLE_NAME = 'oc_category_path'
      AND INDEX_NAME = 'idx_path_category'
  ),
  'DO 0',
  'ALTER TABLE `oc_category_path` ADD INDEX `idx_path_category` (`path_id`, `category_id`)'
);
PREPARE wob_performance_stmt FROM @wob_performance_sql;
EXECUTE wob_performance_stmt;
DEALLOCATE PREPARE wob_performance_stmt;

SET @wob_performance_sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @wob_performance_schema
      AND TABLE_NAME = 'oc_cart'
      AND INDEX_NAME = 'idx_date_added'
  ),
  'DO 0',
  'ALTER TABLE `oc_cart` ADD INDEX `idx_date_added` (`date_added`)'
);
PREPARE wob_performance_stmt FROM @wob_performance_sql;
EXECUTE wob_performance_stmt;
DEALLOCATE PREPARE wob_performance_stmt;

SET @wob_performance_sql := NULL;
SET @wob_performance_schema := NULL;
