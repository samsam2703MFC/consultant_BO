-- Tablette de groupe : validation de la production du jour ligne par ligne (plan → fait, perte sur la même ligne) et
-- photos des pertes. n_prod_day_line : une ligne validée par produit, jour et groupe (quantité faite, pertes, motif
-- d'un manque au plan, qui et quand ; correction du kierownik depuis l'ERP : corrected_by / corrected_at).
-- n_prod_waste_photo : photos d'une perte (n_prod_lot_photo, stockées en base comme les étiquettes de lot).
-- Conditionnel (bases de test sans les tables de production) et rejouable (MySQL 8 / MariaDB).

SET @prod := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_waste');

SET @sql := IF(@prod > 0, 'CREATE TABLE IF NOT EXISTS n_prod_waste_photo (
    id_waste    BIGINT NOT NULL,
    id_photo    BIGINT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_waste, id_photo),
    UNIQUE KEY uq_n_prod_waste_photo_photo (id_photo),
    CONSTRAINT fk_n_prod_waste_photo_waste FOREIGN KEY (id_waste) REFERENCES n_prod_waste (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_prod_waste_photo_photo FOREIGN KEY (id_photo) REFERENCES n_prod_lot_photo (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@prod > 0, 'CREATE TABLE IF NOT EXISTS n_prod_day_line (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    work_date     DATE NOT NULL,
    id_group      INT NOT NULL,
    id_item       INT NOT NULL,
    planned       DECIMAL(12,3) NULL,
    produced      DECIMAL(12,3) NOT NULL,
    waste         DECIMAL(12,3) NOT NULL DEFAULT 0,
    note          VARCHAR(255) NULL COMMENT ''powód braku wobec planu'',
    id_employee   INT NULL,
    id_device     INT NULL,
    validated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    corrected_by  INT NULL COMMENT ''użytkownik ERP (kierownik) — poprawka po zatwierdzeniu'',
    corrected_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_prod_day_line (work_date, id_group, id_item),
    KEY idx_n_prod_day_line_item (id_item),
    KEY idx_n_prod_day_line_group (id_group),
    KEY idx_n_prod_day_line_employee (id_employee),
    KEY idx_n_prod_day_line_device (id_device),
    CONSTRAINT fk_n_prod_day_line_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id),
    CONSTRAINT fk_n_prod_day_line_item FOREIGN KEY (id_item) REFERENCES n_prod_item (id),
    CONSTRAINT fk_n_prod_day_line_employee FOREIGN KEY (id_employee) REFERENCES n_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_prod_day_line_device FOREIGN KEY (id_device) REFERENCES n_prod_device (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.waste_photo_required', '1', '1', 'bool', NULL,
    'Tablet : zdjęcie straty wymagane (każda strata z ilością > 0)',
    'Tablette : photo obligatoire pour chaque perte (quantité > 0)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.waste_photo_required');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.waste_photo_max', '4', '4', 'int', NULL,
    'Tablet : maksymalna liczba zdjęć jednej straty',
    'Tablette : nombre maximal de photos par perte'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.waste_photo_max');
