-- Tablette de bureau : la personne choisit sa section (dział) puis son nom ; la tablette affiche un QR personnel
-- (personne + jour) que seule cette personne valide avec son téléphone enregistré (entrée / sortie).
-- n_prod_device.mode reçoit « office ». Les salariés sans profil de production (ex. Administracja) reçoivent leur
-- profil n_prod_employee (actif, comme la valeur par défaut des listes) : le responsable peut leur donner un PIN.
-- kiosk.office_qr_ttl_s : validité du QR personnel. Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_device'
        AND COLUMN_NAME = 'mode' AND COLUMN_TYPE NOT LIKE '%''office''%') = 1,
    'ALTER TABLE n_prod_device MODIFY COLUMN mode ENUM(''atelier'',''driver'',''office'') NOT NULL DEFAULT ''atelier''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_prod_employee', 'production_employee')) = 2,
    'INSERT INTO n_prod_employee (id_production_employee, active)
     SELECT pe.id, 1 FROM production_employee pe
     WHERE NOT EXISTS (SELECT 1 FROM n_prod_employee e WHERE e.id_production_employee = pe.id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.office_qr_ttl_s', '120', '120', 'int', 's',
    'Tablet biurowy : ważność osobistego kodu QR (osoba + dzień)',
    'Tablette de bureau : validité du QR code personnel (personne + jour)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.office_qr_ttl_s');
