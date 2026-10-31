-- Tablette chauffeur [144-146] : arrivée chez le client (photos avant déchargement, horodatées), photos après
-- déchargement, signature → rapport PDF de livraison et de tournée ; consignes de livraison propres à chaque client
-- (procédure de déchargement, alarme, clé, congélateur, contact), chiffrées (AES-256-GCM, clé du serveur).
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

-- Arrêt « arrivé » (sur place, déchargement en cours) avant « livré » ou « problème », heure d'arrivée.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_stop' AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%arrived%') = 0,
    'ALTER TABLE n_delivery_stop MODIFY status ENUM(''arrived'',''delivered'',''problem'') NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_stop' AND COLUMN_NAME = 'arrived_at') = 0,
    'ALTER TABLE n_delivery_stop ADD COLUMN arrived_at DATETIME NULL AFTER recorded_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Photos avant / après déchargement : heure de prise de vue et identifiant d'envoi (file hors ligne rejouée sans doublon).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_media' AND COLUMN_NAME = 'kind' AND COLUMN_TYPE LIKE '%photo_before%') = 0,
    'ALTER TABLE n_delivery_media MODIFY kind ENUM(''signature'',''photo'',''photo_before'',''photo_after'') NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_media' AND COLUMN_NAME = 'taken_at') = 0,
    'ALTER TABLE n_delivery_media ADD COLUMN taken_at DATETIME NULL AFTER content, ADD COLUMN client_uuid VARCHAR(64) NULL AFTER taken_at, ADD UNIQUE KEY uq_n_delivery_media_uuid (id_stop, client_uuid)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Consignes de livraison d'un client : un seul bloc chiffré (JSON : procédure, alarme, clé, congélateur, contact).
CREATE TABLE IF NOT EXISTS n_customer_delivery_info (
    id_customer INT NOT NULL,
    payload     TEXT NOT NULL,
    updated_by  VARCHAR(160) NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_customer),
    CONSTRAINT fk_n_customer_delivery_info_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'driver.max_photos', '6', '6', 'int', 'zdjęcia', 'Tablet kierowcy : najwięcej zdjęć przed i po rozładunku u klienta (każde osobno)', 'Tablette chauffeur : nombre maximum de photos avant et après déchargement chez un client (chacune)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'driver.max_photos');
