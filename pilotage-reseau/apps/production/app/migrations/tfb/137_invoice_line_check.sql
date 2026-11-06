-- Factures d'achat — assistant [217] (Zaopatrzenie › Faktury › Kreator) : toutes les lignes des factures ouvertes dans un
-- tableau, vérifiées l'une après l'autre. Chaque ligne garde son contrôle : vérifiée (checked) ou litigieuse (disputed), qui,
-- quand, note. Une nouvelle correspondance (matière ou facteur) annule le contrôle. Conditionnel et rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv_line' AND COLUMN_NAME = 'check_status') = 0,
    'ALTER TABLE n_inv_line
        ADD COLUMN check_status VARCHAR(8) NULL COMMENT ''assistant : checked, disputed'' AFTER price_ok_at,
        ADD COLUMN check_note VARCHAR(500) NULL AFTER check_status,
        ADD COLUMN checked_by INT NULL AFTER check_note,
        ADD COLUMN checked_at DATETIME NULL AFTER checked_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
