-- Pointage signé : la personne signe sur la tablette ou sur son téléphone dans l'écran de validation de l'entrée et de
-- la sortie (avec les questions), avant « OK, zatwierdzam » ; sans signature, pas de pointage (422 SIGNATURE_REQUIRED).
-- n_prod_signature.purpose reçoit « clock_in » (signature de l'entrée, ref = id du pointage).
-- kiosk.signature_on_clock_in (1) : signature à l'entrée (liste de présence) ; kiosk.signature_on_clock_out (1) : à la sortie.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_signature'
        AND COLUMN_NAME = 'purpose' AND COLUMN_TYPE NOT LIKE '%''clock_in''%') = 1,
    'ALTER TABLE n_prod_signature MODIFY COLUMN purpose ENUM(''clock_in'',''clock_out'',''timesheet_month'',''operation'') NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.signature_on_clock_in', '1', '1', 'bool', NULL,
    'Podpis przy wejściu (lista obecności) — obowiązkowy na tablecie albo telefonie',
    'Signature à l''entrée (liste de présence) — obligatoire sur la tablette ou le téléphone'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.signature_on_clock_in');

UPDATE n_prod_param
SET description_pl = 'Podpis przy wyjściu (potwierdzenie godzin) — obowiązkowy na tablecie albo telefonie',
    description_fr = 'Signature à la sortie (confirmation des heures) — obligatoire sur la tablette ou le téléphone'
WHERE code = 'kiosk.signature_on_clock_out';
