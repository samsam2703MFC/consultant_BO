-- [410] Tablet hali, krok « Schładzanie » (makieta, ekran D) : produit sorti du four → minutnik ; thermomètre au cœur du
-- produit → température mesurée. Aucun enregistrement HACCP de refroidissement n'existait pour la production (n_mgr_haccp_event
-- = contrôles, audits, ruptures de la chaîne du froid ; n_po_receipt.temperature_c = réception) : registre propre.
--   - n_hala_chill : jour, groupe, article (n_prod_item), partie éventuelle, sortie du four, température et heure de la mesure,
--     limites en vigueur au départ (copiées : un réglage changé ensuite ne réécrit pas l'historique) ;
--   - limites : hala.chill_target_c (°C au cœur, au plus) et hala.chill_max_minutes (délai maximal) — Ustawienia › Parametry ;
--   - étape par groupe (n_hala_group.step_chill) : réglage de départ pour les groupes Tarty, Cukiernia et Garmażeria (codes
--     TARTY, CUKIERNIA, GARMAZERIA s'ils existent), modifiable ensuite dans l'ERP — le code ne connaît aucun nom de groupe ;
--   - HACCP miesiąca : deux sortes d'éléments (n_haccp_month_review.kind) — hala_chill (refroidissement hors limites) et
--     hala_clean (strefy non sprzątnięte un jour de production).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, ALTER seulement si la valeur manque.

CREATE TABLE IF NOT EXISTS n_hala_chill (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    work_date   DATE NOT NULL,
    id_group    INT NOT NULL COMMENT 'n_prod_group.id',
    id_item     INT NOT NULL COMMENT 'n_prod_item.id',
    id_output   BIGINT NULL COMMENT 'n_prod_output.id (partia, jeśli jest)',
    out_at      DATETIME NOT NULL COMMENT 'wyjście z pieca : start minutnika',
    temp_c      DECIMAL(4,1) NULL COMMENT 'temperatura w środku produktu',
    measured_at DATETIME NULL,
    target_c    DECIMAL(4,1) NOT NULL COMMENT 'limit w chwili startu (hala.chill_target_c)',
    max_minutes SMALLINT NOT NULL COMMENT 'limit w chwili startu (hala.chill_max_minutes)',
    id_employee INT NULL,
    id_device   INT NULL,
    measured_by INT NULL,
    client_uuid CHAR(36) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hala_chill_uuid (client_uuid),
    KEY idx_hala_chill_group (id_group, work_date),
    KEY idx_hala_chill_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : schładzanie (temperatura i czas) [410]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.chill_target_c', '10', '10', 'decimal', '°C',
    'Tablet hali, schładzanie : temperatura w środku produktu musi być najwyżej taka',
    'Tablette atelier, refroidissement : température à cœur maximale'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.chill_target_c');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.chill_max_minutes', '120', '120', 'int', 'min',
    'Tablet hali, schładzanie : najdłuższy czas od wyjścia z pieca do zimnego produktu',
    'Tablette atelier, refroidissement : délai maximal entre la sortie du four et le produit froid'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.chill_max_minutes');

-- Réglage de départ de l'étape « Schładzanie » (modifiable : Produkcja › Tablet hali).
INSERT IGNORE INTO n_hala_group (id_group, guided, step_make, step_bags, step_chill, step_clean, step_pack)
SELECT g.id, 1, 1, 1, 1, 1, 0 FROM n_prod_group g WHERE g.code IN ('TARTY', 'CUKIERNIA', 'GARMAZERIA');

-- HACCP miesiąca : sortes hala_chill et hala_clean (table créée par la migration 228).
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review' AND COLUMN_NAME = 'kind');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%hala_chill%',
    'ALTER TABLE n_haccp_month_review MODIFY kind ENUM(''haccp_event'',''sop_review'',''sop_run'',''delivery_temp'',''hala_chill'',''hala_clean'') NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
