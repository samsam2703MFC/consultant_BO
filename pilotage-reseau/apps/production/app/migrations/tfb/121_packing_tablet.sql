-- Tablet pakowania (28/10/2026) : pakowanie to osobny tablet (/tablet/packing, sparowany jak inne tablety), nie strona ERP.
-- Licznik spakowanych kartonów zapisuje tablet : updated_device = tablet (updated_by = użytkownik ERP, gdy z biura). Rejouable.
-- MySQL 8 : bez ADD COLUMN IF NOT EXISTS (krok warunkowy).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_packing_count' AND COLUMN_NAME = 'updated_device');
SET @sql := IF(@col = 0, 'ALTER TABLE n_packing_count ADD COLUMN updated_device INT NULL AFTER updated_by', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
