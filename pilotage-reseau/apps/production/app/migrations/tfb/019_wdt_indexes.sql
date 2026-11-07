-- Indeksy odczytu dokumentów WDT (sprzedaż według daty, pozycje dokumentu). Bez nich każde
-- zestawienie sprzedaży (Rentowność, zakupy miesiąca, marże) czytało wszystkie pozycje WDT.
-- Tylko dodanie indeksów, gdy ich brak ; dane bez zmian. Kolumna tekstowa (na serwerze po
-- przeniesieniu z MariaDB data bywa TEXT) : indeks na pierwszych znakach (RRRR-MM-DD = 10).

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND SEQ_IN_INDEX = 1);
SET @type := (SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date');
SET @col := IF(@type IN ('tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob'), 'established_date(10)', 'established_date');
SET @sql := IF(@idx = 0, CONCAT('ALTER TABLE document_wdt ADD INDEX idx_document_wdt_established (', @col, ')'), 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND COLUMN_NAME = 'id_document_wdt' AND SEQ_IN_INDEX = 1);
SET @type := (SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND COLUMN_NAME = 'id_document_wdt');
SET @col := IF(@type IN ('tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob'), 'id_document_wdt(20)', 'id_document_wdt');
SET @sql := IF(@idx = 0, CONCAT('ALTER TABLE document_wdt_product ADD INDEX idx_document_wdt_product_document (', @col, ')'), 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
