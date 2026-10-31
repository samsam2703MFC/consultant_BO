-- Nowy produkt, krok « Koszt i cena » [198] : składnik karty przypisany także do naszej receptury (masa, półprodukt,
-- produkt) — koszt = koszt surowców receptury (product_recipe.precalculated_material_cost ÷ yield_quantity) × ilość.
-- Pamięć wyborów : surowiec albo receptura. Rejouable (MySQL 8 / MariaDB).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_dev_ingredient_map' AND COLUMN_NAME = 'id_recipe');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_dev_ingredient_map MODIFY id_raw_material INT NULL COMMENT ''raw_material.id (albo receptura)'',
        ADD COLUMN id_recipe INT NULL COMMENT ''product_recipe.id (albo surowiec)'' AFTER id_raw_material',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
