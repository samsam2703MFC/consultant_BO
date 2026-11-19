-- [347] Poczta › Ustawienia skrzynki : la personne (ou l'administrateur dans la session d'un poste) choisit une des boîtes
-- déjà configurées (accounting@, logistyka@, produkcja@) au lieu de retaper serveur et mot de passe. Ce choix est gardé
-- même hors du poste lié au compte ; il suit les changements du compte (mot de passe, serveur).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_user_mailbox' AND COLUMN_NAME = 'account_chosen');
SET @sql := IF(@c = 0, 'ALTER TABLE n_user_mailbox ADD COLUMN account_chosen TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 : konto firmowe wybrane w Ustawieniach skrzynki'' AFTER id_email_account', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
