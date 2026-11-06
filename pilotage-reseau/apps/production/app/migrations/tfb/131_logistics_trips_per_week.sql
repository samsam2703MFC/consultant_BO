-- Plan logistyki [208] : liczba tras (zmian) ciężarówki z kierowcą w tygodniu — standard 1 trasa na kierowcę na tydzień.
-- n_vehicle.trips_per_week : domyślnie dla ciężarówki (Pojazdy i pojemność, Kalendarz tras) ; n_logistics_plan_vehicle.trips :
-- wyjątek w tygodniu (⚙ w Tablicy) ; n_logistics_trip.trip_no : 1., 2. … trasa tygodnia. Rejouable (MySQL 8 / MariaDB).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_vehicle' AND COLUMN_NAME = 'trips_per_week');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_vehicle ADD COLUMN trips_per_week TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''tras (zmian) w tygodniu : standard 1 trasa na kierowcę na tydzień'' AFTER departure_time',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_logistics_plan_vehicle' AND COLUMN_NAME = 'trips');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_logistics_plan_vehicle ADD COLUMN trips TINYINT UNSIGNED NULL COMMENT ''tras w tym tygodniu ; NULL = z ciężarówki'' AFTER unavailable',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_logistics_trip' AND COLUMN_NAME = 'trip_no');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_logistics_trip ADD COLUMN trip_no TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''numer trasy w tygodniu (1, 2 …)'' AFTER id_carrier_vehicle,
        DROP PRIMARY KEY, ADD PRIMARY KEY (week_start, id_carrier_vehicle, trip_no)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
