-- ============================================================
-- MIGRACJA 028: Read-only snapshoty produktow franczyzowych
-- ============================================================

CREATE TABLE IF NOT EXISTS `franchise_product_sync_snapshot` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer`           INT NOT NULL,
    `franchisee_uid`        VARCHAR(191) NULL,
    `requested_date`        DATE NOT NULL,
    `status`                ENUM('success', 'failed') NOT NULL DEFAULT 'failed',
    `http_code`             INT NULL,
    `error_code`            VARCHAR(80) NULL,
    `error_message`         TEXT NULL,
    `duration_ms`           INT UNSIGNED NULL,
    `items_count`           INT UNSIGNED NOT NULL DEFAULT 0,
    `active_items_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `inactive_items_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `content_hash`          CHAR(64) NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fpss_customer_created` (`id_customer`, `created_at`),
    KEY `idx_fpss_customer_status_created` (`id_customer`, `status`, `created_at`),
    KEY `idx_fpss_franchisee_uid` (`franchisee_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `franchise_product_sync_snapshot_item` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id`         INT UNSIGNED NOT NULL,
    `id_customer`         INT NOT NULL,
    `sku`                 VARCHAR(191) NULL,
    `id_product_external` VARCHAR(191) NULL,
    `product_name`        VARCHAR(255) NULL,
    `price_net`           DECIMAL(12,4) NULL,
    `vat_rate`            DECIMAL(8,4) NULL,
    `package_size`        DECIMAL(12,4) NULL,
    `package_unit`        VARCHAR(50) NULL,
    `is_active`           TINYINT(1) NULL,
    `valid_from`          DATE NULL,
    `diagnostic_status`   ENUM('ok', 'inactive_supplier', 'missing_price', 'zero_price', 'missing_sku', 'invalid_payload') NOT NULL,
    `raw_payload`         JSON NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fpssi_snapshot` (`snapshot_id`),
    KEY `idx_fpssi_customer_sku` (`id_customer`, `sku`),
    KEY `idx_fpssi_status` (`diagnostic_status`),
    CONSTRAINT `fk_fpssi_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `franchise_product_sync_snapshot` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
