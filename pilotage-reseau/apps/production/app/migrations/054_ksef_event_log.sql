CREATE TABLE IF NOT EXISTS `ksef_event_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `id_ksef_submission` BIGINT UNSIGNED NULL,
    `id_shipment_log` INT NULL,
    `level` ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'error',
    `stage` VARCHAR(80) NOT NULL,
    `http_status` SMALLINT UNSIGNED NULL,
    `ksef_code` VARCHAR(80) NULL,
    `message` TEXT NOT NULL,
    `details_json` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ksef_event_created` (`created_at`),
    KEY `idx_ksef_event_submission` (`id_ksef_submission`),
    KEY `idx_ksef_event_shipment` (`id_shipment_log`),
    CONSTRAINT `fk_ksef_event_submission` FOREIGN KEY (`id_ksef_submission`) REFERENCES `ksef_submission` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
