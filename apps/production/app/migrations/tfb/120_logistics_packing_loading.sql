-- Logistyka i pakowanie (28/10/2026) : ekran paletyzacji na żywo, pakowanie (kartony, sztuki, czas), załadunek ciężarówki
-- (przeciągnij palety i klientów) → plan na tablecie kierowcy. Rejouable.
-- 1. n_pallet_entry : kartony położone na paletę wpisane ręcznie (tablet paletyzacji : + i klawiatura) ; « na palecie » =
--    kartony zeskanowane na palety (palets_delivery / carton_pallet_connection) + te wpisy.
-- 2. n_packing_count : pakowanie dnia wysyłki — kartony spakowane na produkt ; base_count = kartony produktu na stanie lub
--    na paletach w chwili pierwszego wpisu : kartony, które potem pojawią się na stanie (etykieta), zmniejszają « spakowane,
--    czekają », żeby nic nie liczyć dwa razy.
-- 3. n_load_plan : plan naczepy (miejsce → klient, numer palety albo paleta planowana) ; layout = wersja robocza,
--    sent_layout = wersja wysłana na tablet kierowcy (numer wersji, kiedy, kto).
-- 4. n_load_check : kierowca potwierdza załadunek miejsca (paleta na swoim miejscu).

CREATE TABLE IF NOT EXISTS n_pallet_entry (
    id INT NOT NULL AUTO_INCREMENT,
    ship_date DATE NOT NULL,
    id_customer INT NOT NULL,
    id_product INT NOT NULL,
    cartons INT NOT NULL,
    source ENUM('tablet', 'erp') NOT NULL DEFAULT 'tablet',
    id_employee INT NULL,
    id_user INT NULL,
    id_device INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pallet_entry_day (ship_date, id_customer, id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_packing_count (
    ship_date DATE NOT NULL,
    id_product INT NOT NULL,
    packed INT NOT NULL DEFAULT 0,
    base_count INT NOT NULL DEFAULT 0,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ship_date, id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_load_plan (
    ship_date DATE NOT NULL,
    id_carrier_vehicle INT NOT NULL,
    layout LONGTEXT NULL,
    sent_layout LONGTEXT NULL,
    version INT NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    sent_by INT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ship_date, id_carrier_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_load_check (
    ship_date DATE NOT NULL,
    id_carrier_vehicle INT NOT NULL,
    slot_no SMALLINT NOT NULL,
    loaded_at DATETIME NOT NULL,
    id_device INT NULL,
    id_employee INT NULL,
    PRIMARY KEY (ship_date, id_carrier_vehicle, slot_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
