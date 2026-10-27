-- Poczta : tłumacz DeepL (wiadomości przychodzące, odpowiedź w języku klienta) i stopki e-maili w językach klientów
-- (27/10/2026). Rejouable.
-- 1. n_mail_footer : stopka firmy na język (pl, fr, nl, en) — Ustawienia › Stopki e-maili i tłumacz ; dodawana pod podpisem
--    w Poczcie i pod treścią e-maili automatycznych (dokumenty, przypomnienia, dostawy) zamiast stałej linii « SL Food ».
-- 2. n_user_mail.tr_* : ostatnie tłumaczenie wiadomości przychodzącej (język docelowy, wykryty język źródłowy, temat, treść)
--    — ponowne otwarcie nie kosztuje znaków DeepL.
-- 3. n_user_mail_sent.lang / original_text : język wysłanej wiadomości i tekst przed tłumaczeniem (ślad, co napisał operator).
-- 4. n_translation_log : zużycie znaków DeepL (kto, gdzie, ile) — limit dzienny na osobę, licznik w Ustawieniach.
-- 5. Parametry : stopka w Poczcie / w e-mailach automatycznych, limit znaków na osobę i dzień.
-- Klucz API : DEEPL_API_KEY w .env serwera albo wpisany w Ustawieniach (zaszyfrowany, punkt 6) — nigdy w repozytorium.

CREATE TABLE IF NOT EXISTS n_mail_footer (
    lang CHAR(2) NOT NULL PRIMARY KEY COMMENT 'pl | fr | nl | en',
    body TEXT NULL COMMENT 'tekst stopki (kilka linii), bez HTML',
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_user_mail' AND COLUMN_NAME = 'tr_lang');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_user_mail ADD COLUMN tr_lang CHAR(2) NULL COMMENT ''język tłumaczenia (docelowy)'' AFTER body_text,
        ADD COLUMN tr_source VARCHAR(8) NULL COMMENT ''język oryginału wykryty przez DeepL'' AFTER tr_lang,
        ADD COLUMN tr_subject VARCHAR(500) NULL AFTER tr_source,
        ADD COLUMN tr_text MEDIUMTEXT NULL AFTER tr_subject,
        ADD COLUMN tr_at DATETIME NULL AFTER tr_text',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_user_mail_sent' AND COLUMN_NAME = 'lang');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_user_mail_sent ADD COLUMN lang CHAR(2) NULL COMMENT ''język wiadomości (stopka)'' AFTER body_text,
        ADD COLUMN original_text MEDIUMTEXT NULL COMMENT ''treść przed tłumaczeniem (DeepL)'' AFTER lang',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_translation_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_user INT NULL,
    context ENUM('mail_in', 'mail_out', 'footer', 'communication') NOT NULL,
    chars INT NOT NULL,
    source_lang VARCHAR(8) NULL,
    target_lang VARCHAR(8) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_n_translation_log_user (id_user, created_at),
    KEY idx_n_translation_log_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'mail.footer_user_mail', '1', '1', 'bool', NULL,
    'Poczta : stopka firmy (w języku wiadomości) pod podpisem w wiadomościach wysyłanych z ERP',
    'Poczta : pied de page de l''entreprise (dans la langue du message) sous la signature des messages envoyés depuis l''ERP'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'mail.footer_user_mail');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'mail.footer_system', '1', '1', 'bool', NULL,
    'E-maile automatyczne (dokumenty, przypomnienia, dostawy) : stopka firmy w języku klienta zamiast linii « SL Food · L''Atelier By »',
    'E-mails automatiques (documents, rappels, livraisons) : pied de page dans la langue du client au lieu de la ligne « SL Food · L''Atelier By »'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'mail.footer_system');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'translate.daily_chars_per_user', '60000', '60000', 'int', 'znaków',
    'Tłumacz DeepL : najwyżej tyle znaków dziennie na osobę (koszt ; ok. 30 e-maili)',
    'Traducteur DeepL : au plus autant de caractères par jour et par personne (coût ; env. 30 e-mails)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'translate.daily_chars_per_user');

-- 6. n_app_secret : klucz DeepL wpisany w Ustawieniach (gdy nie ma go w .env), zaszyfrowany sekretem serwera
--    (AES-256-GCM, jak hasła skrzynek) ; ERP pokazuje tylko 4 ostatnie znaki.
CREATE TABLE IF NOT EXISTS n_app_secret (
    code VARCHAR(64) NOT NULL PRIMARY KEY,
    secret TEXT NOT NULL COMMENT 'zaszyfrowany (m1:…)',
    hint VARCHAR(16) NULL COMMENT '4 ostatnie znaki',
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
