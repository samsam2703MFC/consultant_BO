-- Feuille de route hebdomadaire [219] (Dzisiaj « Mój tydzień » + Funkcje i zadania › Plan tygodnia stanowiska) : chaque
-- tâche fixe du poste a sa durée en minutes (fixée par l'administrateur), ses jours (tâche quotidienne limitée à certains
-- jours de la semaine) et, en option, une validation automatique quand l'assistant lié est terminé (factures vérifiées,
-- coûts recalculés, nouveaux prix planifiés, demandes d'offres envoyées). Conditionnel et rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'minutes') = 0,
    'ALTER TABLE n_position_task
        ADD COLUMN minutes SMALLINT UNSIGNED NULL COMMENT ''czas jednego wykonania (min) — ustala administrator'' AFTER due_weekday,
        ADD COLUMN weekdays VARCHAR(13) NULL COMMENT ''zadanie codzienne tylko w te dni (1 = pon.), np. 1,4 ; NULL = wszystkie dni robocze'' AFTER minutes,
        ADD COLUMN auto_code VARCHAR(32) NULL COMMENT ''zalicza się samo : inv_checked, costs_recalc, prices_planned, buy_sent'' AFTER form_code',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Plan tygodnia stanowiska Zakupy (makieta 1 i 2) : minuty i dni zadań, które już są (tylko puste pola) …
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.minutes = CASE
        WHEN t.frequency = 'daily' AND t.link_path = '/purchasing/nc' THEN 10
        WHEN t.link_path = '/workshop/buy' THEN 40
        WHEN t.link_path = '/workshop/buy#tab=suppliers' THEN 30
        WHEN t.link_path = '/purchasing/offers' THEN 15
        WHEN t.link_path = '/materials/prices' THEN 15
        WHEN t.link_path = '/workshop/supply#tab=invoices' AND t.due_weekday = 2 THEN 20
        WHEN t.link_path = '/workshop/supply#tab=invoices' AND t.due_weekday = 3 THEN 25
        ELSE t.minutes END
WHERE p.code = 'ZAKUPY' AND t.active = 1 AND t.minutes IS NULL AND t.frequency IN ('daily', 'weekly');

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.due_weekday = 2
WHERE p.code = 'ZAKUPY' AND t.frequency = 'weekly' AND t.due_weekday IS NULL AND t.link_path = '/purchasing/offers';

-- … « Zaimportować ceny z faktur » (środa) = kreator faktur [217] : link prosto do kreatora, zalicza się samo, gdy pozycje są sprawdzone …
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.link_path = '/workshop/supply#tab=invoices&inv=wizard', t.auto_code = 'inv_checked'
WHERE p.code = 'ZAKUPY' AND t.frequency = 'weekly' AND t.due_weekday = 3 AND t.link_path = '/workshop/supply#tab=invoices' AND t.auto_code IS NULL;

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.auto_code = 'buy_sent'
WHERE p.code = 'ZAKUPY' AND t.link_path = '/workshop/buy' AND t.auto_code IS NULL;

-- … i nowe zadania kreatora cen [218] : koszty po fakturach (środa), nowe ceny (czwartek), kontrola publikacji (piątek).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'MARZE'),
    'Przelicz koszty i marże po fakturach (Kreator cen, krok 1)',
    'Recalculer les coûts et marges après les factures (assistant des prix, étape 1)',
    'weekly', 3, 20, '/finances/price-wizard', 'costs_recalc', 7, 1
FROM n_position p
WHERE p.code = 'ZAKUPY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/finances/price-wizard');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'CENY'),
    'Nowe ceny : marża bazowa + współczynnik, publikacja od daty (Kreator cen, krok 2–3)',
    'Nouveaux prix : marge de base + coefficient, publication à date (assistant des prix, étapes 2–3)',
    'weekly', 4, 30, '/finances/price-wizard#step=2', 'prices_planned', 8, 1
FROM n_position p
WHERE p.code = 'ZAKUPY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/finances/price-wizard#step=2');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'CENY'),
    'Sprawdź publikację cen (strona franczyzy, e-maile do klientów)',
    'Vérifier la publication des prix (site franchise, e-mails aux clients)',
    'weekly', 5, 10, '/finances/price-wizard#step=3', 9, 1
FROM n_position p
WHERE p.code = 'ZAKUPY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/finances/price-wizard#step=3');
