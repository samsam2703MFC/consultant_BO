-- [288] Urlopy (makiety 48 + 49) : wnioski o urlop z telefonu (po odbiciu QR) zatwierdzane przez kierownika produkcji,
-- korekta salda urlopu (HR : « zostało » wpisane w tabeli pracowników) i zaległy urlop z poprzedniego roku do 01.06.

-- Wniosek o urlop złożony na telefonie powiązanym z osobą ; po zatwierdzeniu = wpis n_time_absence (id_absence).
CREATE TABLE IF NOT EXISTS n_time_leave_request (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    id_employee   INT NOT NULL COMMENT 'n_prod_employee.id',
    type          ENUM('urlop','na_zadanie') NOT NULL,
    date_from     DATE NOT NULL,
    date_to       DATE NOT NULL,
    days          INT NOT NULL COMMENT 'jours ouvrés (lun.–ven. hors fériés PL) au dépôt',
    note          VARCHAR(255) NULL,
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(255) NULL COMMENT 'motif du refus',
    id_absence    INT NULL COMMENT 'n_time_absence.id créé à la validation',
    id_device     INT NULL COMMENT 'téléphone (n_prod_device.id)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by    INT NULL COMMENT 'user.id',
    decided_at    DATETIME NULL,
    KEY ix_n_time_leave_request (status, date_from),
    KEY ix_n_time_leave_request_emp (id_employee, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Korekta salda urlopu na rok (dni, + albo −) : HR wpisuje « zostało », ERP zapisuje różnicę wobec wyliczenia.
CREATE TABLE IF NOT EXISTS n_time_leave_adjust (
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    year        SMALLINT NOT NULL,
    days        INT NOT NULL,
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_employee, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('time.leave_carry_until', '01.06', '01.06', 'string', 'DD.MM', 'Zaległy urlop z poprzedniego roku : wykorzystać najpóźniej do (potem przepada) ; urlop od 1.01 najpierw zmniejsza zaległy', 'Congé reporté de l''année précédente : à prendre au plus tard le (ensuite perdu) ; le congé pris dès le 1.01 consomme d''abord le report'),
('time.leave_since_year', '2026', '2026', 'int', 'rok', 'Urlop : pierwszy rok liczony w ERP (zaległy wyliczany od następnego roku)', 'Congé : première année suivie dans l''ERP (report calculé à partir de l''année suivante)');
