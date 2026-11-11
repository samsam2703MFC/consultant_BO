-- Module « Gestion de production atelier » — articles planifiables, réglages produit, pertes, gammes.

-- Article planifiable : un produit fini existant (final_product) ou un semi-fini existant
-- (recette product_recipe de type composition / semi-produit). Rien n'est recopié : le nom,
-- la nomenclature et le colisage restent ceux de l'ERP.
CREATE TABLE IF NOT EXISTS tfb_prod_item (
    id                INT NOT NULL AUTO_INCREMENT,
    item_type         ENUM('product','semi') NOT NULL,
    id_final_product  INT NULL,
    id_recipe         INT NULL,
    base_unit         VARCHAR(8) NOT NULL DEFAULT 'szt',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_item_product (id_final_product),
    UNIQUE KEY uq_tfb_item_recipe (id_recipe),
    CONSTRAINT fk_tfb_item_product FOREIGN KEY (id_final_product) REFERENCES final_product (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_item_recipe FOREIGN KEY (id_recipe) REFERENCES product_recipe (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_item_ref CHECK (
        (item_type = 'product' AND id_final_product IS NOT NULL AND id_recipe IS NULL)
        OR (item_type = 'semi' AND id_recipe IS NOT NULL AND id_final_product IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Réglages de production d'un article (extension de la fiche existante).
-- Un article « jamais congelé » ne peut jamais être en MTS : contrôle en base, en API et en UI.
-- Quantités dans l'unité de base de l'article (pièces pour un produit fini).
CREATE TABLE IF NOT EXISTS tfb_prod_product_settings (
    id_item                   INT NOT NULL,
    mode                      ENUM('MTO','MTS') NOT NULL DEFAULT 'MTO',
    never_frozen              TINYINT(1) NOT NULL DEFAULT 0,
    stock_min                 DECIMAL(12,3) NULL,
    stock_max                 DECIMAL(12,3) NULL,
    coverage_days             DECIMAL(6,2) NULL,
    batch_min                 DECIMAL(12,3) NULL,
    batch_multiple            DECIMAL(12,3) NULL,
    shelf_life_workshop_days  DECIMAL(6,2) NULL,
    shelf_life_shop_days      DECIMAL(6,2) NULL,
    id_primary_group          INT NULL,
    status                    ENUM('draft','validated') NOT NULL DEFAULT 'draft',
    wizard_step               TINYINT NOT NULL DEFAULT 1,
    validated_at              DATETIME NULL,
    validated_by              INT NULL,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by                INT NULL,
    PRIMARY KEY (id_item),
    CONSTRAINT fk_tfb_settings_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_settings_group FOREIGN KEY (id_primary_group) REFERENCES tfb_prod_group (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_settings_never_frozen CHECK (NOT (never_frozen = 1 AND mode = 'MTS')),
    CONSTRAINT ck_tfb_settings_stock CHECK (stock_min IS NULL OR stock_max IS NULL OR stock_max >= stock_min),
    CONSTRAINT ck_tfb_settings_batch CHECK ((batch_min IS NULL OR batch_min > 0) AND (batch_multiple IS NULL OR batch_multiple > 0)),
    CONSTRAINT ck_tfb_settings_step CHECK (wizard_step BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Taux de perte par ligne de nomenclature. La nomenclature elle-même reste celle de l'ERP
-- (product_recipe_connection) : on n'ajoute que ce qui lui manque.
CREATE TABLE IF NOT EXISTS tfb_prod_bom_loss (
    id                INT NOT NULL AUTO_INCREMENT,
    parent_recipe_id  INT NOT NULL,
    child_kind        ENUM('material','recipe') NOT NULL,
    child_id          INT NOT NULL,
    loss_pct          DECIMAL(5,2) NOT NULL DEFAULT 0,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by        INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_bom_loss (parent_recipe_id, child_kind, child_id),
    CONSTRAINT fk_tfb_bom_loss_recipe FOREIGN KEY (parent_recipe_id) REFERENCES product_recipe (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_bom_loss_pct CHECK (loss_pct >= 0 AND loss_pct < 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Gamme opératoire : étapes ordonnées d'un article.
-- Durée d'une étape = fixed_time_s × nb de passages + variable_time_s × quantité,
-- nb de passages = ⌈quantité / capacity_per_pass⌉.
-- Délai après l'étape (pousse, repos, refroidissement) : delay_after_s, qui occupe ou non
-- un poste à capacité (delay_id_workstation, ex. chambre de pousse).
CREATE TABLE IF NOT EXISTS tfb_prod_routing_step (
    id                    INT NOT NULL AUTO_INCREMENT,
    id_item               INT NOT NULL,
    step_no               INT NOT NULL,
    name_pl               VARCHAR(100) NOT NULL,
    name_fr               VARCHAR(100) NOT NULL,
    id_workstation        INT NOT NULL,
    fixed_time_s          INT NOT NULL DEFAULT 0,
    variable_time_s       DECIMAL(10,3) NOT NULL DEFAULT 0,
    capacity_per_pass     DECIMAL(10,2) NOT NULL DEFAULT 1,
    operators_required    DECIMAL(4,2) NOT NULL DEFAULT 1,
    min_level_order       INT NULL,
    delay_after_s         INT NOT NULL DEFAULT 0,
    delay_id_workstation  INT NULL,
    cleaning_required     TINYINT(1) NOT NULL DEFAULT 0,
    cleaning_time_s       INT NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_routing_step (id_item, step_no),
    CONSTRAINT fk_tfb_routing_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_routing_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT fk_tfb_routing_delay_ws FOREIGN KEY (delay_id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT ck_tfb_routing_times CHECK (fixed_time_s >= 0 AND variable_time_s >= 0 AND delay_after_s >= 0 AND cleaning_time_s >= 0),
    CONSTRAINT ck_tfb_routing_capacity CHECK (capacity_per_pass > 0 AND operators_required >= 0),
    CONSTRAINT ck_tfb_routing_cleaning CHECK (cleaning_required = 1 OR cleaning_time_s = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Postes alternatifs d'une étape, avec leur propre cadence.
CREATE TABLE IF NOT EXISTS tfb_prod_routing_alternative (
    id                 INT NOT NULL AUTO_INCREMENT,
    id_step            INT NOT NULL,
    id_workstation     INT NOT NULL,
    fixed_time_s       INT NOT NULL DEFAULT 0,
    variable_time_s    DECIMAL(10,3) NOT NULL DEFAULT 0,
    capacity_per_pass  DECIMAL(10,2) NOT NULL DEFAULT 1,
    priority           INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_routing_alt (id_step, id_workstation),
    CONSTRAINT fk_tfb_routing_alt_step FOREIGN KEY (id_step) REFERENCES tfb_prod_routing_step (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_routing_alt_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT ck_tfb_routing_alt CHECK (fixed_time_s >= 0 AND variable_time_s >= 0 AND capacity_per_pass > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
