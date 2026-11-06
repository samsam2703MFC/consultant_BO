-- Plan logistyki › Kalendarz tras [207] : trasa ciężarówki w tygodniu (załadunek, jazda, przerwy, odpoczynki, rozładunki,
-- powrót, odpoczynek tygodniowy). Bez wpisu : trasa z szablonu (parametry niżej) i klientów na ciężarówce ; po zmianie
-- w kalendarzu : fazy zapisane tutaj (JSON). Rejouable (MySQL 8 / MariaDB).
CREATE TABLE IF NOT EXISTS n_logistics_trip (
    week_start DATE NOT NULL COMMENT 'poniedziałek tygodnia planu',
    id_carrier_vehicle INT NOT NULL COMMENT 'carrier_vehicle.id',
    start_at DATETIME NOT NULL COMMENT 'początek pierwszej fazy (załadunek / wyjazd)',
    phases LONGTEXT NOT NULL COMMENT 'JSON : [{k: load|drive|brk|rest|unload|back|weekly, m: minuty, client_id?}]',
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Trasy ciężarówek zmienione ręcznie w Kalendarzu tras [207]';

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
    ('logistics.trip_departure_time', '05:00', '05:00', 'string', NULL, 'Kalendarz tras : godzina załadunku, gdy ciężarówka nie ma własnej', 'Calendrier des trajets : heure de chargement quand le camion n''en a pas'),
    ('logistics.trip_load_min', '60', '60', 'int', 'min', 'Kalendarz tras : załadunek', 'Calendrier des trajets : chargement'),
    ('logistics.trip_drive_out_min', '765', '765', 'int', 'min', 'Kalendarz tras : jazda do pierwszego klienta (bez przerw)', 'Calendrier des trajets : trajet jusqu''au premier client (sans pauses)'),
    ('logistics.trip_between_min', '35', '35', 'int', 'min', 'Kalendarz tras : jazda między klientami', 'Calendrier des trajets : trajet entre deux clients'),
    ('logistics.trip_unload_min', '30', '30', 'int', 'min', 'Kalendarz tras : rozładunek u klienta (stały czas)', 'Calendrier des trajets : déchargement chez le client (temps fixe)'),
    ('logistics.trip_unload_pallet_min', '2', '2', 'int', 'min', 'Kalendarz tras : rozładunek — dodatkowo na paletę', 'Calendrier des trajets : déchargement — en plus par palette'),
    ('logistics.trip_drive_back_min', '765', '765', 'int', 'min', 'Kalendarz tras : powrót do bazy (bez przerw)', 'Calendrier des trajets : retour à la base (sans pauses)'),
    ('logistics.trip_weekly_rest_min', '2700', '2700', 'int', 'min', 'Kalendarz tras : odpoczynek tygodniowy po powrocie (45 h ; skrócony 24 h)', 'Calendrier des trajets : repos hebdomadaire après le retour (45 h ; réduit 24 h)');
