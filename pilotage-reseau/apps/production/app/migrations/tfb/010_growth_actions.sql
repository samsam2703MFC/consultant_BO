-- Rentowność : actions de développement du chiffre d'affaires par groupe de produits
-- (dział produkcyjny). Une action = un produit existant ou nouveau, un volume par magasin et
-- par mois, un prix de vente prévu, une marge cible et l'investissement nécessaire ; le CA, la
-- marge, le délai de retour et le ROI en sont calculés.

CREATE TABLE IF NOT EXISTS tfb_growth_action (
    id                     INT NOT NULL AUTO_INCREMENT,
    id_section             INT NOT NULL,
    title                  VARCHAR(150) NOT NULL,
    id_final_product       INT NULL COMMENT 'Produit existant de l''ERP',
    product_name           VARCHAR(200) NULL COMMENT 'Nouveau produit, pas encore dans l''ERP',
    pieces_per_shop_month  DECIMAL(12,2) NOT NULL,
    shops                  INT NOT NULL,
    selling_price_eur      DECIMAL(10,4) NOT NULL COMMENT 'Prix de vente prévu par pièce (EUR)',
    target_margin_pct      DECIMAL(5,2) NOT NULL,
    investment_eur         DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_month            CHAR(7) NULL COMMENT 'YYYY-MM',
    status                 ENUM('idea','planned','in_progress','developed','dropped') NOT NULL DEFAULT 'idea',
    owner_user             INT NULL,
    notes                  TEXT NULL,
    created_by             INT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_growth_action_section (id_section, status),
    CONSTRAINT fk_tfb_growth_action_section FOREIGN KEY (id_section) REFERENCES production_sections (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_growth_action_values CHECK (pieces_per_shop_month >= 0 AND shops >= 1 AND selling_price_eur >= 0
        AND target_margin_pct > -100 AND target_margin_pct < 100 AND investment_eur >= 0),
    CONSTRAINT ck_tfb_growth_action_product CHECK (id_final_product IS NOT NULL OR product_name IS NOT NULL),
    CONSTRAINT ck_tfb_growth_action_month CHECK (start_month IS NULL OR start_month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('growth.default_shops', '0', '0', 'int', NULL, 'Domyślna liczba sklepów w działaniach (0 = klienci kupujący w ostatnich 90 dniach)', 'Nombre de magasins par défaut des actions (0 = clients ayant acheté sur 90 jours)'),
('growth.roi_horizon_months', '36', '36', 'int', 'mo', 'Horyzont ROI działań rozwoju sprzedaży', 'Horizon du ROI des actions de développement du CA');
