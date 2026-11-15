-- [278] HR › Sukcesja (makieta 43, validée le 12/11/2026) : toute personne qui part à la retraite dans les 24 mois
--   (hr.succession_months) reçoit automatiquement un plan de relève — 1. audit du poste et des savoirs, 2. recrutement /
--   choix du successeur, 3. formation en binôme, 4. passage progressif (agenda 80 → 60 → 40 %, seulement avec l'accord
--   de la personne : aneks), 5. dernière passation et entretien final. Étapes datées à rebours depuis le départ, cases à
--   cocher, responsable ; tâches dans « Mój tydzień » du responsable, cloche quand une étape est en retard. Autres départs
--   (démission, long congé) : plan ajouté à la main. Rejouable : CREATE IF NOT EXISTS, INSERT IGNORE.
CREATE TABLE IF NOT EXISTS n_hr_succession (
    id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_employee        INT NOT NULL COMMENT 'n_prod_employee.id (personne qui part)',
    reason             ENUM('emerytura', 'wypowiedzenie', 'urlop', 'inne') NOT NULL DEFAULT 'emerytura',
    departure_date     DATE NOT NULL,
    successor_employee INT NULL COMMENT 'n_prod_employee.id du successeur',
    successor_note     VARCHAR(200) NULL COMMENT 'rekrutacja zewnętrzna, kandydat…',
    responsible_user   INT NULL COMMENT 'user.id responsable du plan (Mój tydzień)',
    status             ENUM('active', 'done', 'cancelled') NOT NULL DEFAULT 'active',
    auto               TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ouvert automatiquement (retraite)',
    created_by         INT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at          DATETIME NULL,
    KEY idx_n_hr_succession_employee (id_employee, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_succession_step (
    id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_plan          INT NOT NULL,
    step_no          TINYINT NOT NULL COMMENT '1 audit, 2 rekrutacja, 3 tandem, 4 stopniowe przekazanie, 5 ostatnie przekazanie',
    title            VARCHAR(120) NOT NULL,
    start_date       DATE NOT NULL,
    due_date         DATE NOT NULL,
    done_at          DATETIME NULL COMMENT 'toutes les cases cochées',
    UNIQUE KEY uq_n_hr_succession_step (id_plan, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_hr_succession_item (
    id       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_step  INT NOT NULL,
    label    VARCHAR(200) NOT NULL,
    code     VARCHAR(30) NULL COMMENT 'successor = coché quand le successeur est choisi',
    sort     INT NOT NULL DEFAULT 0,
    done_at  DATETIME NULL,
    done_by  INT NULL,
    KEY idx_n_hr_succession_item_step (id_step, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Agenda du passage progressif (étape 4) : période, part du temps sur le poste, remarque.
CREATE TABLE IF NOT EXISTS n_hr_succession_agenda (
    id        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_plan   INT NOT NULL,
    date_from DATE NOT NULL,
    date_to   DATE NOT NULL,
    own_pct   TINYINT NOT NULL COMMENT 'part du temps encore sur le poste (100, 80, 60, 40, 0)',
    note      VARCHAR(255) NULL,
    KEY idx_n_hr_succession_agenda_plan (id_plan, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('hr.succession_months', '24', '24', 'int', 'mies.', 'HR : plan sukcesji otwierany automatycznie, gdy do emerytury zostaje najwyżej', 'RH : plan de relève ouvert automatiquement quand la retraite est dans au plus');
