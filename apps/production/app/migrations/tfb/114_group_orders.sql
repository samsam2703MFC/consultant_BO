-- Compositions faites par un autre groupe (27/10/2026, maquettes 1 à 4). Rejouable.
-- 1. n_recipe_producer.lead_days : délai de commande de la composition (jours avant le besoin, 0 = le jour même).
-- 2. n_group_order : commande d'une composition par un groupe à un autre (quantité, jour et heure) — commandé, accepté,
--    en production (partie du groupe qui fait), prêt, retiré (partie du groupe qui commande), annulé.
-- 3. n_prod_output_component : partie d'un produit ↔ partie de la composition faite par l'autre groupe (une ligne au lieu
--    des matières de la composition ; la partie de la composition est considérée entièrement utilisée).

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_recipe_producer' AND COLUMN_NAME = 'lead_days');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE n_recipe_producer ADD COLUMN lead_days TINYINT UNSIGNED NULL COMMENT ''Zamawianie : ile dni przed potrzebą (0 = ten sam dzień)'' AFTER id_group',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_group_order (
    id               BIGINT NOT NULL AUTO_INCREMENT,
    id_recipe        INT NOT NULL COMMENT 'product_recipe.id : kompozycja / półprodukt',
    id_item          INT NULL COMMENT 'n_prod_item (półprodukt) grupy robiącej',
    id_group_from    INT NOT NULL COMMENT 'Grupa zamawiająca',
    id_group_to      INT NOT NULL COMMENT 'Grupa robiąca',
    quantity         DECIMAL(12,3) NOT NULL,
    unit             VARCHAR(8) NOT NULL DEFAULT 'kg',
    due_at           DATETIME NOT NULL COMMENT 'Na kiedy (dzień i godzina)',
    status           ENUM('ordered','accepted','in_production','ready','picked','cancelled') NOT NULL DEFAULT 'ordered',
    id_output        BIGINT NULL COMMENT 'Partia grupy robiącej (n_prod_output)',
    id_picked_output BIGINT NULL COMMENT 'Partia grupy zamawiającej, która zużyła kompozycję',
    picked_quantity  DECIMAL(12,3) NULL,
    note             VARCHAR(255) NULL,
    ordered_by       INT NULL COMMENT 'n_prod_employee',
    id_device        INT NULL,
    accepted_by      INT NULL,
    accepted_at      DATETIME NULL,
    ready_at         DATETIME NULL,
    picked_at        DATETIME NULL,
    cancelled_by     INT NULL,
    cancelled_at     DATETIME NULL,
    client_uuid      CHAR(36) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_group_order_uuid (client_uuid),
    KEY ix_n_group_order_to (id_group_to, status, due_at),
    KEY ix_n_group_order_from (id_group_from, due_at),
    KEY ix_n_group_order_recipe (id_recipe, due_at),
    KEY fk_n_group_order_output (id_output),
    KEY fk_n_group_order_picked (id_picked_output),
    CONSTRAINT fk_n_group_order_from FOREIGN KEY (id_group_from) REFERENCES n_prod_group (id),
    CONSTRAINT fk_n_group_order_to FOREIGN KEY (id_group_to) REFERENCES n_prod_group (id),
    CONSTRAINT fk_n_group_order_output FOREIGN KEY (id_output) REFERENCES n_prod_output (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_group_order_picked FOREIGN KEY (id_picked_output) REFERENCES n_prod_output (id) ON DELETE SET NULL,
    CONSTRAINT ck_n_group_order_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_prod_output_component (
    id               BIGINT NOT NULL AUTO_INCREMENT,
    id_output        BIGINT NOT NULL COMMENT 'Partia produktu (grupa zamawiająca)',
    id_recipe        INT NOT NULL COMMENT 'Kompozycja robiona przez inną grupę',
    id_source_output BIGINT NULL COMMENT 'Partia kompozycji grupy robiącej',
    quantity         DECIMAL(14,3) NULL COMMENT 'Potrzebna ilość (jednostka receptury kompozycji)',
    unit             VARCHAR(8) NULL,
    id_employee      INT NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_prod_output_component (id_output, id_recipe),
    KEY fk_n_prod_output_component_source (id_source_output),
    CONSTRAINT fk_n_prod_output_component_output FOREIGN KEY (id_output) REFERENCES n_prod_output (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_prod_output_component_source FOREIGN KEY (id_source_output) REFERENCES n_prod_output (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fiche « półprodukt » du groupe qui fait chaque composition déjà liée (liste de production, planning, parties).
INSERT INTO n_prod_item (item_type, id_recipe, base_unit)
SELECT 'semi', p.id_recipe, IF(u.name IN ('g', 'kg', 'ml', 'l'), 'kg', 'szt')
FROM n_recipe_producer p
JOIN product_recipe pr ON pr.id = p.id_recipe
LEFT JOIN unit u ON u.id = pr.id_unit
WHERE NOT EXISTS (SELECT 1 FROM n_prod_item i WHERE i.id_recipe = p.id_recipe);

INSERT INTO n_prod_product_settings (id_item, mode, never_frozen, id_primary_group, status, wizard_step)
SELECT i.id, 'MTO', 0, p.id_group, 'validated', 1
FROM n_recipe_producer p
JOIN n_prod_item i ON i.id_recipe = p.id_recipe
WHERE NOT EXISTS (SELECT 1 FROM n_prod_product_settings s WHERE s.id_item = i.id);

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'group_orders.days_ahead', '5', '5', 'int', 'dni',
    'Zamówienia między grupami : ile dni naprzód można zamówić na tablecie',
    'Commandes entre groupes : jusqu''à combien de jours à l''avance la tablette peut commander'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'group_orders.days_ahead');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'group_orders.hours', '06:00,08:00,10:00,12:00,14:00', '06:00,08:00,10:00,12:00,14:00', 'string', NULL,
    'Zamówienia między grupami : godziny do wyboru na tablecie',
    'Commandes entre groupes : heures proposées sur la tablette'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'group_orders.hours');
