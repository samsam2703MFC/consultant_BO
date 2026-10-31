-- Premie zespołów (27/10/2026) : onglet « Premia » de la tablette de production, visible ou non selon le groupe.
-- Ventes du groupe dans le mois (WDT, comme Rentowność) ÷ personnes du groupe → ventes par personne ; à partir du
-- minimum (bonus.min_per_person_pln, 30 000 PLN) le palier atteint donne la prime par personne (n_bonus_tier).
-- Prime de chacun au prorata des jours de présence (pointage) si bonus.presence_prorata = 1. Rejouable.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_group' AND COLUMN_NAME = 'bonus_visible');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE n_prod_group ADD COLUMN bonus_visible TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Zakładka « Premia » na tablecie grupy'' AFTER active',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_bonus_tier (
    id                   INT NOT NULL AUTO_INCREMENT,
    from_per_person_pln  DECIMAL(12,2) NOT NULL COMMENT 'Od sprzedaży na osobę w miesiącu (PLN)',
    amount_pln           DECIMAL(10,2) NOT NULL COMMENT 'Premia na osobę za miesiąc (PLN)',
    updated_by           INT NULL,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_bonus_tier_from (from_per_person_pln),
    CONSTRAINT ck_n_bonus_tier_values CHECK (from_per_person_pln > 0 AND amount_pln >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Progi z makiety (do zmiany w ERP : Zespół › Premie zespołów).
INSERT INTO n_bonus_tier (from_per_person_pln, amount_pln)
SELECT * FROM (SELECT 30000 AS f, 300 AS a UNION ALL SELECT 35000, 600 UNION ALL SELECT 40000, 1000) seed
WHERE NOT EXISTS (SELECT 1 FROM n_bonus_tier);

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'bonus.min_per_person_pln', '30000', '30000', 'decimal', 'PLN',
    'Premie zespołów : minimalna sprzedaż na osobę w miesiącu, od której jest premia',
    'Primes d''équipe : ventes minimales par personne et par mois pour avoir une prime'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'bonus.min_per_person_pln');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'bonus.presence_prorata', '1', '1', 'bool', NULL,
    'Premie zespołów : premia osoby w proporcji do dni obecności (odbicia na tablecie)',
    'Primes d''équipe : prime de chacun au prorata des jours de présence (pointage)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'bonus.presence_prorata');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'bonus.show_people', '1', '1', 'bool', NULL,
    'Premie zespołów : tablet pokazuje premię każdej osoby grupy (0 = tylko własną)',
    'Primes d''équipe : la tablette montre la prime de chaque personne du groupe (0 = seulement la sienne)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'bonus.show_people');
