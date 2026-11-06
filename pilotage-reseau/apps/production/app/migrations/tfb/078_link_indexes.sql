
-- Nettoyage de la base 5/7 : index sur les colonnes de liaison les plus lues (stocks cartons, palettes, réclamations,
-- messages, commandes, documents par client et par produit). Les colonnes des clés étrangères de 076 ont déjà le leur.

SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_stock' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `carton_stock` ADD INDEX `idx_carton_stock_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_pallet_connection' AND COLUMN_NAME = 'id_palet') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_pallet_connection' AND COLUMN_NAME = 'id_palet' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `carton_pallet_connection` ADD INDEX `idx_carton_pallet_connection_id_palet` (`id_palet`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_pallet_connection' AND COLUMN_NAME = 'id_carton') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_pallet_connection' AND COLUMN_NAME = 'id_carton' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `carton_pallet_connection` ADD INDEX `idx_carton_pallet_connection_id_carton` (`id_carton`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `complaint` ADD INDEX `idx_complaint_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_order') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_order' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `complaint` ADD INDEX `idx_complaint_id_order` (`id_order`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `complaint` ADD INDEX `idx_complaint_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'id_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'id_wdt' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `communication_message` ADD INDEX `idx_communication_message_id_wdt` (`id_wdt`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'id_proforma') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'id_proforma' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `communication_message` ADD INDEX `idx_communication_message_id_proforma` (`id_proforma`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'template_id') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND COLUMN_NAME = 'template_id' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `communication_message` ADD INDEX `idx_communication_message_template_id` (`template_id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `customer_order` ADD INDEX `idx_customer_order_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_wdt' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `customer_order` ADD INDEX `idx_customer_order_id_wdt` (`id_wdt`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_proforma') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'id_proforma' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `customer_order` ADD INDEX `idx_customer_order_id_proforma` (`id_proforma`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `customer_order_product` ADD INDEX `idx_customer_order_product_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_wdt` ADD INDEX `idx_document_wdt_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_wdt_product` ADD INDEX `idx_document_wdt_product_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_wz` ADD INDEX `idx_document_wz_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_wz_product` ADD INDEX `idx_document_wz_product_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_proforma` ADD INDEX `idx_document_proforma_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma_product' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma_product' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_proforma_product` ADD INDEX `idx_document_proforma_product_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_cmr` ADD INDEX `idx_document_cmr_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'id_document_wz') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr' AND COLUMN_NAME = 'id_document_wz' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_cmr` ADD INDEX `idx_document_cmr_id_document_wz` (`id_document_wz`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_order_shipping_difference` ADD INDEX `idx_document_order_shipping_difference_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference_products' AND COLUMN_NAME = 'id_product') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference_products' AND COLUMN_NAME = 'id_product' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `document_order_shipping_difference_products` ADD INDEX `idx_document_order_shipping_difference_products_id_product` (`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'palets_delivery' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `palets_delivery` ADD INDEX `idx_palets_delivery_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_wdt' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_id_wdt` (`id_wdt`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_customer') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_log' AND COLUMN_NAME = 'id_customer' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `shipment_log` ADD INDEX `idx_shipment_log_id_customer` (`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_packaging_item' AND COLUMN_NAME = 'id_document_wz') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_packaging_item' AND COLUMN_NAME = 'id_document_wz' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `shipment_packaging_item` ADD INDEX `idx_shipment_packaging_item_id_document_wz` (`id_document_wz`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND COLUMN_NAME = 'id_category') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product' AND COLUMN_NAME = 'id_category' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `final_product` ADD INDEX `idx_final_product_id_category` (`id_category`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material' AND COLUMN_NAME = 'id_category') = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material' AND COLUMN_NAME = 'id_category' AND SEQ_IN_INDEX = 1) = 0),
    'ALTER TABLE `raw_material` ADD INDEX `idx_raw_material_id_category` (`id_category`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
