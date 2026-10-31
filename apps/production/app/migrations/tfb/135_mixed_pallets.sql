-- [215] Paleta mieszana (makieta 214, Kartony i palety › Paleta mieszana) : pełne warstwy kilku produktów klienta jedna
-- na drugiej (cięższa warstwa na dole, mniejsze kartony wyżej). Zatwierdzona paleta liczy się w dniu wysyłki jako jedna
-- paleta (kartony zdjęte z palet zwykłych), kolejność układania na etykiecie PDF i na tablecie pakowania. Rejouable.
CREATE TABLE IF NOT EXISTS n_mixed_pallet (
    id INT NOT NULL AUTO_INCREMENT,
    ship_date DATE NOT NULL COMMENT 'dzień wysyłki',
    id_customer INT NOT NULL,
    pallet VARCHAR(12) NOT NULL DEFAULT 'eur' COMMENT 'eur | industrial (PalletLayout::PALLETS)',
    height_mm SMALLINT UNSIGNED NULL COMMENT 'wysokość z paletą przy zatwierdzeniu (mm)',
    kg DECIMAL(7,1) NULL COMMENT 'waga z paletą przy zatwierdzeniu (kg)',
    confirmed_by INT NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_mixed_pallet_day (ship_date, id_customer),
    CONSTRAINT fk_n_mixed_pallet_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Palety mieszane zatwierdzone : klient i dzień wysyłki [215]';

CREATE TABLE IF NOT EXISTS n_mixed_pallet_item (
    id_mixed_pallet INT NOT NULL,
    position TINYINT UNSIGNED NOT NULL COMMENT '1 = na dole palety',
    id_final_product INT NOT NULL,
    cartons SMALLINT UNSIGNED NOT NULL,
    layers TINYINT UNSIGNED NOT NULL,
    per_layer TINYINT UNSIGNED NOT NULL COMMENT 'kartony na warstwę przy zatwierdzeniu',
    PRIMARY KEY (id_mixed_pallet, position),
    KEY idx_n_mixed_pallet_item_product (id_final_product),
    CONSTRAINT fk_n_mixed_pallet_item_pallet FOREIGN KEY (id_mixed_pallet) REFERENCES n_mixed_pallet (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_mixed_pallet_item_product FOREIGN KEY (id_final_product) REFERENCES final_product (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Palety mieszane : produkty od dołu (warstwy, kartony) [215]';
