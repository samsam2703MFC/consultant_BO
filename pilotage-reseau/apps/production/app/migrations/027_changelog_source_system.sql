-- ============================================================
-- MIGRACJA 027: Rozszerzenie zrodla wpisu changelogu o 'system'
-- (zmiany inicjowane przez procesy systemowe, np. supplement push)
-- ============================================================

ALTER TABLE `customer_order_changelog`
    MODIFY COLUMN `source` ENUM('web', 'api', 'collector', 'system')
        NOT NULL DEFAULT 'web';

