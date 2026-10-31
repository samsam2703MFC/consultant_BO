-- Planowanie logistyki na 12 tygodni [200] (Logistyka i pakowanie › Plan 12 tygodni). Rejouable (MySQL 8 / MariaDB).
-- Prognoza palet klienta na tydzień : palety tego samego tygodnia rok temu (N-1) × współczynnik, a bez N-1 średnia
-- z 3 ostatnich miesięcy ; zamówienia już złożone zamiast prognozy ; dodatkowe palety (święta) w % ; klienci
-- przeciągani na ciężarówki ; dzień wyjazdu przesunięty przy świętach PL / BE ; urlopy kierowców ; zatwierdzenie
-- tygodnia = pojazdy klientów na dzień wyjazdu (Dzień wysyłki, Załadunek), gdy nie wybrano ich tam ręcznie.

-- Tydzień planu : wyjazd ustawiony ręcznie, zatwierdzenie.
CREATE TABLE IF NOT EXISTS n_logistics_plan_week (
    week_start DATE NOT NULL COMMENT 'poniedziałek tygodnia',
    ship_date DATE NULL COMMENT 'dzień wyjazdu wybrany ręcznie (NULL = dzień domyślny, przesunięty przy święcie)',
    approved_at DATETIME NULL,
    approved_by INT NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : tydzień (wyjazd, zatwierdzenie) [200]';

-- Klient w tygodniu : palety wpisane ręcznie (zamiast prognozy) i ciężarówka.
CREATE TABLE IF NOT EXISTS n_logistics_plan_load (
    week_start DATE NOT NULL,
    id_customer INT NOT NULL,
    pallets INT NULL COMMENT 'palety wpisane w Prognozie (NULL = prognoza)',
    id_carrier_vehicle INT NULL COMMENT 'ciężarówka (NULL = do rozplanowania)',
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, id_customer),
    KEY idx_logistics_plan_load_vehicle (id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : klient → palety i ciężarówka w tygodniu [200]';

-- Ciężarówka w tygodniu : kierowca na zastępstwo, pojazd niedostępny.
CREATE TABLE IF NOT EXISTS n_logistics_plan_vehicle (
    week_start DATE NOT NULL,
    id_carrier_vehicle INT NOT NULL,
    id_driver INT NULL COMMENT 'kierowca na ten tydzień (n_prod_employee ; NULL = kierowca pojazdu)',
    unavailable TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'pojazd niedostępny w tym tygodniu',
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : ciężarówka w tygodniu (kierowca, dostępność) [200]';

-- Dodatkowe palety w tygodniu (święta, akcje) : % na wszystkich klientów ; co roku = ten sam tydzień ISO w następnych latach.
CREATE TABLE IF NOT EXISTS n_logistics_event (
    id INT NOT NULL AUTO_INCREMENT,
    week_start DATE NOT NULL,
    name VARCHAR(80) NOT NULL,
    pct DECIMAL(6,1) NOT NULL COMMENT '+40 = +40 % palet, -10 = -10 %',
    yearly TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'powtarzaj co roku (ten sam tydzień ISO)',
    created_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_logistics_event_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : dodatkowe palety w tygodniu [200]';

-- Współczynnik klienta (palety N-1 × współczynnik) wpisany ręcznie ; bez wpisu : trend 3 miesięcy (teraz ÷ rok temu).
CREATE TABLE IF NOT EXISTS n_logistics_customer_coef (
    id_customer INT NOT NULL,
    coef DECIMAL(5,2) NOT NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : współczynnik N-1 klienta [200]';

-- Nieobecność kierowcy (urlop, choroba) : ciężarówka bez kierowcy w dniu wyjazdu.
CREATE TABLE IF NOT EXISTS n_logistics_absence (
    id INT NOT NULL AUTO_INCREMENT,
    id_employee INT NOT NULL COMMENT 'n_prod_employee',
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    reason VARCHAR(120) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logistics_absence_employee (id_employee, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : urlopy kierowców [200]';

-- Dni wolne dodane ręcznie (np. zamknięcie zakładu) albo święto ustawowe pominięte (święta PL i BE liczone same).
CREATE TABLE IF NOT EXISTS n_logistics_holiday (
    day DATE NOT NULL,
    country CHAR(2) NOT NULL COMMENT 'PL (wyjazd, produkcja) albo BE (dostawa)',
    name VARCHAR(80) NOT NULL,
    off TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = dzień wolny dodany ; 0 = święto ustawowe pominięte',
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (day, country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Plan logistyki : dni wolne dodane / pominięte [200]';

-- Parametry (Ustawienia › Parametry).
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
    ('logistics.plan_weeks', '12', '12', 'int', 'tyg.', 'Plan logistyki : liczba tygodni planu', 'Planning logistique : nombre de semaines'),
    ('logistics.plan_ship_weekday', '2', '2', 'int', NULL, 'Plan logistyki : zwykły dzień wyjazdu (1 = poniedziałek … 5 = piątek)',
        'Planning logistique : jour de départ habituel (1 = lundi … 5 = vendredi)'),
    ('logistics.plan_delivery_days', '1', '1', 'int', 'dni', 'Plan logistyki : dostawa u klienta = wyjazd + dni (święto w dniu dostawy przesuwa wyjazd)',
        'Planning logistique : livraison chez le client = départ + jours (un férié le jour de livraison décale le départ)'),
    ('logistics.plan_avg_weeks', '13', '13', 'int', 'tyg.', 'Plan logistyki : średnia palet z ostatnich tygodni, gdy brak N-1 (13 = 3 miesiące)',
        'Planning logistique : moyenne des palettes des dernières semaines quand il n''y a pas de N-1 (13 = 3 mois)'),
    ('logistics.plan_coef_default', '1', '1', 'decimal', '×', 'Plan logistyki : współczynnik N-1, gdy brak trendu klienta',
        'Planning logistique : coefficient N-1 quand le client n''a pas de tendance'),
    ('logistics.plan_departure_country', 'PL', 'PL', 'string', NULL, 'Plan logistyki : święta kraju wyjazdu (produkcja)', 'Planning logistique : fériés du pays de départ (production)'),
    ('logistics.plan_delivery_country', 'BE', 'BE', 'string', NULL, 'Plan logistyki : święta kraju dostawy (klienci)', 'Planning logistique : fériés du pays de livraison (clients)');
