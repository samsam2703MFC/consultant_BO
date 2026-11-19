-- [365] Heures de nuit de 21:00 à 05:00 (décision du 19/11/2026 ; avant : 22:00–06:00 écrit dans le code), totaux par semaine et par
-- mois sur la feuille de temps, remarque du jour à la sortie. Plage réglable dans
-- Ustawienia › Parametry : time.night_start, time.night_end (HH:MM ; fin plus tôt que le début = le lendemain).
-- Feuille de temps mensuelle (tablette, PDF) et liste des heures par personne. Rejouable : INSERT … WHERE NOT EXISTS.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'time.night_start', '21:00', '21:00', 'time', NULL,
    'Początek pory nocnej (godziny nocne w karcie czasu pracy)', 'Début des heures de nuit (feuille de temps)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'time.night_start');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'time.night_end', '05:00', '05:00', 'time', NULL,
    'Koniec pory nocnej (następnego dnia, jeśli wcześniej niż początek)', 'Fin des heures de nuit (le lendemain si plus tôt que le début)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'time.night_end');

-- [365] Remarque du jour : à la sortie (fin de journée), la personne peut écrire une remarque (facultative), reprise sur la
-- feuille de temps imprimée en fin de mois (colonne Uwagi). Distincte de note (motif d'une correction du responsable).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_clock' AND COLUMN_NAME = 'day_note');
SET @sql := IF(@c = 0, 'ALTER TABLE n_prod_clock ADD COLUMN day_note VARCHAR(500) NULL COMMENT ''remarque de la personne à la sortie [365]'' AFTER note', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
