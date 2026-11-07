-- Licznik stron (Ustawienia › Licznik stron) : wyświetlenia stron ERP na dzień, trasę i użytkownika,
-- aby po miesiącu zobaczyć, które strony i pozycje menu są używane, a które można uprościć.

CREATE TABLE IF NOT EXISTS tfb_page_view (
    day        DATE NOT NULL,
    route      VARCHAR(190) NOT NULL,
    id_user    INT NOT NULL DEFAULT 0,
    views      INT UNSIGNED NOT NULL DEFAULT 0,
    last_path  VARCHAR(255) NULL,
    last_at    DATETIME NOT NULL,
    PRIMARY KEY (day, route, id_user),
    KEY idx_tfb_page_view_route (route)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
