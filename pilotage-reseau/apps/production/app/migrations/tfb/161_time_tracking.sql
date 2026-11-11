-- [262] Czas pracy (pointages) : validation quotidienne des pointages par le kierownik, motif de chaque surplus,
--   absences et congés (26 jours / an par défaut), banque d'heures supplémentaires récupérées en jour de congé
--   supplémentaire sous 3 mois au plus, proposé par le kierownik et signé par la personne au pointage (tablette bureau).
-- Règles (décision du 11/11/2026) : plan 8 h par jour ouvré (lundi–vendredi hors fériés PL), 40 h par semaine ;
--   heure supplémentaire = au-delà de 8 h le jour (tolérance time.tolerance_min) et, en plus, au-delà de 40 h la semaine.
-- Les pointages restent dans n_prod_clock (inchangés). Rejouable : CREATE IF NOT EXISTS, INSERT IGNORE, ALTER conditionnel.

-- Signature d'un jour de récupération (ref = n_time_comp.id).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_signature'
        AND COLUMN_NAME = 'purpose' AND COLUMN_TYPE NOT LIKE '%''comp_day''%') = 1,
    'ALTER TABLE n_prod_signature MODIFY COLUMN purpose ENUM(''clock_in'',''clock_out'',''timesheet_month'',''operation'',''comp_day'') NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Jour validé par le kierownik : tous les pointages fermés, chaque surplus et chaque absence ont un motif.
CREATE TABLE IF NOT EXISTS n_time_day (
    day          DATE NOT NULL PRIMARY KEY,
    validated_by INT NULL COMMENT 'user.id',
    validated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    summary      VARCHAR(255) NULL COMMENT 'présents, heures, heures sup. au moment de la validation'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Motif d'un surplus (overtime) ou d'une absence sans entrée de calendrier (absence) pour une personne un jour donné.
CREATE TABLE IF NOT EXISTS n_time_note (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    day         DATE NOT NULL,
    kind        ENUM('overtime','absence') NOT NULL,
    reason      VARCHAR(32) NOT NULL COMMENT 'code de TimeService::OVERTIME_REASONS / ABSENCE_REASONS',
    note        VARCHAR(255) NULL,
    set_by      INT NULL,
    set_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_time_note (id_employee, day, kind),
    KEY ix_n_time_note_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Calendrier des absences : congé, congé sur demande, maladie (L4), jour de récupération (wn, créé par la signature).
CREATE TABLE IF NOT EXISTS n_time_absence (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    date_from   DATE NOT NULL,
    date_to     DATE NOT NULL,
    type        ENUM('urlop','na_zadanie','l4','wn','inna') NOT NULL,
    minutes     INT NULL COMMENT 'wn : minutes récupérées (jour partiel possible)',
    id_comp     INT NULL COMMENT 'n_time_comp.id pour un jour de récupération',
    note        VARCHAR(255) NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_n_time_absence (id_employee, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Jour de récupération des heures supplémentaires : proposé par le kierownik, signé par la personne (tablette bureau
-- au pointage, ou à l'écran devant le kierownik). Seul un jour signé réduit la banque d'heures.
CREATE TABLE IF NOT EXISTS n_time_comp (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    id_employee  INT NOT NULL COMMENT 'n_prod_employee.id',
    day          DATE NOT NULL COMMENT 'jour de congé supplémentaire',
    minutes      INT NOT NULL,
    status       ENUM('proposed','signed','cancelled') NOT NULL DEFAULT 'proposed',
    id_signature INT NULL COMMENT 'n_prod_signature.id (purpose comp_day)',
    proposed_by  INT NULL,
    proposed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signed_at    DATETIME NULL,
    KEY ix_n_time_comp (id_employee, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Mois clôturé : tous les jours validés, cartes A4 imprimées pour signature ; plus de correction ni de revalidation.
CREATE TABLE IF NOT EXISTS n_time_month (
    month     CHAR(7) NOT NULL PRIMARY KEY COMMENT 'YYYY-MM',
    closed_by INT NULL COMMENT 'user.id',
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Droit au congé par personne (jours par an) ; sans ligne : time.leave_days.
CREATE TABLE IF NOT EXISTS n_time_leave (
    id_employee   INT NOT NULL PRIMARY KEY COMMENT 'n_prod_employee.id',
    days_per_year INT NOT NULL,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('time.day_plan_min', '480', '480', 'int', 'min', 'Czas pracy : plan dnia roboczego (pon.–pt., poza świętami)', 'Pointages : plan d''un jour ouvré (lun.–ven., hors fériés)'),
('time.week_plan_min', '2400', '2400', 'int', 'min', 'Czas pracy : plan tygodnia ; ponad = nadgodziny', 'Pointages : plan de la semaine ; au-delà = heures sup.'),
('time.tolerance_min', '15', '15', 'int', 'min', 'Czas pracy : tolerancja ponad plan dnia bez nadgodzin', 'Pointages : tolérance au-delà du plan du jour sans heure sup.'),
('time.recovery_months', '3', '3', 'int', 'mies.', 'Nadgodziny : odebrać jako dzień wolny najpóźniej w ciągu', 'Heures sup. : à récupérer en jour de congé au plus tard dans'),
('time.leave_days', '26', '26', 'int', 'dni', 'Urlop : dni w roku (domyślnie dla każdej osoby)', 'Congé : jours par an (par défaut pour chaque personne)'),
('time.company_name', 'SL Food', 'SL Food', 'string', NULL, 'Karta czasu pracy (PDF) : nazwa pracodawcy w stopce (logo SL Food w nagłówku)', 'Fiche de temps (PDF) : employeur en pied de page (logo SL Food en en-tête)');
