-- [306] Trasy w Logistyce : trasa = stali klienci, pojazd zwykle ten sam (pierwszy z pojazdów trasy) i kierowca zwykle ten sam.
--   Kolumna kierowcy trasy (pracownik, n_prod_employee.id ; rejouable) i pozycja « Trasy » w menu stanowisk, które mają
--   już « Dzień wysyłki » (Logistyka).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_tour' AND COLUMN_NAME = 'id_driver');
SET @sql := IF(@c = 0, 'ALTER TABLE n_tour ADD COLUMN id_driver INT NULL COMMENT ''kierowca zwykle na tej trasie (n_prod_employee.id)'' AFTER departure_time', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO n_position_menu (id_position, item)
SELECT id_position, 'tours' FROM n_position_menu WHERE item = 'shipping_day';
