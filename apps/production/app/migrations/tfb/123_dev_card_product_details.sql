-- Jeden kreator « Nowy produkt testowy » (/test-products/new) [197] : karta z filmu, z pliku, z gotowej karty .docx
-- albo sam pomysł — razem z polami dawnego formularza produktu testowego. Rejouable (MySQL 8 / MariaDB).
-- n_dev_card : kategoria produktu (category_product), typ etykiety (0 mała, 1 duża) i typ produktu testowego
-- (test_product_type) zapisane w szkicu i przeniesione do test_product przy zapisie.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_dev_card' AND COLUMN_NAME = 'id_category');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_dev_card ADD COLUMN id_category INT NULL COMMENT ''category_product.id (opcjonalnie)'' AFTER season_quarters,
        ADD COLUMN label_type TINYINT NULL COMMENT ''etykieta : 0 mała, 1 duża'' AFTER id_category,
        ADD COLUMN id_type INT NULL COMMENT ''test_product_type.id (NULL = Produkt)'' AFTER label_type',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
