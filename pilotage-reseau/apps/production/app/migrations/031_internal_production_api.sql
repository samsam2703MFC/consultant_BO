-- Internal production-application API: registered devices and picking-list audit.

CREATE TABLE IF NOT EXISTS `internal_api_device` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(500) NULL,
    `device_id` CHAR(36) NOT NULL,
    `api_key_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_user_id` INT NULL,
    `last_used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_internal_api_device_device_id` (`device_id`),
    KEY `idx_internal_api_device_active` (`is_active`, `last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `picking_list_generation` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` ENUM('api', 'web') NOT NULL,
    `id_device` BIGINT UNSIGNED NULL,
    `id_user` INT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `pdf_sha256` CHAR(64) NOT NULL,
    `order_count` INT UNSIGNED NOT NULL,
    `product_count` INT UNSIGNED NOT NULL,
    `selected_order_ids_json` JSON NOT NULL,
    `products_snapshot_json` JSON NOT NULL,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_picking_list_generation_device`
        FOREIGN KEY (`id_device`) REFERENCES `internal_api_device` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    KEY `idx_picking_list_generation_generated_at` (`generated_at`),
    KEY `idx_picking_list_generation_device_date` (`id_device`, `generated_at`),
    KEY `idx_picking_list_generation_user_date` (`id_user`, `generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
