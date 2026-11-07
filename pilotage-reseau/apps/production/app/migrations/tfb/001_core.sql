-- Module « Gestion de production atelier » — socle : paramètres, rôles, jetons, journal.
-- Appliqué par Bin/tfb_migrate.php (jamais à la main). Chaque instruction est rejouable.

-- Paramètres métier [PARAM] : toute valeur modifiable vit ici, avec sa valeur par défaut.
CREATE TABLE IF NOT EXISTS tfb_prod_param (
    code            VARCHAR(64)  NOT NULL,
    value           TEXT         NOT NULL,
    default_value   TEXT         NOT NULL,
    value_type      ENUM('int','decimal','string','bool','time','json') NOT NULL,
    unit            VARCHAR(16)  NULL,
    description_pl  VARCHAR(255) NOT NULL,
    description_fr  VARCHAR(255) NOT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT          NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rôles et permissions atomiques, refus par défaut (ADR-010).
CREATE TABLE IF NOT EXISTS tfb_prod_role (
    code     VARCHAR(32)  NOT NULL,
    name_pl  VARCHAR(100) NOT NULL,
    name_fr  VARCHAR(100) NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_role_permission (
    role_code   VARCHAR(32) NOT NULL,
    permission  VARCHAR(64) NOT NULL,
    PRIMARY KEY (role_code, permission),
    CONSTRAINT fk_tfb_role_perm_role FOREIGN KEY (role_code) REFERENCES tfb_prod_role (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rôle d'un utilisateur de l'ERP (table user), éventuellement limité à un groupe de production.
CREATE TABLE IF NOT EXISTS tfb_prod_user_role (
    id          INT NOT NULL AUTO_INCREMENT,
    id_user     INT NOT NULL,
    role_code   VARCHAR(32) NOT NULL,
    id_group    INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_user_role (id_user, role_code, id_group),
    CONSTRAINT fk_tfb_user_role_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_user_role_role FOREIGN KEY (role_code) REFERENCES tfb_prod_role (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Jetons de renouvellement (seule leur empreinte SHA-256 est stockée ; rotation par famille).
CREATE TABLE IF NOT EXISTS tfb_prod_refresh_token (
    id           BIGINT NOT NULL AUTO_INCREMENT,
    id_user      INT NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    family_id    CHAR(32) NOT NULL,
    expires_at   DATETIME NOT NULL,
    revoked_at   DATETIME NULL,
    replaced_by  BIGINT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_agent   VARCHAR(255) NULL,
    ip           VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_refresh_hash (token_hash),
    KEY ix_tfb_refresh_family (family_id),
    CONSTRAINT fk_tfb_refresh_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tentatives de connexion à l'API (limitation des essais).
CREATE TABLE IF NOT EXISTS tfb_prod_login_attempt (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    login       VARCHAR(255) NOT NULL,
    ip          VARCHAR(45) NOT NULL,
    success     TINYINT(1) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_login_attempt (ip, login, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Journal de toutes les écritures faites par l'API du module.
CREATE TABLE IF NOT EXISTS tfb_prod_audit_log (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_user     INT NULL,
    action      VARCHAR(32) NOT NULL,
    entity      VARCHAR(64) NOT NULL,
    entity_id   VARCHAR(64) NULL,
    before_json JSON NULL,
    after_json  JSON NULL,
    request_id  CHAR(32) NULL,
    PRIMARY KEY (id),
    KEY ix_tfb_audit_entity (entity, entity_id),
    KEY ix_tfb_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
