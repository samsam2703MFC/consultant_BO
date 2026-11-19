-- [348] Karta pracownika › « Grafik tygodnia i przypomnienie o wejściu » : stały grafik tygodnia (taki sam co tydzień,
--   pusty dzień = wolne) et SMS à la personne quand elle n'a pas pointé son entrée N minutes après le début du grafik
--   (Bin/hr_clock_reminder.php, cron chaque minute). Un seul SMS par personne et par jour (n_hr_clock_reminder, unique).
-- Rien si : pointage d'entrée ce jour, absence du calendrier (n_time_absence), férié PL, pas de téléphone dans la fiche,
--   SMS coupé sur la fiche, après la fin du grafik. Texte vide sur la fiche = texte commun (hr.clock_sms_text).
-- Le téléphone reste chiffré (n_hr_employee.phone_enc) ; ni numéro ni nom dans les journaux du serveur.
CREATE TABLE IF NOT EXISTS n_hr_schedule (
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    weekday     TINYINT NOT NULL COMMENT '1 = poniedziałek … 7 = niedziela',
    start_time  TIME NOT NULL COMMENT 'początek grafiku (HH:MM)',
    end_time    TIME NOT NULL COMMENT 'koniec grafiku ; mniejszy niż początek = koniec następnego dnia (noc)',
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_employee, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_employee' AND COLUMN_NAME = 'clock_sms');
SET @sql := IF(@c = 0, 'ALTER TABLE n_hr_employee ADD COLUMN clock_sms TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''SMS, gdy brak wejścia (grafik tygodnia)''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_employee' AND COLUMN_NAME = 'clock_sms_text');
SET @sql := IF(@c = 0, 'ALTER TABLE n_hr_employee ADD COLUMN clock_sms_text VARCHAR(480) NULL COMMENT ''treść SMS dla tej osoby ; NULL = hr.clock_sms_text'' AFTER clock_sms', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_hr_clock_reminder (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_employee     INT NOT NULL COMMENT 'n_prod_employee.id',
    day             DATE NOT NULL,
    scheduled_start TIME NOT NULL COMMENT 'początek grafiku tego dnia',
    sent_at         DATETIME NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, logged (zapisany, bramka nie wysyła), failed',
    error           VARCHAR(255) NULL,
    UNIQUE KEY uq_n_hr_clock_reminder (id_employee, day),
    KEY idx_n_hr_clock_reminder_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('hr.clock_sms_delay', '15', '15', 'int', 'min', 'HR : SMS, gdy brak wejścia — po ilu minutach od początku grafiku', 'RH : SMS si pas d''entrée — minutes après le début de l''horaire'),
('hr.clock_sms_text', 'Jesteś w grafiku od {godzina}, a nie zarejestrowano jeszcze Twojego wejścia. Zarejestruj wejście albo poinformuj biuro o nieobecności. Dziękujemy.',
 'Jesteś w grafiku od {godzina}, a nie zarejestrowano jeszcze Twojego wejścia. Zarejestruj wejście albo poinformuj biuro o nieobecności. Dziękujemy.',
 'string', NULL, 'HR : domyślna treść SMS, gdy brak wejścia ({godzina} = początek grafiku)', 'RH : texte par défaut du SMS si pas d''entrée ({godzina} = début de l''horaire)');
