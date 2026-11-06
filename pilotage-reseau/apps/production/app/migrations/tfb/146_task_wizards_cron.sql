-- Lot 1 « zadanie = kreator » [227] (makiety 1–3 zatwierdzone) : zadanie stanowiska otwiera kreator na właściwy okres i
-- zalicza się samo na jego ostatnim kroku ; może też być wykonywane automatycznie (cron) : kiedy (codziennie, w dni
-- tygodnia, co miesiąc, co kwartał), o której, co robi, potem « czeka na osobę » albo « zalicza się samo » ; przypomnienie
-- w dniu terminu i przełożony po N dniach spóźnienia. Dziennik uruchomień automatycznych. Warunkowe i rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'due_time') = 0,
    'ALTER TABLE n_position_task
        ADD COLUMN due_time TIME NULL COMMENT ''termin w dniu zadania (np. 12:00) ; NULL = koniec dnia'' AFTER weekdays,
        ADD COLUMN month_day VARCHAR(5) NULL COMMENT ''termin miesiąca / kwartału : wd3 = 3. dzień roboczy, d5 = 5. dzień ; NULL = ostatni dzień'' AFTER due_time,
        ADD COLUMN cron_on TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''wykonywane automatycznie (cron)'' AFTER auto_code,
        ADD COLUMN cron_freq ENUM(''daily'',''weekly'',''monthly'',''quarterly'') NULL COMMENT ''cron : częstotliwość'' AFTER cron_on,
        ADD COLUMN cron_days VARCHAR(13) NULL COMMENT ''cron : dni tygodnia (1 = pon.), np. 1 albo 1,2,3,4,5 ; miesiąc / kwartał : wd1, d1'' AFTER cron_freq,
        ADD COLUMN cron_time TIME NULL COMMENT ''cron : godzina uruchomienia'' AFTER cron_days,
        ADD COLUMN cron_action VARCHAR(32) NULL COMMENT ''cron : co robi (TaskCronService::ACTIONS)'' AFTER cron_time,
        ADD COLUMN cron_then ENUM(''wait'',''done'') NOT NULL DEFAULT ''wait'' COMMENT ''po uruchomieniu : czeka na osobę (kreator) albo zalicza się samo'' AFTER cron_action,
        ADD COLUMN remind_time TIME NULL DEFAULT ''09:00:00'' COMMENT ''przypomnienie osobie w dniu terminu ; NULL = bez'' AFTER cron_then,
        ADD COLUMN escalate_days TINYINT UNSIGNED NULL DEFAULT 2 COMMENT ''spóźnione o tyle dni : powiadom przełożonego ; NULL = nigdy'' AFTER remind_time',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_task_occurrence' AND COLUMN_NAME = 'summary') = 0,
    'ALTER TABLE n_task_occurrence
        ADD COLUMN summary VARCHAR(255) NULL COMMENT ''wynik kreatora albo crona (np. 10 988 kart. · zatwierdzona 09:04)'' AFTER note,
        ADD COLUMN reminded_at DATETIME NULL COMMENT ''przypomnienie w dniu terminu wysłane'' AFTER summary,
        ADD COLUMN escalated_at DATETIME NULL COMMENT ''przełożony powiadomiony o spóźnieniu'' AFTER reminded_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Uruchomienia automatyczne zadań : jedno udane na okres crona (dzień, tydzień, miesiąc, kwartał).
CREATE TABLE IF NOT EXISTS n_task_cron_run (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_task INT NOT NULL,
    period_key VARCHAR(16) NOT NULL COMMENT 'okres crona : 2026-11-02, 2026-W45, 2026-11, 2026-Q4',
    action VARCHAR(32) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    ok TINYINT(1) NULL COMMENT '1 = OK, 0 = błąd, NULL = w toku',
    summary VARCHAR(255) NULL COMMENT 'wynik (np. 177 produktów · 12 stanów zmienionych)',
    error VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_task_cron_run (id_task, period_key),
    KEY idx_task_cron_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Zadania : uruchomienia automatyczne (cron)';

-- Kreatory, które już są, podpięte pod zadania (te same strony, zalicza się samo) :
-- wysyłka dnia (Logistyka) — wszystkie palety dnia z dokumentami …
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.link_path = '/shipping/wizard', t.auto_code = 'day_shipped'
WHERE p.code = 'LOGISTYKA' AND t.frequency = 'daily' AND t.link_path = '/shipping-documents/create' AND t.auto_code IS NULL;

-- … śledzenie produkcji (Kierownik produkcji) — dzień zatwierdzony na tabletach …
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.auto_code = 'production_day_closed'
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND t.frequency = 'daily' AND t.link_path = '/production/follow-up' AND t.auto_code IS NULL;

-- … zamknięcie miesiąca (Administracja) — kreator 8 kroków, termin 3. dzień roboczy, miesiąc zamknięty.
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.link_path = '/month-close', t.auto_code = 'month_closed', t.month_day = 'wd3', t.minutes = COALESCE(t.minutes, 120)
WHERE p.code = 'ADMINISTRACJA' AND t.frequency = 'monthly' AND t.link_path = '/communication/automations' AND t.auto_code IS NULL;

-- Nowe zadania z kreatorem (gdy stanowisko jest, a zadania jeszcze nie ma) : plan produkcji tygodnia (Kierownik
-- produkcji, czwartek), plan logistyki 13 tygodni (Logistyka, czwartek), koszty usług miesiąca (Administracja, 2. dzień roboczy).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1), 'Plan produkcji tygodnia (kreator : zatwierdź plan)',
       'Plan de production de la semaine (assistant : valider le plan)', 'weekly', 4, 45, '/production/plan-wizard', 'week_plan_validated', 15, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/production/plan-wizard');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'LOGISTYKA' LIMIT 1), 'Plan logistyki 13 tygodni (zatwierdź tydzień)',
       'Plan logistique 13 semaines (valider la semaine)', 'weekly', 4, 30, '/logistics/plan', 'logistics_plan_approved', 15, 1
FROM n_position p
WHERE p.code = 'LOGISTYKA' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/logistics/plan');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, month_day, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'ADMIN' LIMIT 1), 'Koszty usług miesiąca (Finanse : zamknij miesiąc)',
       'Coûts de services du mois (Finances : clôturer le mois)', 'monthly', 'wd2', 30, '/finances/costs', 'services_closed', 15, 1
FROM n_position p
WHERE p.code = 'ADMINISTRACJA' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/finances/costs');

-- « Kto co zrobił » (widok przełożonego) : stanowiska, którym ktoś podlega (Kierownik produkcji, Logistyka, Brygadzista) ;
-- administrator widzi zawsze, inne stanowiska dodaje w Uprawnieniach funkcji.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT DISTINCT p.id_reports_to, 'execution' FROM n_position p JOIN n_position s ON s.id = p.id_reports_to WHERE p.id_reports_to IS NOT NULL;
