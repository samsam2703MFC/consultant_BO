-- ============================================================
-- MIGRACJA 025: Audit trail zamowien klientow
-- ============================================================

CREATE TABLE IF NOT EXISTS `customer_order_changelog` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `id_order`       INT          NOT NULL,
    `id_user`        INT          NULL     COMMENT 'NULL = zmiana systemowa (API / kolektor)',
    `action_type`    ENUM('create', 'update', 'delete') NOT NULL,
    `source`         ENUM('web', 'api', 'collector') NOT NULL DEFAULT 'web',
    `changed_fields` JSON         NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_id_order`   (`id_order`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
