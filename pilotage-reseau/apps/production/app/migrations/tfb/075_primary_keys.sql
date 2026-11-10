
-- Nettoyage de la base 2/7 : clé primaire pour les 16 tables d'origine qui n'en avaient pas.
-- Colonne id auto-incrémentée INVISIBLE : absente de « SELECT * » et des INSERT sans liste de colonnes, donc
-- rien ne change pour le code de l'ERP ni pour les programmes extérieurs (scanner d'entrepôt : carton_log).
-- smp a déjà une colonne id (non unique, écrite ailleurs) : sa clé s'appelle row_id.

-- carton_log
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_log') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_log' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carton_log' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `carton_log` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- customer_order_product
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order_product' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `customer_order_product` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_cmr_packages
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_cmr_packages' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `document_cmr_packages` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_invoice_product
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice_product' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_invoice_product' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `document_invoice_product` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- document_wz_product
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_wz_product' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `document_wz_product` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- flattened_recipe_ingredient
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_ingredient') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_ingredient' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_ingredient' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `flattened_recipe_ingredient` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- flattened_recipe_recipe
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_recipe') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_recipe' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flattened_recipe_recipe' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `flattened_recipe_recipe` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- franchisee_final_product
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'franchisee_final_product' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `franchisee_final_product` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- material_allergen_connection
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_allergen_connection') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_allergen_connection' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_allergen_connection' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `material_allergen_connection` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ordered_product_in_proforma_by_franchisees
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ordered_product_in_proforma_by_franchisees' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `ordered_product_in_proforma_by_franchisees` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- pre_calculated_nutrition
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pre_calculated_nutrition') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pre_calculated_nutrition' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pre_calculated_nutrition' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `pre_calculated_nutrition` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_recipe_connection
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_connection') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_connection' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_connection' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `product_recipe_connection` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_recipe_preparation_connection
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_preparation_connection') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_preparation_connection' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_recipe_preparation_connection' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `product_recipe_preparation_connection` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- raw_material_price_changelog
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `raw_material_price_changelog` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- slfood_order_collector_products
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slfood_order_collector_products') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slfood_order_collector_products' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slfood_order_collector_products' AND COLUMN_NAME = 'id') = 0),
    'ALTER TABLE `slfood_order_collector_products` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- smp
SET @sql := IF(((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smp') = 1 AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smp' AND CONSTRAINT_TYPE = 'PRIMARY KEY') = 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smp' AND COLUMN_NAME = 'row_id') = 0),
    'ALTER TABLE `smp` ADD COLUMN `row_id` INT NOT NULL AUTO_INCREMENT INVISIBLE FIRST, ADD PRIMARY KEY (`row_id`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

