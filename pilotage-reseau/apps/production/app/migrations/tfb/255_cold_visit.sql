-- [432] Obchód chłodni przed zakupami (makieta « Obchód chłodni », écrans A tablette, B une chłodnia, C ERP) : avant
-- « Zakupy tygodnia », le Kierownik produkcji fait le tour des lodówki et chłodnie avec sa tablette qualité (/tablet/quality),
-- compte les surowce, marque les dates (« Krótka — najpierw »), les pertes (« Do wyrzucenia », « Uszkodzone ») et le
-- porządek. Les comptages passent par l'inventaire existant de Zaopatrzenie (n_mp_count), donc le stan et « Co kupić »
-- partent de la quantité réelle. Tables propres :
--   - n_cold_place : miejsca obchodu — chaque urządzenie « chłodnia / mroźnia » actif du rejestr « Serwisy i przeglądy »
--     (n_equipment.kind = 'cold', lien id_equipment, recopié aussi à la lecture) et les miejsca ajoutés dans l'ERP (Magazyn
--     suchy…, sans urządzenie) ;
--   - n_cold_place_material : quel surowiec leży où (appris au premier obchód : « Dodaj surowiec, który tu leży ») ;
--   - n_cold_visit : un obchód (début, fin quand toutes les miejsca sont zapisane, personne, totaux) ;
--   - n_cold_visit_place : une miejsce zapisane dans un obchód (porządek tak / nie + zdjęcie, note, totaux) ;
--   - n_cold_visit_line : un surowiec policzony (stan ERP au moment du zapis, jest naprawdę, date, perte, inventaire écrit).
--   Aucun mécanisme de perte de surowce n'existe dans l'ERP (n_prod_waste = pertes de produits, n_raw_material_loss = odpad
--   przy obróbce en %) : les pertes restent dans n_cold_visit_line et l'inventaire écrit = policzone − strata.
--   - n_prod_param cold_visit.valid_hours : « Co kupić » s'ouvre seulement après un obchód complet de moins de N heures ;
--   - HACCP miesiąca : sorte cold_clean (porządek « Nie » + zdjęcie) dans n_haccp_month_review.kind.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, ALTER seulement si la valeur manque. Pas de clé
-- étrangère vers les tables de l'ERP (bases partielles des tests).

