-- [341] Un compte d'envoi par usage : finance (factures, proformas, relances de paiement), logistyka (informations de
-- livraison), produkcja (réponses de la production : non-conformités, réclamations). Les comptes existants deviennent
-- « finance ». Un message part du compte de son usage, sinon du compte finance.
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_email_account');
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_email_account' AND COLUMN_NAME = 'purpose');
SET @sql := IF(@t = 1 AND @c = 0, 'ALTER TABLE communication_email_account ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT ''finance'' COMMENT ''finance, logistics albo production'' AFTER name', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
