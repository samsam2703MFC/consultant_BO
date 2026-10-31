-- [237] [238] Stanowisko Tarty i zastępstwo zadań.
--
-- [238] Zastępstwo : zadanie ma stanowisko zastępcze (id_backup_position). Gdy na stanowisku zadania nikogo nie ma
--   (brak konta ERP na stanowisku), zadanie pokazuje się, przypomina i liczy u stanowiska zastępczego (Moje zadania,
--   Mój tydzień, Kto co zrobił, Zadania zespołu, przypomnienia). Gdy ktoś obejmie stanowisko — wraca do niego samo.
--   « Kontrola przyjęć dnia » : jedno zadanie u Jakości (zastępstwo : Kierownik produkcji) ; kopia u Kierownika
--   wyłączona (nic nie usunięte, historia zostaje).
-- [237] Tarty : pełnoprawna grupa — własne stanowisko « Pracownik tart » (pracownia, grupa Tarty, przełożony : Brygadzista),
--   karta stanowiska do przejrzenia i zadanie dnia « Dzień zatwierdzony na tablecie — Tarty », jak inne grupy.
-- Rejouable.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'id_backup_position');
SET @s := IF(@c = 0,
    'ALTER TABLE n_position_task ADD COLUMN id_backup_position INT NULL COMMENT ''zastępstwo : stanowisko, które robi zadanie, gdy na stanowisku zadania nikogo nie ma'' AFTER id_position',
    'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @kier := (SELECT id FROM n_position WHERE code = 'KIEROWNIK_PRODUKCJI' LIMIT 1);
SET @jak := (SELECT id FROM n_position WHERE code = 'JAKOSC' LIMIT 1);
SET @jak_task := (SELECT COUNT(*) FROM n_position_task WHERE id_position = @jak AND auto_code = 'receiving_checked' AND active = 1);

UPDATE n_position_task SET id_backup_position = @kier
WHERE id_position = @jak AND auto_code = 'receiving_checked' AND id_backup_position IS NULL AND @kier IS NOT NULL;

UPDATE n_position_task SET active = 0
WHERE id_position = @kier AND auto_code = 'receiving_checked' AND active = 1 AND @jak_task > 0;

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'TARTY', 'Pracownik tart', 'Préparateur de tartes', 'atelier', g.id, 75, 1 FROM n_prod_group g
WHERE g.code = 'TARTY' AND NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'TARTY');

UPDATE n_position p JOIN n_position b ON b.code = 'BRYGADZISTA' SET p.id_reports_to = b.id WHERE p.code = 'TARTY' AND p.id_reports_to IS NULL;

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
       'Dzień zatwierdzony na tablecie — Tarty (plan → zrobione → straty → zatwierdź)',
       'Journée validée sur la tablette — Tarty (plan → fait → pertes → valider)',
       'daily', 10, '/production/follow-up', 'group_day_closed', NULL, NULL, 5, 1
FROM n_position p
WHERE p.code = 'TARTY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'group_day_closed');

-- Karta stanowiska pre-wypełniona (do przejrzenia i zatwierdzenia w ERP).
INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Wytwarza tarty (spody, nadzienia, pieczenie, wykończenie) zgodnie z recepturami i planem dnia, w jakości L’Atelier.',
       'Fabrique les tartes (fonds, garnitures, cuisson, finition) selon les recettes et le plan du jour, dans la qualité L’Atelier.',
       'Ciasto kruche i spody tart
Nadzienia, kremy i owoce
Pieczenie i schładzanie
Wykończenie (glazura, dekoracja) i kontrola wyglądu i wagi
Zapis produkcji, partii surowców i strat na tablecie grupy', 'Pâte sucrée et fonds de tarte
Garnitures, crèmes et fruits
Cuisson et refroidissement
Finition (nappage, décor) et contrôle de l’aspect et du poids
Saisie de la production, des lots de matières et des pertes sur la tablette du groupe',
       'Technologia cukiernicza (ciasta kruche, kremy)
Precyzja i powtarzalność', 'Technologie pâtissière (pâtes sucrées, crèmes)
Précision et régularité',
       'Zawód cukiernik albo doświadczenie w produkcji ciast', 'CAP pâtissier ou expérience en production de pâtisserie',
       'Hala, zmiana dzienna, praca w chłodni', 'Atelier, équipe de jour, travail en chambre froide',
       'Plan dnia wykonany
Straty w %
Jakość i waga tart', 'Plan du jour réalisé
Pertes en %
Qualité et poids des tartes',
       'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Łańcuch chłodniczy : temperatury
Alergeny (gluten, orzechy, mleko, jaja)', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Chaîne du froid : températures
Allergènes (gluten, fruits à coque, lait, œufs)',
       'Brygadzista, jakość, pakowanie', 'Chef d’équipe, qualité, conditionnement'
FROM n_position p WHERE p.code = 'TARTY'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);