CREATE TABLE IF NOT EXISTS n_cold_place (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_equipment INT UNSIGNED NULL COMMENT 'n_equipment.id (chłodnia / mroźnia z rejestru) ; NULL = miejsce dodane w ERP',
    name         VARCHAR(160) NULL COMMENT 'nazwa miejsca bez urządzenia (Magazyn suchy…) ; z urządzeniem : jego nazwa',
    kind         ENUM('cold','dry','other') NOT NULL DEFAULT 'cold' COMMENT 'chłodnia / lodówka, magazyn suchy, inne',
    sort_order   INT NOT NULL DEFAULT 0,
    active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cold_place_equipment (id_equipment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Obchód chłodni : miejsca (urządzenia chłodnicze + dodane) [432]';

CREATE TABLE IF NOT EXISTS n_cold_place_material (
    id_place        INT UNSIGNED NOT NULL COMMENT 'n_cold_place.id',
    id_raw_material INT NOT NULL COMMENT 'raw_material.id',
    id_employee     INT NULL COMMENT 'n_prod_employee.id (tablet)',
    created_by      INT NULL COMMENT 'user.id (ERP)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_place, id_raw_material),
    KEY idx_cold_place_material (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Obchód chłodni : który surowiec leży gdzie [432]';

CREATE TABLE IF NOT EXISTS n_cold_visit (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at   DATETIME NOT NULL,
    completed_at DATETIME NULL COMMENT 'wszystkie miejsca zapisane',
    id_employee  INT NULL COMMENT 'n_prod_employee.id : Kierownik produkcji / Jakość (PIN na tablecie)',
    id_device    INT NULL COMMENT 'n_prod_device.id',
    places       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'miejsca zapisane',
    materials    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'surowce policzone',
    differences  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'surowce z różnicą wobec ERP',
    PRIMARY KEY (id),
    KEY idx_cold_visit_done (completed_at),
    KEY idx_cold_visit_start (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Obchód chłodni przed zakupami [432]';

CREATE TABLE IF NOT EXISTS n_cold_visit_place (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_visit    INT UNSIGNED NOT NULL COMMENT 'n_cold_visit.id',
    id_place    INT UNSIGNED NOT NULL COMMENT 'n_cold_place.id',
    place_name  VARCHAR(160) NOT NULL COMMENT 'nazwa w chwili zapisu',
    saved_at    DATETIME NOT NULL,
    id_employee INT NULL,
    id_device   INT NULL,
    clean       ENUM('yes','no') NOT NULL COMMENT 'czysto i porządek ?',
    id_photo    BIGINT NULL COMMENT 'n_prod_lot_photo.id (obowiązkowe przy « Nie »)',
    note        VARCHAR(255) NULL,
    materials   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    differences SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cold_visit_place (id_visit, id_place),
    KEY idx_cold_visit_place_clean (clean, saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Obchód chłodni : miejsce zapisane [432]';

CREATE TABLE IF NOT EXISTS n_cold_visit_line (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_visit_place  INT UNSIGNED NOT NULL COMMENT 'n_cold_visit_place.id',
    id_raw_material INT NOT NULL,
    erp_qty         DECIMAL(14,3) NULL COMMENT 'stan ERP przed liczeniem (jednostka bazowa) ; NULL = nieznany',
    counted_qty     DECIMAL(14,3) NOT NULL COMMENT 'jest naprawdę (jednostka bazowa)',
    date_state      ENUM('ok','short') NULL COMMENT 'data OK / krótka — zużyć najpierw',
    loss_kind       ENUM('discard','damaged') NULL COMMENT 'do wyrzucenia / uszkodzone',
    loss_qty        DECIMAL(14,3) NULL,
    loss_reason     VARCHAR(120) NULL,
    lot_number      VARCHAR(64) NULL COMMENT 'partia bieżąca (rejestr partii) w chwili zapisu',
    best_before     DATE NULL,
    count_qty       DECIMAL(14,3) NULL COMMENT 'inwentaryzacja zapisana (wszystkie miejsca surowca − straty)',
    id_mp_count     INT NULL COMMENT 'n_mp_count.id (inwentaryzacja Zaopatrzenia)',
    PRIMARY KEY (id),
    KEY idx_cold_line_place (id_visit_place),
    KEY idx_cold_line_material (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Obchód chłodni : surowiec policzony [432]';

-- Miejsca de départ : les chłodnie / mroźnie du rejestr (les suivantes sont recopiées à la lecture).
SET @c := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_equipment');
SET @sql := IF(@c > 0, 'INSERT INTO n_cold_place (id_equipment, kind, sort_order)
    SELECT e.id, ''cold'', 0 FROM n_equipment e
    WHERE e.kind = ''cold'' AND NOT EXISTS (SELECT 1 FROM n_cold_place p WHERE p.id_equipment = e.id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'cold_visit.valid_hours', '24', '24', 'int', 'h',
    'Zakupy tygodnia : « Co kupić » otwiera się po pełnym obchodzie chłodni zrobionym najwyżej tyle godzin temu',
    'Achats de la semaine : « Co kupić » s''ouvre après une tournée complète des chambres froides faite au plus il y a ce nombre d''heures'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'cold_visit.valid_hours');

-- HACCP miesiąca : sorte cold_clean (table créée par la migration 228, étendue par 248).
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review' AND COLUMN_NAME = 'kind');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%cold_clean%',
    CONCAT('ALTER TABLE n_haccp_month_review MODIFY kind ', REPLACE(@t, ')', ',''cold_clean'')'), ' NOT NULL'), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
