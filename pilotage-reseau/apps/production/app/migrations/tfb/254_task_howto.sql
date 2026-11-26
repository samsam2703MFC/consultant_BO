-- [422] Feuille de fonction : « Zadania i jak je wykonać » sur la karta stanowiska. Une tâche sans assistant (tâche
-- manuelle) reçoit son mode d'emploi, écrit par l'administrateur dans Administracja › Zadania stanowisk :
--   - how_pl / how_fr : les étapes « Jak », une étape par ligne (phrases courtes, langage simple) ;
--   - problem_pl / problem_fr : la ligne « Problem ? » (qui appeler, que faire).
-- Les tâches d'assistant (auto_code) ont leurs étapes dans le code (App\Services\Organization\TaskHowTo), pas ici.
-- Vide = la karta montre la page de la tâche et une ligne neutre. Rejouable : ALTER conditionnel.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'how_pl');
SET @sql := IF(@c = 0, 'ALTER TABLE n_position_task
    ADD COLUMN how_pl TEXT NULL COMMENT ''karta stanowiska : jak wykonać zadanie (PL), jeden krok na linię'',
    ADD COLUMN how_fr TEXT NULL COMMENT ''karta stanowiska : jak wykonać zadanie (FR), jeden krok na linię'',
    ADD COLUMN problem_pl VARCHAR(300) NULL COMMENT ''karta stanowiska : « Problem ? » — kogo wołać, co zrobić (PL)'',
    ADD COLUMN problem_fr VARCHAR(300) NULL COMMENT ''karta stanowiska : « Problem ? » (FR)''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
