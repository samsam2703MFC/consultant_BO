-- Logistyka : trasy (kursy). Trasa = nazwa, dni tygodnia, godzina wyjazdu ; pojazdy trasy (kolejność
-- wypełniania) i klienci trasy (kolejność rozładunku). W dniu wysyłki klient jedzie trasą tego dnia
-- tygodnia, pojazd dobierany z pojazdów trasy (pierwszy, w którym jego palety się mieszczą).
-- Przydział dnia (tfb_shipping_assignment) może zmienić trasę (tour_set) albo pojazd (vehicle_set = 0 :
-- pojazd automatycznie z trasy). Dotychczasowe stałe trasy klientów (pojazd + kolejność) przeniesione
-- do tras „Trasa {pojazd}”.

CREATE TABLE IF NOT EXISTS tfb_tour (
    id             INT NOT NULL AUTO_INCREMENT,
    name           VARCHAR(80) NOT NULL,
    weekdays       TINYINT UNSIGNED NOT NULL DEFAULT 127 COMMENT 'Dni tygodnia : 1 = pon., 2 = wt., 4 = śr., 8 = czw., 16 = pt., 32 = sob., 64 = niedz.',
    departure_time TIME NULL COMMENT 'Wyjazd trasy ; NULL = godzina pojazdu lub planning.default_departure_time',
    color          CHAR(7) NULL,
    active         TINYINT(1) NOT NULL DEFAULT 1,
    notes          VARCHAR(255) NULL,
    updated_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_tour_name (name),
    CONSTRAINT ck_tfb_tour_weekdays CHECK (weekdays BETWEEN 1 AND 127)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_tour_vehicle (
    id_tour            INT NOT NULL,
    id_carrier_vehicle INT NOT NULL,
    position           INT NOT NULL DEFAULT 1 COMMENT 'Kolejność wypełniania pojazdów trasy',
    PRIMARY KEY (id_tour, id_carrier_vehicle),
    KEY idx_tfb_tour_vehicle_vehicle (id_carrier_vehicle),
    CONSTRAINT fk_tfb_tour_vehicle_tour FOREIGN KEY (id_tour) REFERENCES tfb_tour (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_tour_vehicle_vehicle FOREIGN KEY (id_carrier_vehicle) REFERENCES carrier_vehicle (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_tour_customer (
    id_tour     INT NOT NULL,
    id_customer INT NOT NULL,
    stop_order  INT NULL COMMENT 'Kolejność rozładunku na trasie',
    PRIMARY KEY (id_tour, id_customer),
    KEY idx_tfb_tour_customer_customer (id_customer),
    CONSTRAINT fk_tfb_tour_customer_tour FOREIGN KEY (id_tour) REFERENCES tfb_tour (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_tour_customer_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Przydział dnia : trasa (tour_set = 1 : trasa dnia, także „bez trasy”) i pojazd automatyczny z trasy.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_shipping_assignment' AND COLUMN_NAME = 'id_tour');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tfb_shipping_assignment
        ADD COLUMN id_tour INT NULL COMMENT ''Trasa na ten dzień'' AFTER id_customer,
        ADD COLUMN tour_set TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = trasa dnia zamiast trasy klienta'' AFTER id_tour,
        ADD COLUMN vehicle_set TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = pojazd automatycznie z trasy'' AFTER id_carrier_vehicle,
        ADD KEY idx_tfb_shipping_assignment_tour (id_tour),
        ADD CONSTRAINT fk_tfb_shipping_assignment_tour FOREIGN KEY (id_tour) REFERENCES tfb_tour (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Stałe trasy klientów (pojazd) → trasy.
INSERT IGNORE INTO tfb_tour (name, weekdays, active)
SELECT DISTINCT CONVERT(CONCAT('Trasa ', COALESCE(NULLIF(TRIM(cv.regis_num), ''), c.name)) USING utf8mb4) COLLATE utf8mb4_general_ci, 127, 1
FROM tfb_customer_route r JOIN carrier_vehicle cv ON cv.id = r.id_carrier_vehicle JOIN carrier c ON c.id = cv.id_carrier;

INSERT IGNORE INTO tfb_tour_vehicle (id_tour, id_carrier_vehicle, position)
SELECT DISTINCT t.id, cv.id, 1
FROM tfb_customer_route r JOIN carrier_vehicle cv ON cv.id = r.id_carrier_vehicle JOIN carrier c ON c.id = cv.id_carrier
JOIN tfb_tour t ON t.name = CONVERT(CONCAT('Trasa ', COALESCE(NULLIF(TRIM(cv.regis_num), ''), c.name)) USING utf8mb4) COLLATE utf8mb4_general_ci;

INSERT IGNORE INTO tfb_tour_customer (id_tour, id_customer, stop_order)
SELECT t.id, r.id_customer, r.stop_order
FROM tfb_customer_route r JOIN carrier_vehicle cv ON cv.id = r.id_carrier_vehicle JOIN carrier c ON c.id = cv.id_carrier
JOIN tfb_tour t ON t.name = CONVERT(CONCAT('Trasa ', COALESCE(NULLIF(TRIM(cv.regis_num), ''), c.name)) USING utf8mb4) COLLATE utf8mb4_general_ci;
