-- Wydajność dnia (27/10/2026) : Tablety › Zatwierdzenia produkcji. Czas normatywny zrobionych sztuk ÷ czas pracy grupy,
-- czas pracy = czas odbity (n_prod_clock) − przerwa (efficiency.break_min_per_person, 15 min na osobę na dzień, bez kontroli)
-- − sprzątanie (0, 15 albo 30 min × osoby obecne, wybór kierownika na dzień i grupę). Cel : efficiency.target_pct (85 %). Rejouable.

CREATE TABLE IF NOT EXISTS n_prod_day_cleaning (
    work_date           DATE NOT NULL,
    id_group            INT NOT NULL,
    minutes_per_person  SMALLINT NOT NULL DEFAULT 0 COMMENT 'Sprzątanie : minuty na osobę obecną (0, 15, 30)',
    updated_by          INT NULL COMMENT 'Użytkownik ERP',
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (work_date, id_group),
    KEY fk_n_prod_day_cleaning_group (id_group),
    CONSTRAINT fk_n_prod_day_cleaning_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE CASCADE,
    CONSTRAINT ck_n_prod_day_cleaning_minutes CHECK (minutes_per_person BETWEEN 0 AND 240)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'efficiency.break_min_per_person', '15', '15', 'int', 'min',
    'Wydajność dnia : przerwa odejmowana od czasu odbitego, minuty na osobę na dzień',
    'Rendement du jour : pause retirée du temps pointé, minutes par personne et par jour'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'efficiency.break_min_per_person');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'efficiency.target_pct', '85', '85', 'decimal', '%',
    'Wydajność dnia : cel (czas normatywny ÷ czas pracy)',
    'Rendement du jour : objectif (temps standard ÷ temps de travail)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'efficiency.target_pct');
