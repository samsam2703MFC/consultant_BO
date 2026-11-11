-- P6 — obsada na dzień i nadgodziny (optymalizator zmian 95 % i symulacja).
--
-- Obsada grupy na zmianę danego dnia ; bez wiersza = suma planned_headcount zespołów grupy na tej
-- zmianie. Nadgodziny (osobo-godziny) przedłużają zmianę grupy : dostępne godziny = obsada × długość
-- zmiany + nadgodziny. Wiersze pisze optymalizator (source = optimizer) albo kierownik (manual).

CREATE TABLE IF NOT EXISTS tfb_prod_staffing (
    work_date          DATE NOT NULL,
    id_group           INT NOT NULL,
    id_shift_template  INT NOT NULL,
    headcount          INT NOT NULL,
    overtime_h         DECIMAL(5,2) NOT NULL DEFAULT 0,
    source             ENUM('optimizer','manual') NOT NULL DEFAULT 'manual',
    note               VARCHAR(255) NULL,
    updated_by         INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (work_date, id_group, id_shift_template),
    CONSTRAINT fk_tfb_staffing_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_staffing_shift FOREIGN KEY (id_shift_template) REFERENCES tfb_prod_shift_template (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_staffing_headcount CHECK (headcount >= 0 AND headcount <= 50),
    CONSTRAINT ck_tfb_staffing_overtime CHECK (overtime_h >= 0 AND overtime_h <= 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('optimizer.max_overtime_h', '2', '2', 'decimal', 'h', 'Maks. nadgodziny na osobę i zmianę (dźwignia „nadgodziny”)', 'Heures supplémentaires max. par personne et par shift (levier « heures sup »)');
