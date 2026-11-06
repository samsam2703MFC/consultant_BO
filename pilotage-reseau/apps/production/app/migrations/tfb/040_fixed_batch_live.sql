-- Stałe partie (📌) = miejsca w tygodniu typowym : partie wyliczone na żywo (zamówienia + uzupełnienie stanu, w pełnych
-- partiach) wchodzą najpierw na przypięte dni, nadmiar czeka w « Do zrobienia » ; przypięcie nie dokłada produkcji.
-- placed = ile partia przypięta dostała w tym dniu tygodnia (liczone przy każdym otwarciu tygodnia) ;
-- released = blok przesunięty ręcznie z tego dnia — w tym tygodniu dzień nie jest już uzupełniany sam.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_fixed_batch_week' AND COLUMN_NAME = 'released');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_prod_fixed_batch_week ADD COLUMN released TINYINT(1) NOT NULL DEFAULT 0 AFTER placed', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
