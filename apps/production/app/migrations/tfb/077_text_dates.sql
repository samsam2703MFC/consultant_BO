
-- Nettoyage de la base 4/7 : les 8 dates des documents stockées en texte deviennent des DATE.
-- Format déjà AAAA-MM-JJ partout, sauf les proformas (AAAA/MM/JJ, converties). Une valeur vide ou illisible devient
-- NULL (aucune dans la copie du 19.10.2026). Colonnes NULL autorisé : un INSERT qui les omet ne casse pas.
-- Les dates relues gardent le même texte (AAAA-MM-JJ) : PDF, KSeF et e-mails inchangés ; proformas : 2026-10-19
-- au lieu de 2026/10/19.

-- index préfixe de 019 (établi sur le texte) : recréé après la conversion
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND INDEX_NAME = 'idx_document_wdt_established') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND DATA_TYPE <> 'date') = 1),
    'ALTER TABLE `document_wdt` DROP INDEX `idx_document_wdt_established`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_cmr.established_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_cmr` MODIFY `established_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_cmr` SET `established_date` = NULLIF(REPLACE(REPLACE(TRIM(`established_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `established_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_cmr` SET `established_date` = NULL WHERE `established_date` IS NOT NULL AND `established_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_cmr` MODIFY `established_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_invoice.invoiced_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_invoice` MODIFY `invoiced_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_invoice` SET `invoiced_date` = NULLIF(REPLACE(REPLACE(TRIM(`invoiced_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `invoiced_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_invoice` SET `invoiced_date` = NULL WHERE `invoiced_date` IS NOT NULL AND `invoiced_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_invoice` MODIFY `invoiced_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_order_shipping_difference.established_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_order_shipping_difference` MODIFY `established_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_order_shipping_difference` SET `established_date` = NULLIF(REPLACE(REPLACE(TRIM(`established_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `established_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_order_shipping_difference` SET `established_date` = NULL WHERE `established_date` IS NOT NULL AND `established_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_order_shipping_difference` MODIFY `established_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_proforma.established_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_proforma` MODIFY `established_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_proforma` SET `established_date` = NULLIF(REPLACE(REPLACE(TRIM(`established_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `established_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_proforma` SET `established_date` = NULL WHERE `established_date` IS NOT NULL AND `established_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_proforma` MODIFY `established_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wdt.established_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `established_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `established_date` = NULLIF(REPLACE(REPLACE(TRIM(`established_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `established_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `established_date` = NULL WHERE `established_date` IS NOT NULL AND `established_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `established_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wdt.invoiced_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `invoiced_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `invoiced_date` = NULLIF(REPLACE(REPLACE(TRIM(`invoiced_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `invoiced_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `invoiced_date` = NULL WHERE `invoiced_date` IS NOT NULL AND `invoiced_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'invoiced_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `invoiced_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wdt.shipping_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'shipping_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `shipping_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'shipping_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `shipping_date` = NULLIF(REPLACE(REPLACE(TRIM(`shipping_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `shipping_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'shipping_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wdt` SET `shipping_date` = NULL WHERE `shipping_date` IS NOT NULL AND `shipping_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'shipping_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wdt` MODIFY `shipping_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wz.established_date
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wz` MODIFY `established_date` TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wz` SET `established_date` = NULLIF(REPLACE(REPLACE(TRIM(`established_date`), ''/'', ''-''), ''.'', ''-''), '''') WHERE `established_date` IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'UPDATE `document_wz` SET `established_date` = NULL WHERE `established_date` IS NOT NULL AND `established_date` NOT REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2}$''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date' AND DATA_TYPE IN ('text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char')) = 1),
    'ALTER TABLE `document_wz` MODIFY `established_date` DATE NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND INDEX_NAME = 'idx_document_wdt_established') = 0),
    'ALTER TABLE `document_wdt` ADD INDEX `idx_document_wdt_established` (`established_date`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
