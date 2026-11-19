-- [300] Logowanie do ERP kodem QR (makieta 55, validée le 16/11/2026) : la page de connexion affiche un QR ; la personne le
--   scanne avec son téléphone enregistré (Czas pracy), pointe son entrée sur le téléphone (questions, signature) et valide :
--   le navigateur qui affichait le QR ouvre le compte ERP lié à cette personne (n_user_employee) — Anna : Produkcja,
--   Asia : Finanse, administrateurs : tout l'ERP. Mot de passe gardé en secours (lien discret).
--   Une demande = un navigateur (jeton aléatoire dans sa session, haché ici) ; le QR seul n'ouvre rien.
CREATE TABLE IF NOT EXISTS n_erp_login_request (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nonce_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton gardé dans la session du navigateur',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME NOT NULL,
    user_agent   VARCHAR(160) NULL COMMENT 'navigateur qui affiche le QR (montré sur le téléphone)',
    id_employee  INT NULL COMMENT 'personne qui a validé (n_prod_employee.id)',
    id_user      INT NULL COMMENT 'compte ouvert (user.id)',
    approved_at  DATETIME NULL,
    used_at      DATETIME NULL COMMENT 'session ouverte dans le navigateur',
    UNIQUE KEY uq_n_erp_login_nonce (nonce_hash),
    KEY ix_n_erp_login_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('erp.login_qr_ttl_s', '180', '180', 'int', 's', 'ERP : ważność kodu QR logowania (odnawiany sam)', 'ERP : validité du QR de connexion (renouvelé tout seul)');
