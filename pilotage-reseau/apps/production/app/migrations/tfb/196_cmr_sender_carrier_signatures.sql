-- [311] CMR : pole 22 (nadawca) = zapisany podpis osoby z kontem ERP, która wystawiła wysyłkę (WZ / CMR / faktura) ;
--   pole 23 (przewoźnik) = podpis kierowcy złożony raz na tablecie przy « Start trasy » (wszystkie CMR jego trasy z dnia).
CREATE TABLE IF NOT EXISTS n_user_signature (
    id_user     INT NOT NULL PRIMARY KEY COMMENT 'user.id',
    image_png   MEDIUMBLOB NOT NULL COMMENT 'podpis (PNG, tło przezroczyste albo białe)',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Podpis użytkownika ERP do dokumentów (pole 22 CMR)';

CREATE TABLE IF NOT EXISTS n_shipment_issuer (
    id_shipment_log INT NOT NULL PRIMARY KEY COMMENT 'shipment_log.id',
    id_user         INT NOT NULL COMMENT 'user.id : kto wystawił wysyłkę',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_shipment_issuer_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Wystawca wysyłki (shipment_log.id_employee był stały)';

CREATE TABLE IF NOT EXISTS n_delivery_run_signature (
    ship_date           DATE NOT NULL,
    id_carrier_vehicle  INT NOT NULL,
    driver_name         VARCHAR(100) NULL,
    image_png           MEDIUMBLOB NOT NULL COMMENT 'podpis kierowcy (PNG)',
    signed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_device           INT NULL,
    PRIMARY KEY (ship_date, id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Podpis kierowcy (przewoźnika) na CMR trasy dnia (pole 23)';
