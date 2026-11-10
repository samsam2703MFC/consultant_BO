-- Logistyka : pojazdy przewoźników z pojemnością, stała trasa klienta, przydział klientów do
-- pojazdów na dzień wysyłki. Palety klienta liczone z zamówień dnia : kartony ÷ kartony na paletę
-- (final_product.cpp, karta produktu) ; reszty kartonów różnych produktów mogą jechać na palecie
-- mieszanej (logistics.mixed_pallets). Waga : kartony × sztuki × gramatura + tara palety.
-- Pojazdy = carrier_vehicle z ERP (przewoźnicy i ich pojazdy) ; tu tylko pojemność i godzina wyjazdu.

CREATE TABLE IF NOT EXISTS tfb_vehicle (
    id_carrier_vehicle INT NOT NULL,
    pallet_places      INT NOT NULL COMMENT 'Miejsca paletowe (EUR 120×80)',
    max_load_kg        INT NULL COMMENT 'Ładowność (kg); NULL = nie sprawdzać',
    departure_time     TIME NULL COMMENT 'Godzina wyjazdu; NULL = planning.default_departure_time',
    active             TINYINT(1) NOT NULL DEFAULT 1,
    notes              VARCHAR(255) NULL,
    updated_by         INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_carrier_vehicle),
    CONSTRAINT fk_tfb_vehicle_vehicle FOREIGN KEY (id_carrier_vehicle) REFERENCES carrier_vehicle (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_vehicle_places CHECK (pallet_places > 0 AND pallet_places <= 100),
    CONSTRAINT ck_tfb_vehicle_load CHECK (max_load_kg IS NULL OR max_load_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_customer_route (
    id_customer        INT NOT NULL,
    id_carrier_vehicle INT NULL COMMENT 'Stały pojazd (trasa) klienta',
    stop_order         INT NULL COMMENT 'Kolejność rozładunku na trasie',
    updated_by         INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_customer),
    KEY idx_tfb_customer_route_vehicle (id_carrier_vehicle),
    CONSTRAINT fk_tfb_customer_route_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_customer_route_vehicle FOREIGN KEY (id_carrier_vehicle) REFERENCES carrier_vehicle (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_shipping_assignment (
    ship_date          DATE NOT NULL,
    id_customer        INT NOT NULL,
    id_carrier_vehicle INT NULL COMMENT 'NULL = jeszcze bez pojazdu',
    stop_order         INT NULL,
    updated_by         INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ship_date, id_customer),
    KEY idx_tfb_shipping_assignment_vehicle (id_carrier_vehicle, ship_date),
    CONSTRAINT fk_tfb_shipping_assignment_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_shipping_assignment_vehicle FOREIGN KEY (id_carrier_vehicle) REFERENCES carrier_vehicle (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('logistics.mixed_pallets', '1', '1', 'bool', NULL, 'Reszty kartonów różnych produktów klienta na wspólnej palecie (paleta mieszana)', 'Restes de cartons de produits différents d''un client sur une palette mixte'),
('logistics.pallet_tare_kg', '25', '25', 'decimal', 'kg', 'Waga pustej palety EUR', 'Poids d''une palette EUR vide'),
('logistics.default_pallet_places', '33', '33', 'int', NULL, 'Domyślna liczba miejsc paletowych nowego pojazdu (naczepa 13,6 m)', 'Places palettes par défaut d''un nouveau véhicule (semi-remorque 13,6 m)');
