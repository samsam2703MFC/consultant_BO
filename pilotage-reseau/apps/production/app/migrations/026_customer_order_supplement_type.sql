-- MIGRACJA 026: Typ zamowienia klienta (STANDARD / SUPPLEMENT)

ALTER TABLE `customer_order`
    ADD COLUMN IF NOT EXISTS `order_type` ENUM('STANDARD', 'SUPPLEMENT') NOT NULL DEFAULT 'STANDARD'
        AFTER `is_freebie`;

ALTER TABLE `customer_order`
    ADD COLUMN IF NOT EXISTS `supplement_push_status` ENUM('pending','sent','failed') NULL DEFAULT NULL
        AFTER `order_type`;

ALTER TABLE `customer_order`
    ADD COLUMN IF NOT EXISTS `supplement_push_at` DATETIME NULL DEFAULT NULL
        AFTER `supplement_push_status`;

ALTER TABLE `customer_order`
    ADD COLUMN IF NOT EXISTS `supplement_order_key` VARCHAR(128) NULL DEFAULT NULL
        AFTER `supplement_push_at`;
