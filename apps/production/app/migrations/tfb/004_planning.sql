-- Module « Gestion de production atelier » — prévisions, plans, OF, opérations, pointages.

-- Prévision par article et par semaine ISO : base × coefficient saisonnier = résultat.
CREATE TABLE IF NOT EXISTS tfb_prod_forecast (
    id             INT NOT NULL AUTO_INCREMENT,
    id_item        INT NOT NULL,
    iso_year       SMALLINT NOT NULL,
    iso_week       TINYINT NOT NULL,
    base_qty       DECIMAL(12,3) NOT NULL DEFAULT 0,
    seasonal_coef  DECIMAL(6,3) NOT NULL DEFAULT 1,
    result_qty     DECIMAL(12,3) NOT NULL DEFAULT 0,
    computed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_forecast (id_item, iso_year, iso_week),
    CONSTRAINT fk_tfb_forecast_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_forecast CHECK (iso_week BETWEEN 1 AND 53 AND seasonal_coef >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Plan de production d'une période : proposed → validated → frozen → closed.
CREATE TABLE IF NOT EXISTS tfb_prod_plan (
    id            INT NOT NULL AUTO_INCREMENT,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    status        ENUM('proposed','validated','frozen','closed') NOT NULL DEFAULT 'proposed',
    version       INT NOT NULL DEFAULT 1,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    validated_by  INT NULL,
    validated_at  DATETIME NULL,
    frozen_at     DATETIME NULL,
    closed_at     DATETIME NULL,
    notes         VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY ix_tfb_plan_period (period_start, period_end),
    CONSTRAINT ck_tfb_plan_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ligne de plan : besoin MTO + MTS + semi-fini d'un article pour une date.
CREATE TABLE IF NOT EXISTS tfb_prod_plan_line (
    id              INT NOT NULL AUTO_INCREMENT,
    id_plan         INT NOT NULL,
    id_item         INT NOT NULL,
    due_date        DATE NOT NULL,
    qty_mto         DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_mts         DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_semi        DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_proposed    DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_final       DECIMAL(12,3) NOT NULL DEFAULT 0,
    batches         INT NOT NULL DEFAULT 0,
    standard_hours  DECIMAL(10,3) NOT NULL DEFAULT 0,
    adjusted_by     INT NULL,
    adjusted_at     DATETIME NULL,
    adjust_reason   VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_plan_line (id_plan, id_item, due_date),
    CONSTRAINT fk_tfb_plan_line_plan FOREIGN KEY (id_plan) REFERENCES tfb_prod_plan (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_plan_line_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id),
    CONSTRAINT ck_tfb_plan_line_qty CHECK (qty_mto >= 0 AND qty_mts >= 0 AND qty_semi >= 0 AND qty_final >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ordre de fabrication (OF).
CREATE TABLE IF NOT EXISTS tfb_prod_order (
    id               INT NOT NULL AUTO_INCREMENT,
    code             VARCHAR(32) NOT NULL,
    id_plan          INT NULL,
    id_item          INT NOT NULL,
    quantity         DECIMAL(12,3) NOT NULL,
    origin           ENUM('MTO','MTS','SEMI') NOT NULL,
    id_parent_order  INT NULL,
    due_at           DATETIME NOT NULL,
    priority         INT NOT NULL DEFAULT 100,
    status           ENUM('planned','released','in_progress','done','cancelled') NOT NULL DEFAULT 'planned',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_order_code (code),
    KEY ix_tfb_order_due (due_at),
    CONSTRAINT fk_tfb_order_plan FOREIGN KEY (id_plan) REFERENCES tfb_prod_plan (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_order_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id),
    CONSTRAINT fk_tfb_order_parent FOREIGN KEY (id_parent_order) REFERENCES tfb_prod_order (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_order_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Commandes clients couvertes par un OF MTO (customer_order_product n'a pas de clé : on garde
-- la commande + le produit).
CREATE TABLE IF NOT EXISTS tfb_prod_order_demand (
    id                 INT NOT NULL AUTO_INCREMENT,
    id_order           INT NOT NULL,
    id_customer_order  INT NOT NULL,
    id_final_product   INT NOT NULL,
    quantity           DECIMAL(12,3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_order_demand (id_order, id_customer_order, id_final_product),
    KEY ix_tfb_order_demand_co (id_customer_order),
    CONSTRAINT fk_tfb_order_demand_order FOREIGN KEY (id_order) REFERENCES tfb_prod_order (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_order_demand_co FOREIGN KEY (id_customer_order) REFERENCES customer_order (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Opération planifiée = OF × étape (ou nettoyage, ou délai de pousse), avec le réel.
CREATE TABLE IF NOT EXISTS tfb_prod_operation (
    id               INT NOT NULL AUTO_INCREMENT,
    id_order         INT NOT NULL,
    id_routing_step  INT NULL,
    kind             ENUM('work','cleaning','delay') NOT NULL DEFAULT 'work',
    id_workstation   INT NOT NULL,
    id_team          INT NULL,
    planned_start    DATETIME NOT NULL,
    planned_end      DATETIME NOT NULL,
    status           ENUM('planned','in_progress','paused','done','cancelled') NOT NULL DEFAULT 'planned',
    actual_start     DATETIME NULL,
    actual_end       DATETIME NULL,
    qty_good         DECIMAL(12,3) NULL,
    qty_scrap        DECIMAL(12,3) NULL,
    scrap_reason     VARCHAR(255) NULL,
    locked           TINYINT(1) NOT NULL DEFAULT 0,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by       INT NULL,
    PRIMARY KEY (id),
    KEY ix_tfb_operation_ws_time (id_workstation, planned_start),
    KEY ix_tfb_operation_order (id_order),
    CONSTRAINT fk_tfb_operation_order FOREIGN KEY (id_order) REFERENCES tfb_prod_order (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_operation_step FOREIGN KEY (id_routing_step) REFERENCES tfb_prod_routing_step (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_operation_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT fk_tfb_operation_team FOREIGN KEY (id_team) REFERENCES tfb_prod_team (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_operation_time CHECK (planned_end > planned_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pointage réel d'une opération par un employé (tablette). client_uuid rend la synchro
-- hors-ligne idempotente.
CREATE TABLE IF NOT EXISTS tfb_prod_time_log (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    id_operation  INT NOT NULL,
    id_employee   INT NOT NULL,
    started_at    DATETIME NOT NULL,
    ended_at      DATETIME NULL,
    pause_s       INT NOT NULL DEFAULT 0,
    source        ENUM('tablet','manual') NOT NULL DEFAULT 'tablet',
    client_uuid   CHAR(36) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_time_log_client (client_uuid),
    KEY ix_tfb_time_log_operation (id_operation),
    CONSTRAINT fk_tfb_time_log_operation FOREIGN KEY (id_operation) REFERENCES tfb_prod_operation (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_time_log_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id),
    CONSTRAINT ck_tfb_time_log CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
