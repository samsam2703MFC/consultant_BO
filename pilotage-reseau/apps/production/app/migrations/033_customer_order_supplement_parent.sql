-- Minimalna relacja dla domówienia tworzonego z edycji opłaconego zamówienia.
ALTER TABLE `customer_order`
    ADD COLUMN IF NOT EXISTS `parent_order_id` INT NULL AFTER `order_type`;

ALTER TABLE `customer_order`
    ADD INDEX IF NOT EXISTS `idx_customer_order_parent_order_id` (`parent_order_id`);
