-- [361] Tablette de bureau : l'accueil est le clavier PIN, le PIN seul ouvre le profil de la personne. Tous les PIN
-- doivent donc être différents (tablette, back-office : Tablety › Zarządzanie, création d'un employé, PIN du chauffeur).
--  - n_prod_employee_credential.pin_lookup : empreinte HMAC du PIN (clé du serveur), unique — retrouve la personne
--    sans parcourir tous les bcrypt et interdit les doublons ; remplie à la prochaine connexion de chacun (les PIN
--    existants ne sont connus qu'en bcrypt) ;
--  - pin_conflict : deux personnes avaient le même PIN (découvert à la connexion) → passage par le nom, puis nouveau PIN ;
--  - n_prod_device.pin_failed / pin_locked_until : PIN inconnus tapés sur la tablette → blocage de la tablette
--    (kiosk.office_pin_max_attempts essais, puis kiosk.lock_minutes).
-- Rejouable : information_schema, INSERT … WHERE NOT EXISTS.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_employee_credential' AND COLUMN_NAME = 'pin_lookup');
SET @sql := IF(@c = 0, 'ALTER TABLE n_prod_employee_credential
        ADD COLUMN pin_lookup CHAR(64) NULL COMMENT ''HMAC-SHA256 du PIN : unique, connexion par PIN seul [361]'' AFTER pin_hash,
        ADD COLUMN pin_conflict TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''PIN identique à celui d une autre personne : à changer [361]'' AFTER pin_lookup,
        ADD UNIQUE KEY uq_credential_pin_lookup (pin_lookup)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_device' AND COLUMN_NAME = 'pin_failed');
SET @sql := IF(@c = 0, 'ALTER TABLE n_prod_device
        ADD COLUMN pin_failed SMALLINT NOT NULL DEFAULT 0 COMMENT ''PIN inconnus d affilée (accueil PIN) [361]'',
        ADD COLUMN pin_locked_until DATETIME NULL COMMENT ''tablette bloquée après trop de PIN inconnus [361]''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.office_pin_max_attempts', '5', '5', 'int', NULL,
    'Tablet biurowy : nieznane PIN-y z rzędu przed blokadą tabletu (na kiosk.lock_minutes)',
    'Tablette de bureau : PIN inconnus d''affilée avant blocage de la tablette (pendant kiosk.lock_minutes)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.office_pin_max_attempts');
