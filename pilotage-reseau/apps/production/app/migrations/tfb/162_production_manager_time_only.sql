-- [262] Kierownik produkcji : na razie tylko Czas pracy (décision du 11/11/2026 : « déplacer pour le moment l'accès de la
--   session production à seulement les pointages, et petit à petit je lui donne du travail »).
--   - menu (rail) : jedna pozycja, Czas pracy ; strona startowa : /time ;
--   - uprawnienia do stron : tylko Czas pracy (inne strony → « Brak dostępu ») ;
--   - zadania stanowiska : wstrzymane (active = 0), poza nowym zadaniem nr 1 « Czas pracy dnia » (codziennie, zalicza się
--     samo, gdy dzień jest zatwierdzony — TaskWizards::time_day_validated).
--   Przywracanie krok po kroku : Administracja › Uprawnienia i menu (strony, menu, strona startowa) i Zadania stanowisk.
-- Jednorazowa (runner) : późniejsze zmiany administratora nie są nadpisywane.

DELETE m FROM n_position_menu m JOIN n_position p ON p.id = m.id_position WHERE p.code = 'KIEROWNIK_PRODUKCJI';
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'time', NULL FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

DELETE a FROM n_page_access a JOIN n_position p ON p.id = a.id_position WHERE p.code = 'KIEROWNIK_PRODUKCJI';
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'time' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

INSERT INTO n_position_home (id_position, home_page)
SELECT p.id, '/time' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI'
ON DUPLICATE KEY UPDATE home_page = VALUES(home_page);

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.active = 0
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND t.active = 1 AND COALESCE(t.auto_code, '') <> 'time_day_validated';

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
       'Czas pracy dnia : odbicia wejść i wyjść, powód każdej nadgodziny i nieobecności — zatwierdź dzień',
       'Temps de travail du jour : pointages entrée / sortie, motif de chaque heure sup. et de chaque absence — valider le jour',
       'daily', 10, '/time', 'time_day_validated', '16:00:00', 1, 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'time_day_validated');
