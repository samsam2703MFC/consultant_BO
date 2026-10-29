-- Kartony i palety [205] : katalog kartonów (rail Produkt › Kartony i palety) + krok « Paleta » w karcie produktu.
-- Rejouable (MySQL 8 / MariaDB).
--  - n_carton : karton = surowiec-opakowanie (raw_material, kupowany i liczony w recepturze) ; tu wymiary zewnętrzne,
--    maksymalny ładunek (kg zawartości), rodzaj, notatka, aktywny. Waga kartonu = raw_material.packaging_unit_weight_g
--    (ta sama co w BDO) ; wymiary zapisywane także w carton_dimension (tabela ERP, id = surowiec).
--  - n_prod_item_logistics : karton z katalogu (id_carton) i ograniczenia palety produktu (puste = parametry).
--  - Kreator karty produktu : nowy krok 3 « Paleta » — zapisane kroki ≥ 3 przesunięte o 1 (raz : dopóki ograniczenie
--    ck_tfb_settings_step pozwala tylko na 1–8).

CREATE TABLE IF NOT EXISTS n_carton (
    id_raw_material INT NOT NULL COMMENT 'raw_material.id (opakowanie : karton)',
    length_mm SMALLINT UNSIGNED NULL COMMENT 'długość zewnętrzna (mm)',
    width_mm SMALLINT UNSIGNED NULL COMMENT 'szerokość zewnętrzna (mm)',
    height_mm SMALLINT UNSIGNED NULL COMMENT 'wysokość zewnętrzna (mm)',
    max_load_kg DECIMAL(6,2) NULL COMMENT 'maks. waga zawartości (kg) ; NULL = nie podano',
    kind ENUM('klapowy', 'fasonowy', 'klejony', 'inny') NULL,
    note VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    PRIMARY KEY (id_raw_material),
    CONSTRAINT fk_n_carton_material FOREIGN KEY (id_raw_material) REFERENCES raw_material (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kartony : wymiary, maks. ładunek, rodzaj [205]';

-- Katalog startowy : surowce « karton… », kartony z carton_dimension i kartony sprzedażowe produktów (PPWR).
-- Wymiary : carton_dimension (ERP), inaczej aktywne wymiary PPWR ; rodzaj z kategorii surowca. Tylko gdy tabele ERP są
-- (bazy testowe mają ich część).
SET @erp := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME IN ('raw_material', 'carton_dimension', 'raw_material_ppwr_profile', 'raw_material_ppwr_dimension', 'final_product_ppwr_sales_packaging'));
SET @sql := IF(@erp = 5,
    'INSERT IGNORE INTO n_carton (id_raw_material, length_mm, width_mm, height_mm, kind)
     SELECT rm.id,
            COALESCE(cd.length, ROUND(pd.length_mm)), COALESCE(cd.width, ROUND(pd.width_mm)), COALESCE(cd.height, ROUND(pd.height_mm)),
            CASE rm.id_category WHEN 200 THEN ''klapowy'' WHEN 203 THEN ''fasonowy'' WHEN 205 THEN ''klejony'' ELSE NULL END
     FROM raw_material rm
     LEFT JOIN carton_dimension cd ON cd.id = rm.id
     LEFT JOIN (SELECT p.id_raw_material, d.length_mm, d.width_mm, d.height_mm
                FROM raw_material_ppwr_profile p
                JOIN raw_material_ppwr_dimension d ON d.id = (SELECT MAX(d2.id) FROM raw_material_ppwr_dimension d2
                                                             WHERE d2.id_raw_material_ppwr_profile = p.id AND d2.is_active = 1)) pd ON pd.id_raw_material = rm.id
     WHERE LOWER(TRIM(rm.name)) LIKE ''karton%''
        OR rm.id IN (SELECT id FROM carton_dimension)
        OR rm.id IN (SELECT id_outer_raw_material FROM final_product_ppwr_sales_packaging WHERE id_outer_raw_material IS NOT NULL)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_item_logistics' AND COLUMN_NAME = 'id_carton');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_prod_item_logistics
        ADD COLUMN id_carton INT NULL COMMENT ''n_carton.id_raw_material : karton z katalogu (wymiary i tara kopiowane)'' AFTER id_item,
        ADD COLUMN pallet_max_height_mm SMALLINT UNSIGNED NULL COMMENT ''maks. wysokość palety z paletą (mm) ; NULL = parametr'' AFTER pallet_type,
        ADD COLUMN pallet_max_kg SMALLINT UNSIGNED NULL COMMENT ''maks. waga palety brutto (kg) ; NULL = parametr'' AFTER pallet_max_height_mm,
        ADD COLUMN pallet_overhang_mm TINYINT UNSIGNED NULL COMMENT ''dopuszczalne wystawanie poza paletę z każdej strony (mm) ; NULL = parametr'' AFTER pallet_max_kg',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
    ('logistics.pallet_max_height_mm', '1800', '1800', 'int', 'mm', 'Paleta : maks. wysokość z paletą (krok « Paleta », katalog kartonów)',
        'Palette : hauteur maximale palette comprise (étape « Paleta », catalogue des cartons)'),
    ('logistics.pallet_max_kg', '1000', '1000', 'int', 'kg', 'Paleta : maks. waga brutto', 'Palette : poids brut maximal'),
    ('logistics.pallet_overhang_mm', '0', '0', 'int', 'mm', 'Paleta : dopuszczalne wystawanie kartonów poza paletę (z każdej strony)',
        'Palette : débord autorisé des cartons (de chaque côté)'),
    ('logistics.truck_inner_height_mm', '2550', '2550', 'int', 'mm', 'Ciężarówka : wysokość wnętrza — piętrowanie, gdy 2 palety się mieszczą',
        'Camion : hauteur intérieure — gerbage quand 2 palettes tiennent');

-- Kreator : nowy krok 3 « Paleta » (Opakowanie 2 → Paleta 3 → Receptura 4 …) ; tylko raz (ograniczenie jeszcze 1–8).
SET @step8 := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_tfb_settings_step' AND CHECK_CLAUSE LIKE '%and 8%');
-- MySQL 8.0.16+ : DROP CHECK ; MariaDB : DROP CONSTRAINT.
SET @sql := IF(@step8 = 0, 'DO 0', IF(VERSION() LIKE '%MariaDB%',
    'ALTER TABLE n_prod_product_settings DROP CONSTRAINT ck_tfb_settings_step',
    'ALTER TABLE n_prod_product_settings DROP CHECK ck_tfb_settings_step'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE n_prod_product_settings SET wizard_step = wizard_step + 1 WHERE @step8 = 1 AND wizard_step >= 3;
SET @sql := IF(@step8 = 0, 'DO 0', 'ALTER TABLE n_prod_product_settings ADD CONSTRAINT ck_tfb_settings_step CHECK (wizard_step BETWEEN 1 AND 9)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
