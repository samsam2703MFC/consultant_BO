-- [266] Karta czasu pracy par e-mail (demande du 11/11/2026) : adresse e-mail de chaque salarié, encodée dans
--   Czas pracy › Miesiąc, et envoi de sa karta du mois (PDF) par la file d'envoi commune (communication_message).
--   L'adresse est une donnée personnelle : gardée chiffrée (AES-256-GCM, clé dérivée du secret du serveur), jamais en clair.
-- Rejouable : CREATE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_time_contact (
    id_employee INT NOT NULL PRIMARY KEY COMMENT 'n_prod_employee.id',
    email_enc   VARCHAR(700) NOT NULL COMMENT 'adresse e-mail chiffrée (StaffContactCipher)',
    updated_by  INT NULL COMMENT 'user.id',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Journal des karty envoyées : qui, quel mois, quel message de la file (statut de l'envoi lu dans communication_message).
CREATE TABLE IF NOT EXISTS n_time_sheet_mail (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    month       CHAR(7) NOT NULL COMMENT 'AAAA-MM',
    id_message  BIGINT UNSIGNED NULL COMMENT 'communication_message.id',
    sent_by     INT NULL COMMENT 'user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_n_time_sheet_mail (month, id_employee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
