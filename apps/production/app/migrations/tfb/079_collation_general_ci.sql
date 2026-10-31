
-- Nettoyage de la base 6/7 : les 23 tables en utf8mb4_unicode_ci passent à utf8mb4_general_ci, comme le reste de
-- l'ERP (plus d'erreur « Illegal mix of collations » sur une jointure de texte). Même jeu de caractères : aucun
-- type de colonne ne change.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notification' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `app_notification` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_email_account' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_email_account` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_incoming_message' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_incoming_message` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_message` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message_attachment' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_message_attachment` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_message_attempt' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_message_attempt` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_template' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `communication_template` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_logistics_type' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cost_logistics_type` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_media_month' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cost_media_month` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_media_section_allocation' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cost_media_section_allocation` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_media_type' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cost_media_type` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_month_logistics' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cost_month_logistics` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cron_notification_outbox' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cron_notification_outbox` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cron_runs' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `cron_runs` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_changelog' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `customer_order_changelog` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchise_product_sync_snapshot' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `franchise_product_sync_snapshot` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchise_product_sync_snapshot_item' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `franchise_product_sync_snapshot_item` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'internal_api_device' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `internal_api_device` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ksef_event_log' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `ksef_event_log` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ksef_integration_settings' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `ksef_integration_settings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ksef_submission' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `ksef_submission` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'picking_list_generation' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `picking_list_generation` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supported_language' AND TABLE_COLLATION <> 'utf8mb4_general_ci'),
    'ALTER TABLE `supported_language` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
