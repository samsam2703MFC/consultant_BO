-- Historia zmian cen produktów : cena standardowa (cennik ogólny, marże kategorii) i ceny klientów,
-- które szły za ceną standardową. Ceny wynegocjowane z klientem (inne niż standardowa) nie są zmieniane.

CREATE TABLE IF NOT EXISTS tfb_product_price_change (
    id               INT NOT NULL AUTO_INCREMENT,
    id_final_product INT NOT NULL,
    id_customer      INT NULL COMMENT 'NULL = cena standardowa',
    old_price        DECIMAL(10,2) NULL,
    new_price        DECIMAL(10,2) NOT NULL,
    source           VARCHAR(30) NOT NULL COMMENT 'general_price_list, category_margin',
    changed_by       INT NULL,
    changed_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_product (id_final_product, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
