-- Personel : wynagrodzenie minimalne a premie, budżet premiowy działu i matryca kompetencji.
--
-- Wynagrodzenie = minimum (stała część, koszt pracodawcy w PLN/mies.) + premie. Premie mają być
-- liczone jako % sprzedaży działu : pula = pay.bonus_pool_pct (lub % działu) × sprzedaż działu,
-- na osobę = pula ÷ liczba aktywnych osób z prawem do premii. Budżet personelu działu =
-- suma minimów + pula premii. Dział wspólny (pakowanie, administracja) : % sprzedaży całej firmy.
--
-- Kompetencje : te same co w ERP (recipe_competency, przypisane do receptur). Poziom na osobę :
-- 1 w szkoleniu, 2 samodzielny, 3 ekspert (szkoli innych). Dział „potrafi” kompetencję, gdy ma co
-- najmniej jedną samodzielną osobę ; jedna osoba = ryzyko.

CREATE TABLE IF NOT EXISTS tfb_staff_pay (
    id_production_employee INT NOT NULL,
    base_monthly           DECIMAL(10,2) NULL COMMENT 'Wynagrodzenie minimalne, koszt pracodawcy (PLN/mies.); NULL = nie podano',
    bonus_eligible         TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Uczestniczy w puli premii działu',
    active                 TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = nieobecny dłużej (urlop, L4) : poza liczbą osób aktywnych',
    notes                  VARCHAR(255) NULL,
    updated_by             INT NULL,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_production_employee),
    CONSTRAINT fk_tfb_staff_pay_employee FOREIGN KEY (id_production_employee) REFERENCES production_employee (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_staff_pay_base CHECK (base_monthly IS NULL OR base_monthly >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_employee_competency (
    id_production_employee INT NOT NULL,
    id_competency          INT NOT NULL,
    level                  TINYINT NOT NULL COMMENT '1 w szkoleniu, 2 samodzielny, 3 ekspert',
    updated_by             INT NULL,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_production_employee, id_competency),
    KEY idx_tfb_employee_competency_competency (id_competency),
    CONSTRAINT fk_tfb_employee_competency_employee FOREIGN KEY (id_production_employee) REFERENCES production_employee (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_employee_competency_competency FOREIGN KEY (id_competency) REFERENCES recipe_competency (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_employee_competency_level CHECK (level BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- % premii na dział (NULL = pay.bonus_pool_pct).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_section_target' AND COLUMN_NAME = 'bonus_pct');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tfb_section_target ADD COLUMN bonus_pct DECIMAL(5,2) NULL COMMENT ''Pula premii = % sprzedaży działu; NULL = pay.bonus_pool_pct'' AFTER margin_pct_target',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('pay.bonus_pool_pct', '5', '5', 'decimal', '%', 'Pula premii działu = % sprzedaży działu (do potwierdzenia)', 'Budget des primes du groupe = % des ventes du groupe (à confirmer)');
