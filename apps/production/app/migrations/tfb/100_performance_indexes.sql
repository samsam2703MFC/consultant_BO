-- Audit de performance (26/10/2026) : index des colonnes filtrées et jointes par les pages les plus lentes.
-- Mesuré sur la copie de production : la table des cartons (carton_stock, ~143 000 lignes, +500 par jour) était relue en
-- entier pour chaque calcul de stock (état, jour d'étiquette, palette), les palettes par jour de livraison, les documents
-- WZ / proformas / écarts par date, les recettes par produit (final_product.id_recipe) sans index.
-- idx_carton_stock_product_date couvre le registre des mouvements (produit, jour d'étiquette, état, palette) : lecture de
-- l'index seul au lieu de la table. Ajout seul (aucune donnée modifiée, aucun index supprimé). Conditionnel : table et colonnes présentes, index absent.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'status') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'id_product') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND INDEX_NAME = 'idx_carton_stock_status_product') = 0,
    'ALTER TABLE `carton_stock` ADD INDEX `idx_carton_stock_status_product` (`status`, `id_product`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'date_add') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'status') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND INDEX_NAME = 'idx_carton_stock_date_add') = 0,
    'ALTER TABLE `carton_stock` ADD INDEX `idx_carton_stock_date_add` (`date_add`, `id_product`, `status`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'date_add') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'status') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'date_pallet') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND INDEX_NAME = 'idx_carton_stock_product_date') = 0,
    'ALTER TABLE `carton_stock` ADD INDEX `idx_carton_stock_product_date` (`id_product`, `date_add`, `status`, `date_pallet`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'status') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'date_pallet') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND INDEX_NAME = 'idx_carton_stock_status_pallet') = 0,
    'ALTER TABLE `carton_stock` ADD INDEX `idx_carton_stock_status_pallet` (`status`, `date_pallet`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'delivery_date') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'id_customer') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND INDEX_NAME = 'idx_palets_delivery_date') = 0,
    'ALTER TABLE `palets_delivery` ADD INDEX `idx_palets_delivery_date` (`delivery_date`, `id_customer`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'status') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'delivery_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND INDEX_NAME = 'idx_palets_delivery_status') = 0,
    'ALTER TABLE `palets_delivery` ADD INDEX `idx_palets_delivery_status` (`status`, `delivery_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'status') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'expected_shipping_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_customer_order_status_ship') = 0,
    'ALTER TABLE `customer_order` ADD INDEX `idx_customer_order_status_ship` (`status`, `expected_shipping_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'expected_shipping_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_customer_order_ship_date') = 0,
    'ALTER TABLE `customer_order` ADD INDEX `idx_customer_order_ship_date` (`expected_shipping_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND INDEX_NAME = 'idx_document_wz_established') = 0,
    'ALTER TABLE `document_wz` ADD INDEX `idx_document_wz_established` (`established_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'established_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND INDEX_NAME = 'idx_document_wz_customer_date') = 0,
    'ALTER TABLE `document_wz` ADD INDEX `idx_document_wz_customer_date` (`id_customer`, `established_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'established_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND INDEX_NAME = 'idx_document_proforma_established') = 0,
    'ALTER TABLE `document_proforma` ADD INDEX `idx_document_proforma_established` (`established_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'established_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND INDEX_NAME = 'idx_dosd_established') = 0,
    'ALTER TABLE `document_order_shipping_difference` ADD INDEX `idx_dosd_established` (`established_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'shipment_date') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND INDEX_NAME = 'idx_shipment_log_date') = 0,
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_date` (`shipment_date`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_diff') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND INDEX_NAME = 'idx_shipment_log_id_diff') = 0,
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_id_diff` (`id_diff`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_wz') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND INDEX_NAME = 'idx_shipment_log_id_wz') = 0,
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_id_wz` (`id_wz`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_cmr') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND INDEX_NAME = 'idx_shipment_log_id_cmr') = 0,
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_id_cmr` (`id_cmr`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND COLUMN_NAME = 'id_recipe') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND INDEX_NAME = 'idx_final_product_id_recipe') = 0,
    'ALTER TABLE `final_product` ADD INDEX `idx_final_product_id_recipe` (`id_recipe`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND COLUMN_NAME = 'id_section') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND INDEX_NAME = 'idx_final_product_id_section') = 0,
    'ALTER TABLE `final_product` ADD INDEX `idx_final_product_id_section` (`id_section`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_preparation_connection' AND COLUMN_NAME = 'id_recipe') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_preparation_connection' AND INDEX_NAME = 'idx_prpc_id_recipe') = 0,
    'ALTER TABLE `product_recipe_preparation_connection` ADD INDEX `idx_prpc_id_recipe` (`id_recipe`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND COLUMN_NAME = 'id_raw_material') = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND COLUMN_NAME = 'timestamp') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND INDEX_NAME = 'idx_rmpc_material_time') = 0,
    'ALTER TABLE `raw_material_price_changelog` ADD INDEX `idx_rmpc_material_time` (`id_raw_material`, `timestamp`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
