-- [310] Tablet kierowcy (makieta 58) : rozładunek palet (skan QR + zdjęcie każdej palety, obowiązkowe), zgłoszenia
--   « Coś nie tak » (zdjęcie + opis, nie blokują dostawy), zdjęcie temperatury zamrażarki przed otwarciem drzwi
--   (opcjonalne), odbiór : podpis na tablecie, podpis w polu 24 CMR (PDF z fakturowania) albo klient nieobecny
--   (zdjęcie po dostawie obowiązkowe). Rejouable (kolumny warunkowe).
ALTER TABLE n_delivery_media MODIFY kind ENUM('signature', 'photo', 'photo_before', 'photo_after', 'photo_pallet', 'photo_incident',
    'photo_temperature', 'cmr_signed') NOT NULL;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_media' AND COLUMN_NAME = 'pallet_no');
SET @sql := IF(@c = 0, 'ALTER TABLE n_delivery_media ADD COLUMN pallet_no INT NULL COMMENT ''zdjęcie palety : numer palety'' AFTER kind,
    ADD COLUMN id_incident INT NULL COMMENT ''zdjęcie zgłoszenia (n_delivery_incident)'' AFTER pallet_no', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_stop' AND COLUMN_NAME = 'receipt_mode');
SET @sql := IF(@c = 0, 'ALTER TABLE n_delivery_stop ADD COLUMN receipt_mode ENUM(''tablet'', ''cmr'', ''absent'') NULL COMMENT ''odbiór : podpis na tablecie, podpis w CMR, klient nieobecny'' AFTER receiver_name,
    ADD COLUMN pallets_note VARCHAR(255) NULL COMMENT ''uzasadnienie brakującej palety (nie zeskanowanej)'' AFTER pallets_returned', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_delivery_pallet_scan (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_stop     INT NOT NULL COMMENT 'n_delivery_stop.id',
    pallet_no   INT NOT NULL COMMENT 'numer palety (palets_delivery.id_palet)',
    method      ENUM('qr', 'manual') NOT NULL DEFAULT 'qr',
    id_media    INT NULL COMMENT 'zdjęcie palety (n_delivery_media)',
    scanned_at  DATETIME NOT NULL,
    lat         DECIMAL(9, 6) NULL,
    lng         DECIMAL(9, 6) NULL,
    driver_name VARCHAR(100) NULL,
    client_uuid VARCHAR(64) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_delivery_pallet_scan (id_stop, pallet_no),
    UNIQUE KEY uq_n_delivery_pallet_scan_uuid (client_uuid),
    CONSTRAINT fk_n_delivery_pallet_scan_stop FOREIGN KEY (id_stop) REFERENCES n_delivery_stop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_delivery_incident (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_stop     INT NOT NULL COMMENT 'n_delivery_stop.id',
    kind        ENUM('pallet_damaged', 'closed', 'temperature', 'missing_goods', 'refused', 'other') NOT NULL,
    note        VARCHAR(500) NULL,
    recorded_at DATETIME NOT NULL,
    driver_name VARCHAR(100) NULL,
    client_uuid VARCHAR(64) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_delivery_incident_uuid (client_uuid),
    KEY idx_n_delivery_incident_stop (id_stop),
    CONSTRAINT fk_n_delivery_incident_stop FOREIGN KEY (id_stop) REFERENCES n_delivery_stop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
