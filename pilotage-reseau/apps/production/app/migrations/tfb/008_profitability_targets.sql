-- Rentowność produkcji (strona startowa) : cele kosztu personelu i marży na dział produkcyjny.
-- Sprzedaż = WDT (EUR), koszt personelu = cost_month_employee, materiały = koszt surowców kartonu,
-- koszty działu = amortyzacja + media, koszty wspólne firmy = cost_month_fixed_cost.
-- Wartości docelowe [PARAM] : domyślne do potwierdzenia, zmieniane na stronie.

CREATE TABLE IF NOT EXISTS tfb_section_target (
    id_section         INT NOT NULL,
    staff_pct_target   DECIMAL(5,2) NULL COMMENT 'Maks. koszt personelu / sprzedaż działu; NULL = profit.staff_target_pct',
    margin_pct_target  DECIMAL(5,2) NULL COMMENT 'Min. marża działu po surowcach, personelu i kosztach działu; NULL = profit.section_margin_target_pct',
    is_shared          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Dział wspólny (bez własnej sprzedaży): pakowanie, administracja',
    updated_by         INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_section),
    CONSTRAINT fk_tfb_section_target_section FOREIGN KEY (id_section) REFERENCES production_sections (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_section_target_staff CHECK (staff_pct_target IS NULL OR (staff_pct_target > 0 AND staff_pct_target < 100)),
    CONSTRAINT ck_tfb_section_target_margin CHECK (margin_pct_target IS NULL OR (margin_pct_target > -100 AND margin_pct_target < 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_section_target (id_section, is_shared)
SELECT id, CASE WHEN name LIKE 'Pakowanie%' OR name LIKE 'Administr%' THEN 1 ELSE 0 END FROM production_sections;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('profit.staff_target_pct', '25', '25', 'decimal', '%', 'Cel: maks. koszt personelu działu / sprzedaż działu', 'Objectif : coût du personnel du groupe / ventes du groupe (max.)'),
('profit.total_staff_target_pct', '35', '35', 'decimal', '%', 'Cel: maks. koszt całego personelu (z działami wspólnymi) / sprzedaż', 'Objectif : coût de tout le personnel (services communs inclus) / ventes (max.)'),
('profit.section_margin_target_pct', '30', '30', 'decimal', '%', 'Cel: min. marża działu po surowcach, personelu i kosztach działu', 'Objectif : marge du groupe après matières, personnel et coûts du groupe (min.)'),
('profit.margin_target_pct', '10', '10', 'decimal', '%', 'Cel: min. wynik produkcji po wszystkich kosztach', 'Objectif : résultat de production après tous les coûts (min.)');
