-- Indeksy odczytu dokumentów WDT (sprzedaż według daty, pozycje dokumentu). Bez nich każde
-- zestawienie sprzedaży (Rentowność, zakupy miesiąca, marże) czytało wszystkie pozycje WDT.
-- Tylko dodanie indeksów, gdy ich brak ; dane bez zmian.

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'established_date' AND SEQ_IN_INDEX = 1);
SET @sql := IF(@idx = 0, 'ALTER TABLE document_wdt ADD INDEX idx_document_wdt_established (established_date)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND COLUMN_NAME = 'id_document_wdt' AND SEQ_IN_INDEX = 1);
SET @sql := IF(@idx = 0, 'ALTER TABLE document_wdt_product ADD INDEX idx_document_wdt_product_document (id_document_wdt)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
