-- Kartony i palety [211] : maks. wysokość i waga palety dla rodzaju kartonu (NULL = parametry logistics.pallet_max_height_mm
-- i logistics.pallet_max_kg). Kolejność : produkt (krok Paleta) → karton → parametry ; warstwy produktu liczone z jego wagi.
-- Rejouable (MySQL 8 / MariaDB).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_carton' AND COLUMN_NAME = 'pallet_max_height_mm');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_carton ADD COLUMN pallet_max_height_mm SMALLINT UNSIGNED NULL COMMENT ''maks. wysokość palety z tym kartonem (mm, z paletą) ; NULL = parametr'' AFTER max_load_kg,
        ADD COLUMN pallet_max_kg SMALLINT UNSIGNED NULL COMMENT ''maks. waga palety z tym kartonem (kg, z paletą) ; NULL = parametr'' AFTER pallet_max_height_mm',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
