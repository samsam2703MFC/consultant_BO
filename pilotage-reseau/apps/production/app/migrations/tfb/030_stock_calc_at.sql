-- Karta produktu, krok « Tryb i zapasy » : data ostatniego obliczenia stanów min. / maks. ze sprzedaży
-- (zapisana razem ze stanami, gdy użytkownik zapisze obliczone wartości).

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_product_settings' AND COLUMN_NAME = 'stock_calc_at');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tfb_prod_product_settings ADD COLUMN stock_calc_at DATETIME NULL COMMENT ''Ostatnie obliczenie stanów min./maks. ze sprzedaży'' AFTER batch_multiple',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
