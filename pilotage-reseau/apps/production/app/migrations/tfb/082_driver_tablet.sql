-- Tablette chauffeur [142] : une tablette appairée comme celle de l'atelier, en mode « driver », avec son véhicule
-- par défaut. Le chauffeur voit sa tournée du jour (arrêts dans l'ordre de déchargement, palettes, documents) et
-- confirme chaque livraison (réceptionnaire, signature, photo, palettes rendues) ou signale un problème.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_device' AND COLUMN_NAME = 'mode') = 0,
    'ALTER TABLE n_prod_device ADD COLUMN mode ENUM(''atelier'',''driver'') NOT NULL DEFAULT ''atelier'' AFTER id_group, ADD COLUMN id_carrier_vehicle INT NULL AFTER mode',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tournée d'un véhicule un jour d'expédition : départ et retour déclarés sur la tablette.
CREATE TABLE IF NOT EXISTS n_delivery_run (
    id                 INT NOT NULL AUTO_INCREMENT,
    ship_date          DATE NOT NULL,
    id_carrier_vehicle INT NOT NULL,
    driver_name        VARCHAR(100) NULL,
    started_at         DATETIME NULL,
    finished_at        DATETIME NULL,
    id_device          INT NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_delivery_run (ship_date, id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Arrêt chez un client (un par client et par jour d'expédition) : livré, ou problème avec sa raison.
CREATE TABLE IF NOT EXISTS n_delivery_stop (
    id                 INT NOT NULL AUTO_INCREMENT,
    ship_date          DATE NOT NULL,
    id_customer        INT NOT NULL,
    id_carrier_vehicle INT NULL,
    status             ENUM('delivered','problem') NOT NULL,
    reason             ENUM('closed','refused','damaged','missing','late','other') NULL,
    receiver_name      VARCHAR(100) NULL,
    pallets_delivered  INT NULL,
    pallets_returned   INT NULL,
    note               VARCHAR(500) NULL,
    lat                DECIMAL(9,6) NULL,
    lng                DECIMAL(9,6) NULL,
    recorded_at        DATETIME NOT NULL,
    driver_name        VARCHAR(100) NULL,
    id_device          INT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_delivery_stop (ship_date, id_customer),
    KEY idx_n_delivery_stop_vehicle (id_carrier_vehicle, ship_date),
    KEY idx_n_delivery_stop_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Signature du réceptionnaire (PNG) et photos (JPEG) d'un arrêt.
CREATE TABLE IF NOT EXISTS n_delivery_media (
    id         INT NOT NULL AUTO_INCREMENT,
    id_stop    INT NOT NULL,
    kind       ENUM('signature','photo') NOT NULL,
    mime       VARCHAR(40) NOT NULL,
    content    MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_delivery_media_stop (id_stop),
    CONSTRAINT fk_n_delivery_media_stop FOREIGN KEY (id_stop) REFERENCES n_delivery_stop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'driver.photo_max_kb', '1500', '1500', 'int', 'kB', 'Maksymalny rozmiar zdjęcia dostawy z tabletu kierowcy', 'Taille maximale de la photo de livraison prise sur la tablette chauffeur'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'driver.photo_max_kb');
