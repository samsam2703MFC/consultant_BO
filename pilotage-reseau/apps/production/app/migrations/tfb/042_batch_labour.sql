-- Arkusz tygodnia : szybki czas partii — liczba osób × czas partii (min) = roboczogodziny na partię. Gdy ustawione
-- (z wielkością partii), godziny tygodnia produktu = partie × osoby × czas ; inaczej godziny z marszruty (etapy).

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_product_settings' AND COLUMN_NAME = 'batch_people');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_prod_product_settings ADD COLUMN batch_people DECIMAL(5,1) NULL AFTER batch_multiple', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_product_settings' AND COLUMN_NAME = 'batch_minutes');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_prod_product_settings ADD COLUMN batch_minutes DECIMAL(8,1) NULL AFTER batch_people', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
