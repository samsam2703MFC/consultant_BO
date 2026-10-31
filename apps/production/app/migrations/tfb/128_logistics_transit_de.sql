-- Plan logistyki [202] : Niemcy (DE) jako kraj przejazdu + « Wszystko do rozplanowania ». Rejouable (MySQL 8 / MariaDB).
-- Ciężarówka PL → BE jedzie przez Niemcy : w niemieckie święto ustawowe obowiązuje zakaz ruchu ciężarówek,
-- więc święto DE od dnia wyjazdu do dnia dostawy przesuwa wyjazd (jak święto PL / BE). Święta regionalne
-- (np. Boże Ciało, Wszystkich Świętych w NRW) dodaje się ręcznie jako dzień wolny DE.
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
    ('logistics.plan_transit_countries', 'DE', 'DE', 'string', NULL,
        'Plan logistyki : kraje przejazdu (np. DE) — święto = zakaz ruchu ciężarówek, przesuwa wyjazd ; puste = bez',
        'Planning logistique : pays traversés (ex. DE) — férié = interdiction de circuler pour les camions, décale le départ ; vide = aucun');

ALTER TABLE n_logistics_holiday MODIFY country CHAR(2) NOT NULL COMMENT 'PL (wyjazd, produkcja), BE (dostawa) albo DE (przejazd)';

-- Pojazd dnia zapisany przy zatwierdzeniu planu (plan_set = 1) : ponowne zatwierdzenie albo « Wszystko do rozplanowania »
-- może go zmienić / usunąć ; wybór ręczny w Dniu wysyłki albo w Załadunku (plan_set = 0) zostaje bez zmian.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_shipping_assignment' AND COLUMN_NAME = 'plan_set');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_shipping_assignment ADD COLUMN plan_set TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = pojazd z zatwierdzenia planu 12 tygodni (plan może go zmienić)'' AFTER vehicle_set',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
