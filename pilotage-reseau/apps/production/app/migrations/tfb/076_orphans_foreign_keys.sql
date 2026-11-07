
-- Nettoyage de la base 3/7 : références orphelines et clés étrangères.
-- RESTRICT pour les lignes des documents légaux → en-tête (WDT, WZ, facture, différences, soumission KSeF) : un
-- document ne se supprime pas avec ses lignes par erreur (le code n'en supprime jamais).
-- CASCADE quand le code supprime le parent puis les lignes : lignes de commande, lignes de proforma
-- (ProformaRepository::deleteProforma), prix clients, colis CMR, pièces jointes de réclamation, historique des prix
-- matière. Recette d'un produit supprimé : produit vidé (SET NULL).
-- Orphelins supprimés avant la clé (sauvegarde : voir 074) : lignes franchisés de commandes supprimées, colis de CMR
-- supprimées, prix de produits ou de clients supprimés, prix franchisé et historique de prix de matières supprimées ;
-- recettes : produit supprimé → NULL ; n_page_view, n_doc_distribution : lignes d'essais.
-- Gardés tels quels, sans clé étrangère :
--  * lignes de documents légaux vers un produit ou un client supprimé (WDT, WZ, CMR, proforma, factures) ;
--  * historique : customer_order_changelog (l'entrée « delete » est écrite après la suppression de la commande),
--    test_product_changelog, shipment_log ;
--  * tables écrites par le scanner d'entrepôt (carton_log, carton_stock, palets_delivery, carton_pallet_connection) :
--    une clé étrangère y refuserait ses écritures ;
--  * lignes de commande vers un produit supprimé (customer_order_product.id_product) : historique des commandes.

-- customer_order_product.id_order → customer_order (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product' AND CONSTRAINT_NAME = 'fk_customer_order_product_id_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `customer_order_product` ADD CONSTRAINT `fk_customer_order_product_id_order` FOREIGN KEY (`id_order`) REFERENCES `customer_order` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ordered_product_in_proforma_by_franchisees.id_order → customer_order (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order') = 1),
    'DELETE x FROM `ordered_product_in_proforma_by_franchisees` x WHERE x.`id_order` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `customer_order` y WHERE y.id = x.`id_order`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees' AND CONSTRAINT_NAME = 'fk_ordered_product_franchisees_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `ordered_product_in_proforma_by_franchisees` ADD CONSTRAINT `fk_ordered_product_franchisees_order` FOREIGN KEY (`id_order`) REFERENCES `customer_order` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wdt_product.id_document_wdt → document_wdt (ON DELETE RESTRICT)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt_product' AND CONSTRAINT_NAME = 'fk_document_wdt_product_id_document_wdt' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_wdt_product` ADD CONSTRAINT `fk_document_wdt_product_id_document_wdt` FOREIGN KEY (`id_document_wdt`) REFERENCES `document_wdt` (`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wz_product.id_document_wz → document_wz (ON DELETE RESTRICT)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product' AND CONSTRAINT_NAME = 'fk_document_wz_product_id_document_wz' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_wz_product` ADD CONSTRAINT `fk_document_wz_product_id_document_wz` FOREIGN KEY (`id_document_wz`) REFERENCES `document_wz` (`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_proforma_product.id_proforma → document_proforma (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma_product' AND CONSTRAINT_NAME = 'fk_document_proforma_product_id_proforma' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_proforma_product` ADD CONSTRAINT `fk_document_proforma_product_id_proforma` FOREIGN KEY (`id_proforma`) REFERENCES `document_proforma` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_invoice_product.id_document_invoice → document_invoice (ON DELETE RESTRICT)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice_product' AND CONSTRAINT_NAME = 'fk_document_invoice_product_id_document_invoice' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_invoice_product` ADD CONSTRAINT `fk_document_invoice_product_id_document_invoice` FOREIGN KEY (`id_document_invoice`) REFERENCES `document_invoice` (`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_order_shipping_difference_products.id_document_order_shipping_difference → document_order_shipping_difference (ON DELETE RESTRICT)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference_products') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_order_shipping_difference_products' AND CONSTRAINT_NAME = 'fk_doc_shipping_diff_products_difference' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_order_shipping_difference_products` ADD CONSTRAINT `fk_doc_shipping_diff_products_difference` FOREIGN KEY (`id_document_order_shipping_difference`) REFERENCES `document_order_shipping_difference` (`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_cmr_packages.id_document_cmr → document_cmr (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr') = 1),
    'DELETE x FROM `document_cmr_packages` x WHERE x.`id_document_cmr` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `document_cmr` y WHERE y.id = x.`id_document_cmr`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages' AND CONSTRAINT_NAME = 'fk_document_cmr_packages_id_document_cmr' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `document_cmr_packages` ADD CONSTRAINT `fk_document_cmr_packages_id_document_cmr` FOREIGN KEY (`id_document_cmr`) REFERENCES `document_cmr` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ksef_submission.id_document_wdt → document_wdt (ON DELETE RESTRICT)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ksef_submission') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wdt') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ksef_submission' AND CONSTRAINT_NAME = 'fk_ksef_submission_id_document_wdt' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `ksef_submission` ADD CONSTRAINT `fk_ksef_submission_id_document_wdt` FOREIGN KEY (`id_document_wdt`) REFERENCES `document_wdt` (`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- complaint_attachment.complaint_id → complaint (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_attachment') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint') = 1),
    'DELETE x FROM `complaint_attachment` x WHERE x.`complaint_id` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `complaint` y WHERE y.id = x.`complaint_id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_attachment') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_attachment' AND CONSTRAINT_NAME = 'fk_complaint_attachment_complaint_id' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `complaint_attachment` ADD CONSTRAINT `fk_complaint_attachment_complaint_id` FOREIGN KEY (`complaint_id`) REFERENCES `complaint` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- final_product_customer_price.id_final_product → final_product (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1),
    'DELETE x FROM `final_product_customer_price` x WHERE x.`id_final_product` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `final_product` y WHERE y.id = x.`id_final_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price' AND CONSTRAINT_NAME = 'fk_final_product_customer_price_id_final_product' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `final_product_customer_price` ADD CONSTRAINT `fk_final_product_customer_price_id_final_product` FOREIGN KEY (`id_final_product`) REFERENCES `final_product` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- final_product_customer_price.id_customer → customer (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer') = 1),
    'DELETE x FROM `final_product_customer_price` x WHERE x.`id_customer` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `customer` y WHERE y.id = x.`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product_customer_price' AND CONSTRAINT_NAME = 'fk_final_product_customer_price_id_customer' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `final_product_customer_price` ADD CONSTRAINT `fk_final_product_customer_price_id_customer` FOREIGN KEY (`id_customer`) REFERENCES `customer` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- franchisee_final_product.id_final_product → final_product (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1),
    'DELETE x FROM `franchisee_final_product` x WHERE x.`id_final_product` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `final_product` y WHERE y.id = x.`id_final_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product' AND CONSTRAINT_NAME = 'fk_franchisee_final_product_id_final_product' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `franchisee_final_product` ADD CONSTRAINT `fk_franchisee_final_product_id_final_product` FOREIGN KEY (`id_final_product`) REFERENCES `final_product` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- raw_material_price_changelog.id_raw_material → raw_material (ON DELETE CASCADE)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material') = 1),
    'DELETE x FROM `raw_material_price_changelog` x WHERE x.`id_raw_material` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `raw_material` y WHERE y.id = x.`id_raw_material`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND CONSTRAINT_NAME = 'fk_raw_material_price_changelog_id_raw_material' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `raw_material_price_changelog` ADD CONSTRAINT `fk_raw_material_price_changelog_id_raw_material` FOREIGN KEY (`id_raw_material`) REFERENCES `raw_material` (`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_recipe.id_product → final_product (ON DELETE SET NULL)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1),
    'UPDATE `product_recipe` x SET x.`id_product` = NULL WHERE x.`id_product` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `final_product` y WHERE y.id = x.`id_product`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe' AND CONSTRAINT_NAME = 'fk_product_recipe_id_product' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0),
    'ALTER TABLE `product_recipe` ADD CONSTRAINT `fk_product_recipe_id_product` FOREIGN KEY (`id_product`) REFERENCES `final_product` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- n_page_view.id_user : orphelins seulement (des lignes valent 0)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_page_view') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user') = 1),
    'DELETE x FROM `n_page_view` x WHERE x.`id_user` <> 0 AND NOT EXISTS (SELECT 1 FROM `user` y WHERE y.id = x.`id_user`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- n_doc_distribution.id_customer : orphelins seulement (des lignes valent 0)
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_doc_distribution') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer') = 1),
    'DELETE x FROM `n_doc_distribution` x WHERE x.`id_customer` <> 0 AND NOT EXISTS (SELECT 1 FROM `customer` y WHERE y.id = x.`id_customer`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

