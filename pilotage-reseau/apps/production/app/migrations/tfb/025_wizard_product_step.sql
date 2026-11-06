-- Jeden kreator produktu, w tej samej kolejności co « Nowy produkt z recepturą » :
-- 1 Produkt (dane ERP), 2 Logistyka, 3 Receptura, 4 Tryb i zapasy, 5 Grupa i wypiek, 6 Etapy i czasy,
-- 7 Kontrola czasów, 8 Zatwierdzenie. Ta sama karta otwiera się z edycji produktu ERP.
-- wizard_step (najdalszy krok) przeliczony z dawnych 7 kroków (artykuł, tryb, grupa, receptura,
-- marszruta, czasy, zatwierdzenie).

-- MySQL 8.0.16+ : DROP CHECK ; MariaDB : DROP CONSTRAINT.
SET @sql := IF(VERSION() LIKE '%MariaDB%',
    'ALTER TABLE tfb_prod_product_settings DROP CONSTRAINT ck_tfb_settings_step',
    'ALTER TABLE tfb_prod_product_settings DROP CHECK ck_tfb_settings_step');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE tfb_prod_product_settings
SET wizard_step = CASE wizard_step WHEN 1 THEN 2 WHEN 2 THEN 4 WHEN 3 THEN 5 WHEN 4 THEN 5 WHEN 5 THEN 6 WHEN 6 THEN 7 ELSE 8 END;
ALTER TABLE tfb_prod_product_settings ADD CONSTRAINT ck_tfb_settings_step CHECK (wizard_step BETWEEN 1 AND 8);
