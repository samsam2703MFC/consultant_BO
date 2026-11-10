CREATE TABLE IF NOT EXISTS `ksef_integration_settings` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `environment` ENUM('test', 'production') NOT NULL DEFAULT 'test',
    `token_encrypted` TEXT NULL,
    `last_connection_check_at` DATETIME NULL,
    `last_connection_check_message` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ksef_submission` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `id_document_wdt` INT NOT NULL,
    `id_shipment_log` INT NOT NULL,
    `status` ENUM('ready', 'submitted', 'processing', 'accepted', 'rejected', 'failed') NOT NULL DEFAULT 'ready',
    `invoice_xml` MEDIUMTEXT NULL,
    `invoice_hash` CHAR(64) NULL,
    `session_reference_number` VARCHAR(64) NULL,
    `invoice_reference_number` VARCHAR(64) NULL,
    `ksef_number` VARCHAR(64) NULL,
    `upo_xml` MEDIUMTEXT NULL,
    `error_code` VARCHAR(64) NULL,
    `error_message` TEXT NULL,
    `submitted_by` INT NULL,
    `submitted_at` DATETIME NULL,
    `resolved_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_ksef_submission_wdt` (`id_document_wdt`),
    UNIQUE KEY `uq_ksef_submission_shipment` (`id_shipment_log`),
    KEY `idx_ksef_submission_status` (`status`),
    KEY `idx_ksef_submission_session_reference` (`session_reference_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
