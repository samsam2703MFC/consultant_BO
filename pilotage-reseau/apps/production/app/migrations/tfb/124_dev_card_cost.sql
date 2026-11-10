-- Nowy produkt testowy : krok 4 « Koszt i cena » [198]. Rejouable (MySQL 8 / MariaDB).
-- Receptura z karty (asystent AI / .docx) przypisana do naszych surowców → koszt surowców na sztukę ;
-- cena fabryczna = koszt × współczynnik (2,5 / 3,0 / 4,0) ; cena w sklepie = cena fabryczna × mnożnik (× 3 / 4 / 5) ;
-- czy produkt jest cobotyzowalny i który etap robi cobot.

-- n_dev_card : przypisania, partia, wybrane współczynniki, cena końcowa w sklepie, cobot.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_dev_card' AND COLUMN_NAME = 'cost_json');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_dev_card ADD COLUMN cost_json LONGTEXT NULL COMMENT ''składniki karty → surowce : [{key, src, id_raw_material, qty, price_kg, status, note}]'' AFTER id_type,
        ADD COLUMN yield_pcs INT NULL COMMENT ''sztuk z partii receptury karty'' AFTER cost_json,
        ADD COLUMN factory_coef DECIMAL(5,2) NULL COMMENT ''cena fabryczna = koszt surowców × współczynnik'' AFTER yield_pcs,
        ADD COLUMN store_mult DECIMAL(5,2) NULL COMMENT ''cena w sklepie netto = cena fabryczna × mnożnik'' AFTER factory_coef,
        ADD COLUMN store_price_eur DECIMAL(10,2) NULL COMMENT ''cena końcowa w sklepie netto (zaokrąglona albo wpisana)'' AFTER store_mult,
        ADD COLUMN cost_pc_pln DECIMAL(12,4) NULL COMMENT ''koszt surowców na sztukę przy ostatnim zapisie'' AFTER store_price_eur,
        ADD COLUMN cost_at DATETIME NULL COMMENT ''ostatni zapis kosztu i ceny'' AFTER cost_pc_pln,
        ADD COLUMN cobot ENUM(''yes'',''no'',''check'') NULL COMMENT ''cobotyzowalny : tak / nie / do sprawdzenia'' AFTER cost_at,
        ADD COLUMN cobot_steps VARCHAR(500) NULL COMMENT ''etapy dla cobota, oddzielone « | »'' AFTER cobot',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pamięć przypisań : nazwa składnika z karty → surowiec (wybór operatora, użyty w następnych kartach).
CREATE TABLE IF NOT EXISTS n_dev_ingredient_map (
    name_key VARCHAR(190) NOT NULL COMMENT 'nazwa składnika : małe litery, bez akcentów i ilości',
    name VARCHAR(255) NOT NULL COMMENT 'nazwa z karty (ostatnia)',
    id_raw_material INT NOT NULL COMMENT 'raw_material.id',
    uses INT NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name_key),
    KEY idx_dev_ingredient_material (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Nowy produkt : składnik karty → surowiec [198]';

-- Parametry (Ustawienia › Pozostałe › Ceny nowych produktów).
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
    ('new_product.factory_coefs', '2.5,3,4', '2.5,3,4', 'string', '×', 'Nowy produkt : współczynniki ceny fabrycznej do wyboru (cena fabryczna = koszt surowców × współczynnik), oddzielone przecinkiem',
        'Nouveau produit : coefficients du prix usine proposés (prix usine = coût matières × coefficient), séparés par une virgule'),
    ('new_product.factory_coef', '3', '3', 'decimal', '×', 'Nowy produkt : domyślny współczynnik ceny fabrycznej',
        'Nouveau produit : coefficient du prix usine par défaut'),
    ('new_product.store_mults', '3,4,5', '3,4,5', 'string', '×', 'Nowy produkt : mnożniki ceny w sklepie do wyboru (cena w sklepie netto = cena fabryczna × mnożnik), oddzielone przecinkiem',
        'Nouveau produit : multiplicateurs du prix magasin proposés (prix magasin HT = prix usine × multiplicateur), séparés par une virgule'),
    ('new_product.store_mult', '4', '4', 'decimal', '×', 'Nowy produkt : domyślny mnożnik ceny w sklepie',
        'Nouveau produit : multiplicateur du prix magasin par défaut'),
    ('new_product.store_round_eur', '0.1', '0.1', 'decimal', 'EUR', 'Nowy produkt : zaokrąglenie ceny końcowej w sklepie (np. 0,10 € ; 0 = bez zaokrąglenia)',
        'Nouveau produit : arrondi du prix magasin final (ex. 0,10 € ; 0 = sans arrondi)');
