-- [345] Adresse d'envoi ↔ profil (stanowisko) : la boîte accounting@ est dans la Poczta de « Administracja i fakturowanie »,
-- logistyka@ dans celle de « Logistyka », produkcja@ dans celle du « Kierownik produkcji ». Réglé sur Konfiguracja poczty.
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_email_account');
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_email_account' AND COLUMN_NAME = 'position_code');
SET @sql := IF(@t = 1 AND @c = 0, 'ALTER TABLE communication_email_account ADD COLUMN position_code VARCHAR(40) NULL COMMENT ''n_position.code : Poczta tego stanowiska'' AFTER purpose', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@t = 1, 'UPDATE communication_email_account SET position_code = CASE purpose WHEN ''finance'' THEN ''ADMINISTRACJA'' WHEN ''logistics'' THEN ''LOGISTYKA'' WHEN ''production'' THEN ''KIEROWNIK_PRODUKCJI'' END WHERE position_code IS NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Skrzynka w Poczcie skopiowana z konta wysyłki (nie ustawiona ręcznie przez osobę) : aktualizowana razem z kontem.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_user_mailbox' AND COLUMN_NAME = 'id_email_account');
SET @sql := IF(@c = 0, 'ALTER TABLE n_user_mailbox ADD COLUMN id_email_account INT NULL COMMENT ''communication_email_account.id, gdy skrzynka pochodzi z konta stanowiska'' AFTER id_user', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
