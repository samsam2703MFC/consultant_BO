CREATE TABLE IF NOT EXISTS `app_notification` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(64) NOT NULL,
    `severity` ENUM('info', 'warning', 'danger') NOT NULL DEFAULT 'info',
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NULL,
    `link_url` VARCHAR(500) NULL,
    `payload_json` JSON NULL,
    `dedup_key` VARCHAR(191) NOT NULL,
    `acknowledged_at` DATETIME NULL,
    `acknowledged_via` ENUM('read', 'dismiss') NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_app_notification_dedup` (`dedup_key`),
    KEY `idx_app_notification_active` (`acknowledged_at`, `expires_at`, `created_at`),
    KEY `idx_app_notification_type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
