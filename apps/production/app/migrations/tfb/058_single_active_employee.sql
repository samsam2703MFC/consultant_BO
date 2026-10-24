-- Jeden wskaźnik « pracownik aktywny » : Budżet personelu (tfb_staff_pay.active) i moduł produkcji
-- (tfb_prod_employee.active : tablet, pointage, zespoły) miały dwa osobne pola — odejście zaznaczone
-- w budżecie zostawało aktywne na tablecie. Zostaje tfb_prod_employee.active ; nieobecni z budżetu
-- przechodzą do profilu produkcji (tworzonego, gdy go nie ma), potem kolumna budżetu znika.
-- MySQL 8 : kroki warunkowe (bez DROP COLUMN IF EXISTS).

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_staff_pay' AND COLUMN_NAME = 'active');
SET @sql := IF(@col > 0, 'INSERT INTO tfb_prod_employee (id_production_employee, active) SELECT sp.id_production_employee, 0 FROM tfb_staff_pay sp WHERE sp.active = 0 ON DUPLICATE KEY UPDATE active = 0', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@col > 0, 'ALTER TABLE tfb_staff_pay DROP COLUMN active', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
