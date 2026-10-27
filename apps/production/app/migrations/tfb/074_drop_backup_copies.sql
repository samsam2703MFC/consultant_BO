
-- Nettoyage de la base (décision 24.10.2026) 1/7 : les 10 copies de sauvegarde faites à la main dans l'ERP d'origine
-- (…_cc, …_2, dates), jamais lues ni écrites par le code, sont supprimées.
-- Export : le déploiement (deploy/server.sh) sauvegarde toute la base avant d'appliquer des migrations en attente
-- (/root/erp_import/sauvegardes). En production, faire la même sauvegarde avant « php Bin/tfb_migrate.php apply ».

DROP TABLE IF EXISTS `carton_stock_2`,
    `customer_order_product_cc`,
    `document_order_shipping_difference_products_01102026`,
    `document_order_shipping_difference_products_1709`,
    `document_order_shipping_difference_products_cc`,
    `document_proforma_product_cc`,
    `document_wdt_product_cc`,
    `final_product_customer_price_cc`,
    `final_product_customer_price_cc_01_05`,
    `final_product_to_compare`;
