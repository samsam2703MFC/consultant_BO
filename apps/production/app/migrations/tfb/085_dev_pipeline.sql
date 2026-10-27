-- Suivi des nouveaux produits [150-151] : étape du produit (idée → test 1 → test 2 → recette → lancement → lancé /
-- arrêté), groupe de production qui le teste (sa tablette seulement), date visée, recette confirmée sur la tablette,
-- produit ERP lancé. Essais (tests de recette) planifiés au bureau et faits / confirmés UNE fois sur la tablette du
-- groupe : quantité, résultat, ce qu'il faut changer, photos — sans ressaisie au bureau.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_dev_product' AND COLUMN_NAME = 'stage') = 0,
    'ALTER TABLE n_dev_product
        ADD COLUMN id_group INT NULL AFTER on_tablet,
        ADD COLUMN stage ENUM(''idea'',''test1'',''test2'',''recipe'',''launch'',''launched'',''stopped'') NOT NULL DEFAULT ''idea'' AFTER id_group,
        ADD COLUMN stage_changed_at DATETIME NULL AFTER stage,
        ADD COLUMN target_date DATE NULL AFTER stage_changed_at,
        ADD COLUMN recipe_confirmed_at DATETIME NULL AFTER target_date,
        ADD COLUMN recipe_confirmed_by INT NULL AFTER recipe_confirmed_at,
        ADD COLUMN id_final_product INT NULL AFTER recipe_confirmed_by,
        ADD KEY idx_n_dev_product_group (id_group, on_tablet),
        ADD CONSTRAINT fk_n_dev_product_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_n_dev_product_final FOREIGN KEY (id_final_product) REFERENCES final_product (id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_n_dev_product_confirmed FOREIGN KEY (recipe_confirmed_by) REFERENCES n_prod_employee (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Essai d'un produit en développement : n° 1, 2, 3… ; planifié (date, consignes) puis fait sur la tablette
-- (id_employee = personne identifiée sur la tablette, n_prod_employee ; recipe_confirmed_by idem).
CREATE TABLE IF NOT EXISTS n_dev_trial (
    id              INT NOT NULL AUTO_INCREMENT,
    id_test_product INT NOT NULL,
    trial_no        INT NOT NULL,
    status          ENUM('planned','done') NOT NULL DEFAULT 'planned',
    planned_date    DATE NULL,
    instructions    TEXT NULL,
    id_group        INT NULL,
    done_at         DATETIME NULL,
    id_employee     INT NULL,
    quantity        DECIMAL(10,2) NULL,
    result          ENUM('ok','fix','ko') NULL,
    notes           TEXT NULL,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_dev_trial_no (id_test_product, trial_no),
    KEY idx_n_dev_trial_group (id_group, status),
    CONSTRAINT fk_n_dev_trial_tp FOREIGN KEY (id_test_product) REFERENCES test_product (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_dev_trial_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_dev_trial_employee FOREIGN KEY (id_employee) REFERENCES n_prod_employee (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Photos d'un essai, prises sur la tablette (JPEG redimensionné), avec l'heure.
CREATE TABLE IF NOT EXISTS n_dev_trial_photo (
    id         INT NOT NULL AUTO_INCREMENT,
    id_trial   INT NOT NULL,
    mime       VARCHAR(32) NOT NULL,
    bytes      INT NOT NULL,
    image      MEDIUMBLOB NOT NULL,
    taken_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_dev_trial_photo (id_trial),
    CONSTRAINT fk_n_dev_trial_photo FOREIGN KEY (id_trial) REFERENCES n_dev_trial (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Produits déjà visibles sur les tablettes : étape d'après leur statut ERP (en production → lancement, terminé → lancé).
UPDATE n_dev_product d JOIN test_product tp ON tp.id = d.id_test_product
SET d.stage = CASE tp.id_status WHEN 2 THEN 'launch' WHEN 3 THEN 'launched' ELSE d.stage END
WHERE d.stage = 'idea' AND tp.id_status IN (2, 3);
