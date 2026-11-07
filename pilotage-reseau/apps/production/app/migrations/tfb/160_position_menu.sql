-- [253] Menu ERP według stanowiska (makieta 26) : pozycje railu (App\Core\Web\Rail::ITEMS) w menu stanowiska i strona,
--   którą pozycja otwiera (np. Jakość : « Zakupy » → kontrola przyjęć). Stanowisko bez wierszy = całe menu (25 pozycji)
--   ograniczone do stron, które widzi. Uprawnienia do stron bez zmian (n_page_access) : strony spoza menu zostają dostępne
--   z wyszukiwarki (Ctrl K) i linków. Administrator : całe menu ; sesja administratora « jako stanowisko » : menu stanowiska.
--   Domyślne menu 4 stanowisk biurowych ; zmiana na stronie « Uprawnienia i menu ». Rejouable (INSERT IGNORE).

CREATE TABLE IF NOT EXISTS n_position_menu (
    id_position INT NOT NULL COMMENT 'n_position.id (0 = bez stanowiska)',
    item        VARCHAR(32) NOT NULL COMMENT 'klucz pozycji railu (Rail::ITEMS)',
    open_path   VARCHAR(120) NULL COMMENT 'strona otwierana z railu (null = strona pozycji)',
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_position, item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Kierownik produkcji : 11 pozycji (dzień, zespół, produkcja, magazyn, tablety, zakupy, surowce, dostawcy, produkty, marże).
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, m.item, m.open_path FROM n_position p
JOIN (SELECT 'today' AS item, NULL AS open_path UNION ALL SELECT 'team_tasks', NULL UNION ALL SELECT 'production', NULL
      UNION ALL SELECT 'warehouse', NULL UNION ALL SELECT 'team', NULL UNION ALL SELECT 'tablets', NULL
      UNION ALL SELECT 'purchasing', NULL UNION ALL SELECT 'materials', NULL UNION ALL SELECT 'suppliers', NULL
      UNION ALL SELECT 'products', NULL UNION ALL SELECT 'margins', NULL) m
WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Specjalista ds. jakości : 6 pozycji, otwierane od razu na jego pracy (przyjęcia, niezgodności, ocena dostawców).
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, m.item, m.open_path FROM n_position p
JOIN (SELECT 'today' AS item, NULL AS open_path UNION ALL SELECT 'purchasing', '/workshop/receiving'
      UNION ALL SELECT 'production', '/purchasing/nc' UNION ALL SELECT 'suppliers', '/purchasing/supplier-review'
      UNION ALL SELECT 'materials', NULL UNION ALL SELECT 'products', NULL) m
WHERE p.code = 'JAKOSC';

-- Logistyka i wysyłki : 6 pozycji.
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, m.item, m.open_path FROM n_position p
JOIN (SELECT 'today' AS item, NULL AS open_path UNION ALL SELECT 'shipping_day', NULL UNION ALL SELECT 'trucks', NULL
      UNION ALL SELECT 'shipments', NULL UNION ALL SELECT 'orders', NULL UNION ALL SELECT 'clients', NULL) m
WHERE p.code = 'LOGISTYKA';

-- Administracja i fakturowanie : 7 pozycji (Koszty otwierane na fakturach i kosztach).
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, m.item, m.open_path FROM n_position p
JOIN (SELECT 'today' AS item, NULL AS open_path UNION ALL SELECT 'mail', NULL UNION ALL SELECT 'invoicing', NULL
      UNION ALL SELECT 'costs', '/finances/invoices' UNION ALL SELECT 'orders', NULL UNION ALL SELECT 'clients', NULL
      UNION ALL SELECT 'complaints', NULL) m
WHERE p.code = 'ADMINISTRACJA';
