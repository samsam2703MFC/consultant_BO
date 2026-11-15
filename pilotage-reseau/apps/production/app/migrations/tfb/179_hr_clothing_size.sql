-- [288] HR : rozmiar odzieży roboczej (lista XXS–XXXL) sur la fiche ; « Wymiar » reste le temps de travail (contrats).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_employee' AND COLUMN_NAME = 'clothing_size');
SET @sql := IF(@c = 0, 'ALTER TABLE n_hr_employee
    ADD COLUMN clothing_size ENUM(''XXS'', ''XS'', ''S'', ''M'', ''L'', ''XL'', ''XXL'', ''XXXL'') NULL COMMENT ''rozmiar odzieży roboczej'' AFTER work_time',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
