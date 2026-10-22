-- Kontrola faktur : import ostatnich cen z faktury do kartoteki surowców (kiedy i kto) ; ilości bez zamówienia
-- przyjmowane przez zamówienie utworzone z faktury (status « received »). MySQL i MariaDB : kolumny dodawane warunkowo.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_inv' AND COLUMN_NAME = 'prices_imported_at');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_inv ADD COLUMN prices_imported_at DATETIME NULL AFTER decided_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_inv' AND COLUMN_NAME = 'prices_imported_by');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_inv ADD COLUMN prices_imported_by INT NULL AFTER prices_imported_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
