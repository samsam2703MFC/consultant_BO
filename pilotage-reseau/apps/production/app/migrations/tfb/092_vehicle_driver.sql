-- Audit 2, lot C (assistant Véhicule et chauffeur) : chauffeur habituel du véhicule choisi parmi les salariés
-- (n_prod_employee, liste et PIN de la tablette chauffeur) au lieu du seul texte libre de carrier_vehicle
-- (qui reste rempli avec son nom pour le CMR). NULL = chauffeur du transporteur (externe) ou inconnu.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_vehicle' AND COLUMN_NAME = 'id_driver') = 0,
    'ALTER TABLE n_vehicle
        ADD COLUMN id_driver INT NULL AFTER departure_time,
        ADD CONSTRAINT fk_tfb_vehicle_driver FOREIGN KEY (id_driver) REFERENCES n_prod_employee (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
