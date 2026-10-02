-- World of Beauty / OpenCart 3.0.3.8
-- Sidrena cijena i referentni datum proizvoda.
-- Skripta je idempotentna i može se sigurno pokrenuti više puta.
-- Projekt koristi prefiks tablica `oc_`; prilagodite ga ako je na live bazi drukčiji.

SET @wob_schema := DATABASE();
SET @wob_old_sql_mode := @@SESSION.sql_mode;
SET @wob_sql_mode := CONCAT(',', @@SESSION.sql_mode, ',');
SET @wob_sql_mode := REPLACE(@wob_sql_mode, ',NO_ZERO_IN_DATE,', ',');
SET @wob_sql_mode := REPLACE(@wob_sql_mode, ',NO_ZERO_DATE,', ',');
SET SESSION sql_mode = TRIM(BOTH ',' FROM @wob_sql_mode);

SET @wob_had_anchor_price := EXISTS(
  SELECT 1
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = @wob_schema
    AND `TABLE_NAME` = 'oc_product'
    AND `COLUMN_NAME` = 'anchor_price'
);

SET @wob_had_anchor_price_date := EXISTS(
  SELECT 1
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = @wob_schema
    AND `TABLE_NAME` = 'oc_product'
    AND `COLUMN_NAME` = 'anchor_price_date'
);

SET @wob_sql := IF(
  @wob_had_anchor_price,
  'DO 0',
  'ALTER TABLE `oc_product` ADD COLUMN `anchor_price` decimal(15,4) NOT NULL DEFAULT 0.0000 AFTER `price`'
);
PREPARE wob_stmt FROM @wob_sql;
EXECUTE wob_stmt;
DEALLOCATE PREPARE wob_stmt;

SET @wob_sql := IF(
  @wob_had_anchor_price_date,
  'DO 0',
  'ALTER TABLE `oc_product` ADD COLUMN `anchor_price_date` date NULL DEFAULT NULL AFTER `anchor_price`'
);
PREPARE wob_stmt FROM @wob_sql;
EXECUTE wob_stmt;
DEALLOCATE PREPARE wob_stmt;

-- Povijesne vrijednosti namjerno se ne izvode iz današnje `product.price`.
-- Nakon migracije unesite provjerenu cijenu i pripadajući referentni datum
-- kroz obrazac proizvoda ili CSV uvoz. Trgovina ne prikazuje prazne vrijednosti.

SET @wob_sql := NULL;
SET @wob_schema := NULL;
SET SESSION sql_mode = @wob_old_sql_mode;
SET @wob_old_sql_mode := NULL;
SET @wob_sql_mode := NULL;
SET @wob_had_anchor_price := NULL;
SET @wob_had_anchor_price_date := NULL;
