-- [273] Kierownik produkcji : odpowiedzialność za czas pracy (demande du 11/11/2026) —
--   codziennie : weryfikacja odbić i zatwierdzenie dnia (zadanie z migracji 162, opis doprecyzowany) ;
--   co miesiąc : sprawdzenie i zamknięcie poprzedniego miesiąca (karty czasu pracy) do 3. dnia roboczego,
--   zalicza się samo po zamknięciu miesiąca w Czas pracy › Miesiąc (TaskWizards::time_month_closed).
--   Karta stanowiska : odpowiedzialność dopisana (raz).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.title_pl = 'Weryfikacja odbić dnia : wejścia i wyjścia, powód każdej nadgodziny i nieobecności — zatwierdź dzień',
    t.title_fr = 'Vérification des pointages du jour : entrées et sorties, motif de chaque heure sup. et absence — valider le jour'
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND t.auto_code = 'time_day_validated';

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
       'Czas pracy miesiąca : sprawdź miesiąc wszystkich (godziny, nadgodziny, urlopy), zatwierdź i zamknij miesiąc — karty czasu pracy',
       'Temps de travail du mois : contrôler le mois de tous (heures, heures sup., congés), valider et clôturer le mois — fiches de temps',
       'monthly', 30, 'wd3', '/time/month', 'time_month_closed', '09:00:00', 2, 2, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'time_month_closed');

UPDATE n_position_sheet s JOIN n_position p ON p.id = s.id_position
SET s.responsibilities_pl = CONCAT('Czas pracy zespołu : weryfikacja odbić i zatwierdzenie dnia (codziennie), zatwierdzenie i zamknięcie miesiąca (co miesiąc)\n', COALESCE(s.responsibilities_pl, '')),
    s.responsibilities_fr = CONCAT('Temps de travail de l''équipe : vérification des pointages et validation du jour (chaque jour), validation et clôture du mois (chaque mois)\n', COALESCE(s.responsibilities_fr, ''))
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND COALESCE(s.responsibilities_pl, '') NOT LIKE 'Czas pracy zespołu%';
