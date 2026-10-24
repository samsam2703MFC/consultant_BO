-- Tydzień typowy : godzina startu partii w dniu (blok tablicy tygodnia) i stałej partii co tydzień (📌) —
-- ten sam dzień i ta sama godzina w każdym tygodniu. NULL = bez godziny (kolejność w dniu dowolna).

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_week_slot' AND COLUMN_NAME = 'start_time');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_prod_week_slot ADD COLUMN start_time TIME NULL AFTER quantity', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_fixed_batch' AND COLUMN_NAME = 'start_time');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_prod_fixed_batch ADD COLUMN start_time TIME NULL AFTER quantity', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
