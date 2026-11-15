-- [271] Rail « HR » (makiety 36–38, demande du 11/11/2026) : tous les employés en un tableau (contact, ancienneté,
--   contrat, départ à la retraite), documents importés (contrat, avenant, visite médicale, note…), notes sur l'employé
--   (publique = visible par le Kierownik produkcji et les finances ; privée = auteur et administrateur ; « do
--   administratora » = cloche de l'administrateur) regroupées en fin de mois (page + e-mail à l'administrateur).
-- Données personnelles chiffrées (HrCipher, AES-256-GCM, clé dérivée du secret du serveur) : téléphone, date de
--   naissance, contact d'urgence, texte des notes, contenu des documents. Chaque ouverture est notée (n_hr_log).
-- E-mail : le même que Czas pracy › E-maile pracowników (n_time_contact), un seul endroit.
-- Accès (clé « hr ») : administrateur, Kierownik produkcji, Administracja i fakturowanie (menu + droit).
CREATE TABLE IF NOT EXISTS n_hr_employee (
    id_employee   INT NOT NULL PRIMARY KEY COMMENT 'n_prod_employee.id',
    phone_enc     VARCHAR(400) NULL COMMENT 'téléphone chiffré (HrCipher)',
    birth_enc     VARCHAR(400) NULL COMMENT 'date de naissance AAAA-MM-JJ chiffrée (HrCipher)',
    gender        ENUM('K', 'M') NULL COMMENT 'K = kobieta, M = mężczyzna (âge de la retraite)',
    hire_date     DATE NULL,
    contract_type ENUM('nieokreslony', 'okreslony', 'probny', 'zlecenie', 'inna') NULL,
    contract_end  DATE NULL,
    work_time     VARCHAR(30) NULL COMMENT 'wymiar : pełny etat, 1/2 etatu…',
    medical_until DATE NULL COMMENT 'badania lekarskie ważne do',
    emergency_enc VARCHAR(700) NULL COMMENT 'contact en cas d''urgence chiffré (HrCipher)',
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_document (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    kind        ENUM('umowa', 'aneks', 'badania', 'notatka', 'inne') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    filename    VARCHAR(200) NOT NULL,
    mime        VARCHAR(80) NOT NULL,
    size        INT UNSIGNED NOT NULL,
    doc_date    DATE NULL,
    content     MEDIUMBLOB NOT NULL COMMENT 'fichier chiffré (HrCipher)',
    uploaded_by INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_n_hr_document_employee (id_employee, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_note (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    body_enc    TEXT NOT NULL COMMENT 'texte chiffré (HrCipher)',
    visibility  ENUM('public', 'private') NOT NULL DEFAULT 'private',
    to_admin    TINYINT(1) NOT NULL DEFAULT 0,
    author      INT NULL COMMENT 'user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_n_hr_note_employee (id_employee, created_at),
    KEY idx_n_hr_note_month (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_log (
    id          BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_user     INT NULL,
    id_employee INT NULL,
    action      VARCHAR(40) NOT NULL COMMENT 'list, card, document, note, save, upload, csv, summary',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_n_hr_log_employee (id_employee, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_summary (
    month      CHAR(7) NOT NULL PRIMARY KEY,
    notes      INT NOT NULL DEFAULT 0,
    recipients INT NOT NULL DEFAULT 0,
    sent_by    INT NULL COMMENT 'NULL = automatique (cron)',
    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('hr.retire_age_k', '60', '60', 'int', 'lat', 'HR : wiek emerytalny — kobiety', 'RH : âge de la retraite — femmes'),
('hr.retire_age_m', '65', '65', 'int', 'lat', 'HR : wiek emerytalny — mężczyźni', 'RH : âge de la retraite — hommes'),
('hr.alert_days', '30', '30', 'int', 'dni', 'HR : przypomnienie przed końcem umowy i badań lekarskich (dni)', 'RH : rappel avant la fin du contrat et de la visite médicale (jours)');

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'hr' FROM n_position p WHERE p.code IN ('KIEROWNIK_PRODUKCJI', 'ADMINISTRACJA');

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'hr', NULL FROM n_position p WHERE p.code IN ('KIEROWNIK_PRODUKCJI', 'ADMINISTRACJA')
  AND EXISTS (SELECT 1 FROM n_position_menu m WHERE m.id_position = p.id);
