-- Module « Gestion de production atelier » — référentiels : groupes, postes, shifts, équipes, employés.

-- Groupe de production (lignes du Gantt). id_section relie, si besoin, aux sections de l'ERP
-- (production_sections) qui portent la répartition des coûts.
CREATE TABLE IF NOT EXISTS tfb_prod_group (
    id          INT NOT NULL AUTO_INCREMENT,
    code        VARCHAR(32)  NOT NULL,
    name_pl     VARCHAR(100) NOT NULL,
    name_fr     VARCHAR(100) NOT NULL,
    color       CHAR(7)      NOT NULL DEFAULT '#8D1D2C',
    sort_order  INT          NOT NULL DEFAULT 0,
    id_section  INT          NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_group_code (code),
    CONSTRAINT fk_tfb_group_section FOREIGN KEY (id_section) REFERENCES production_sections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Modèle de shift. Un shift dont la fin est avant le début passe minuit (Nuit 22–06).
-- active_days : jours ISO séparés par des virgules (1 = lundi … 7 = dimanche).
CREATE TABLE IF NOT EXISTS tfb_prod_shift_template (
    id           INT NOT NULL AUTO_INCREMENT,
    code         VARCHAR(32)  NOT NULL,
    name_pl      VARCHAR(100) NOT NULL,
    name_fr      VARCHAR(100) NOT NULL,
    start_time   TIME         NOT NULL,
    end_time     TIME         NOT NULL,
    active_days  VARCHAR(13)  NOT NULL DEFAULT '1,2,3,4,5,6',
    premium_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_night     TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order   INT          NOT NULL DEFAULT 0,
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_shift_code (code),
    CONSTRAINT ck_tfb_shift_premium CHECK (premium_pct >= 0 AND premium_pct <= 200),
    CONSTRAINT ck_tfb_shift_times CHECK (start_time <> end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Poste de travail. robot_mode est obligatoire pour un robot et interdit sinon.
CREATE TABLE IF NOT EXISTS tfb_prod_workstation (
    id                 INT NOT NULL AUTO_INCREMENT,
    code               VARCHAR(32)  NOT NULL,
    id_group           INT          NOT NULL,
    name_pl            VARCHAR(100) NOT NULL,
    name_fr            VARCHAR(100) NOT NULL,
    type               ENUM('machine','four','robot','manuel','fermentation','froid','surgelation') NOT NULL,
    robot_mode         ENUM('autonome','avec_operateur') NULL,
    capacity_per_pass  DECIMAL(10,2) NOT NULL DEFAULT 1,
    capacity_unit      VARCHAR(16)  NOT NULL DEFAULT 'piece',
    hourly_cost        DECIMAL(10,2) NOT NULL DEFAULT 0,
    photo_url          VARCHAR(1024) NULL,
    notes              VARCHAR(500) NULL,
    active             TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_workstation_code (code),
    KEY ix_tfb_workstation_group (id_group),
    CONSTRAINT fk_tfb_workstation_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id),
    CONSTRAINT ck_tfb_workstation_robot CHECK ((type = 'robot') = (robot_mode IS NOT NULL)),
    CONSTRAINT ck_tfb_workstation_capacity CHECK (capacity_per_pass > 0),
    CONSTRAINT ck_tfb_workstation_cost CHECK (hourly_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Disponibilité d'un poste par shift (absence de ligne = disponible).
CREATE TABLE IF NOT EXISTS tfb_prod_workstation_shift (
    id_workstation     INT NOT NULL,
    id_shift_template  INT NOT NULL,
    available          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_workstation, id_shift_template),
    CONSTRAINT fk_tfb_ws_shift_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_ws_shift_shift FOREIGN KEY (id_shift_template) REFERENCES tfb_prod_shift_template (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Profil de production d'un employé de l'atelier. L'employé reste celui de l'ERP
-- (production_employee) ; le panel TFB est maître pour l'identité, les postes et les niveaux :
-- panel_employee_id le relie, level_* est une copie du niveau lu dans le panel.
CREATE TABLE IF NOT EXISTS tfb_prod_employee (
    id                     INT NOT NULL AUTO_INCREMENT,
    id_production_employee INT          NOT NULL,
    panel_employee_id      INT          NULL,
    id_primary_group       INT          NULL,
    level_order            INT          NULL,
    level_name             VARCHAR(100) NULL,
    level_synced_at        DATETIME     NULL,
    night_supervisor       TINYINT(1)   NOT NULL DEFAULT 0,
    active                 TINYINT(1)   NOT NULL DEFAULT 1,
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_employee_erp (id_production_employee),
    UNIQUE KEY uq_tfb_employee_panel (panel_employee_id),
    CONSTRAINT fk_tfb_employee_erp FOREIGN KEY (id_production_employee) REFERENCES production_employee (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_employee_group FOREIGN KEY (id_primary_group) REFERENCES tfb_prod_group (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Équipe = groupe + shift + effectif prévu.
CREATE TABLE IF NOT EXISTS tfb_prod_team (
    id                 INT NOT NULL AUTO_INCREMENT,
    code               VARCHAR(32)  NOT NULL,
    id_group           INT          NOT NULL,
    id_shift_template  INT          NOT NULL,
    name               VARCHAR(100) NOT NULL,
    planned_headcount  INT          NOT NULL DEFAULT 0,
    active             TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_team_code (code),
    KEY ix_tfb_team_group_shift (id_group, id_shift_template),
    CONSTRAINT fk_tfb_team_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id),
    CONSTRAINT fk_tfb_team_shift FOREIGN KEY (id_shift_template) REFERENCES tfb_prod_shift_template (id),
    CONSTRAINT ck_tfb_team_headcount CHECK (planned_headcount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_team_member (
    id           INT NOT NULL AUTO_INCREMENT,
    id_team      INT NOT NULL,
    id_employee  INT NOT NULL,
    is_lead      TINYINT(1) NOT NULL DEFAULT 0,
    valid_from   DATE NULL,
    valid_to     DATE NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_team_member (id_team, id_employee),
    CONSTRAINT fk_tfb_team_member_team FOREIGN KEY (id_team) REFERENCES tfb_prod_team (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_team_member_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_team_member_dates CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Le rôle « chef d'équipe » peut être limité à un groupe.
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_user_role'
                     AND CONSTRAINT_NAME = 'fk_tfb_user_role_group');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE tfb_prod_user_role ADD CONSTRAINT fk_tfb_user_role_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
