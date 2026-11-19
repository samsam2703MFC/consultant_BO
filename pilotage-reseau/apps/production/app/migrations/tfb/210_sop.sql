-- [353] Procedury SOP w produkcji (makiety 69 i 69 B) :
--  - n_sop : procedura (kod, tytuł, cel, obszar, kto, środki ochrony, stan szkic / obowiązuje / archiwum, wersja, przegląd) ;
--    tworzy i zatwierdza Kierownik produkcji (prawo stron « sop ») ;
--  - n_sop_step : etapy — polecenie, opis, zdjęcie (n_sop_photo, w bazie : zrzut ekranu, aparat, plik), oznaczenie BHP /
--    HACCP / jakość, czas, potwierdzenie : odhaczenie, wartość w zakresie (poza zakresem = niezgodność), zdjęcie przy wykonaniu ;
--  - n_sop_link : strony / asystenci ERP, na których procedura jest pod przyciskiem « Procedura » (procedura asystowana) ;
--  - n_sop_run : wykonania (kto, kiedy, skąd, wartości, zdjęcia, wynik) ; n_sop_event : utworzenie, zatwierdzenie wersji ;
--  - pierwsze procedury (szkice, do uzupełnienia zrzutami ekranu) : próbki produkcji, ceny surowców z faktur, faktury KSeF ;
--  - zadanie dnia Kierownika produkcji « SOP dnia » : popraw jedną procedurę albo utwórz nową (zatwierdzona wersja dnia).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT IGNORE / WHERE NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_sop (
    id            INT NOT NULL AUTO_INCREMENT,
    code          VARCHAR(24) NOT NULL,
    title         VARCHAR(200) NOT NULL,
    purpose       TEXT NULL,
    area          VARCHAR(160) NULL COMMENT 'grupa, stanowisko, maszyna, dział',
    performer     VARCHAR(160) NULL COMMENT 'kto wykonuje',
    ppe           VARCHAR(255) NULL COMMENT 'środki ochrony indywidualnej',
    status        ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    version       INT NOT NULL DEFAULT 0 COMMENT 'wersja zatwierdzona (0 = nigdy)',
    changed       TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'zmiany od ostatniego zatwierdzenia',
    review_months TINYINT NOT NULL DEFAULT 12,
    review_at     DATE NULL,
    approved_by   INT NULL,
    approved_at   DATETIME NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sop_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Procedury SOP [353]';

CREATE TABLE IF NOT EXISTS n_sop_photo (
    id         INT NOT NULL AUTO_INCREMENT,
    mime       VARCHAR(20) NOT NULL,
    bytes      MEDIUMBLOB NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Zdjęcia i zrzuty ekranu SOP (etapy i wykonania) [353]';

CREATE TABLE IF NOT EXISTS n_sop_step (
    id        INT NOT NULL AUTO_INCREMENT,
    id_sop    INT NOT NULL,
    sort      SMALLINT NOT NULL DEFAULT 0,
    title     VARCHAR(200) NOT NULL,
    body      TEXT NULL,
    tag       ENUM('bhp','haccp','quality') NULL,
    minutes   SMALLINT NULL,
    confirm   ENUM('check','value','photo') NOT NULL DEFAULT 'check',
    value_min DECIMAL(12,3) NULL,
    value_max DECIMAL(12,3) NULL,
    value_unit VARCHAR(16) NULL,
    id_photo  INT NULL,
    PRIMARY KEY (id),
    KEY idx_sop_step_sop (id_sop, sort),
    CONSTRAINT fk_sop_step_sop FOREIGN KEY (id_sop) REFERENCES n_sop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Etapy SOP [353]';

CREATE TABLE IF NOT EXISTS n_sop_link (
    id_sop INT NOT NULL,
    path   VARCHAR(160) NOT NULL COMMENT 'strona / asystent ERP (bez # i ?)',
    PRIMARY KEY (id_sop, path),
    KEY idx_sop_link_path (path),
    CONSTRAINT fk_sop_link_sop FOREIGN KEY (id_sop) REFERENCES n_sop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='SOP pod przyciskiem « Procedura » na stronach [353]';

CREATE TABLE IF NOT EXISTS n_sop_run (
    id          INT NOT NULL AUTO_INCREMENT,
    id_sop      INT NOT NULL,
    version     INT NOT NULL,
    source_path VARCHAR(160) NULL,
    id_user     INT NULL,
    started_at  DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    result      ENUM('ok','deviation') NOT NULL,
    steps       LONGTEXT NOT NULL COMMENT 'JSON : etap, potwierdzenie, wartość, zdjęcie',
    id_nc       INT NULL COMMENT 'niezgodność (wartość poza zakresem)',
    PRIMARY KEY (id),
    KEY idx_sop_run_sop (id_sop, finished_at),
    KEY idx_sop_run_day (finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Wykonania SOP [353]';

CREATE TABLE IF NOT EXISTS n_sop_event (
    id      INT NOT NULL AUTO_INCREMENT,
    id_sop  INT NOT NULL,
    kind    ENUM('created','approved','archived') NOT NULL,
    version INT NOT NULL DEFAULT 0,
    at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    by_user INT NULL,
    PRIMARY KEY (id),
    KEY idx_sop_event_day (kind, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Historia SOP (zadanie « SOP dnia ») [353]';

-- Prawo « sop » (lista, asystent, zatwierdzenie) i pozycja w menu : Kierownik produkcji i Jakość ; kto ma prawo produkcji — też.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT id_position, 'sop' FROM n_page_access WHERE page_key = 'production';
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'sop' FROM n_position p WHERE p.code IN ('KIEROWNIK_PRODUKCJI', 'JAKOSC');
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'sop', NULL FROM n_position p WHERE p.code IN ('KIEROWNIK_PRODUKCJI', 'JAKOSC');

-- Pierwsze procedury : szkice z etapami (zrzuty ekranu dodaje Kierownik produkcji), przypięte do asystentów.
INSERT INTO n_sop (code, title, purpose, area, performer, status)
SELECT 'SOP-JAK-001', 'Próbki produkcji : wybór, wydruk A4, odłożenie',
    'Każda wybrana partia ma próbkę archiwalną z kartą A4 (QR), odłożoną i zeskanowaną — do DLC produktu.',
    'Wszystkie grupy · zamrażarka próbek', 'Kierownik produkcji / Jakość', 'draft'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_sop WHERE code = 'SOP-JAK-001');
INSERT INTO n_sop (code, title, purpose, area, performer, status)
SELECT 'SOP-ZAK-001', 'Ceny surowców z faktur zakupu (asystent cen)',
    'Każda cena z faktury sprawdzona względem bazy, ostatniej faktury i innych dostawców ; nowa cena zapisana albo reklamacja.',
    'Zaopatrzenie › Faktury', 'Kierownik produkcji', 'draft'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_sop WHERE code = 'SOP-ZAK-001');
INSERT INTO n_sop (code, title, purpose, area, performer, status)
SELECT 'SOP-ZAK-002', 'Faktury KSeF : pobranie, przypisanie pozycji, usługi',
    'Faktury zakupu z KSeF pobrane, pozycje przypisane do surowców, faktury usług przekazane do Finansów.',
    'Zakupy › Faktury KSeF', 'Kierownik produkcji / Administracja', 'draft'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_sop WHERE code = 'SOP-ZAK-002');

INSERT INTO n_sop_step (id_sop, sort, title, body, tag, confirm, value_min, value_max, value_unit)
SELECT s.id, x.sort, x.title, x.body, x.tag, x.confirm, x.vmin, x.vmax, x.unit FROM n_sop s
JOIN (SELECT 1 AS sort, 'Otwórz Partie i identyfikowalność › Próbki' AS title, 'Z zadania dnia « Próbki produkcji » albo z menu Produkcja.' AS body, NULL AS tag, 'check' AS confirm, NULL AS vmin, NULL AS vmax, NULL AS unit
      UNION ALL SELECT 2, 'Sprawdź partie zaproponowane do próbek', 'Propozycja wg kategorii produktu (partia, dzień, tydzień, miesiąc). Odznacz albo zaznacz partię, jeśli trzeba.', NULL, 'check', NULL, NULL, NULL
      UNION ALL SELECT 3, 'Utwórz próbki i wydrukuj karty A4', '« Utwórz próbki », potem « Drukuj : 1 A4 na próbkę ». Jedna karta na próbkę.', NULL, 'check', NULL, NULL, NULL
      UNION ALL SELECT 4, 'Pobierz próbkę z partii i zapakuj z kartą A4', 'Czyste rękawice, woreczek spożywczy, karta A4 z QR przy próbce.', 'haccp', 'photo', NULL, NULL, NULL
      UNION ALL SELECT 5, 'Odłóż do zamrażarki próbek', 'Sprawdź temperaturę zamrażarki próbek.', 'haccp', 'value', NULL, -18, '°C'
      UNION ALL SELECT 6, 'Zeskanuj QR karty i zakończ', 'Krok 3 asystenta : skan QR (albo « Odłożona »), potem « Zakończ — zadanie zaliczone ».', NULL, 'check', NULL, NULL, NULL) x
WHERE s.code = 'SOP-JAK-001' AND NOT EXISTS (SELECT 1 FROM n_sop_step t WHERE t.id_sop = s.id);

INSERT INTO n_sop_step (id_sop, sort, title, body, tag, confirm)
SELECT s.id, x.sort, x.title, x.body, x.tag, 'check' FROM n_sop s
JOIN (SELECT 1 AS sort, 'Otwórz asystenta « Ceny z faktur zakupu »' AS title, 'Czerwony przycisk na Dzisiaj albo Zaopatrzenie › Faktury : są faktury z pozycjami do sprawdzenia.' AS body, NULL AS tag
      UNION ALL SELECT 2, 'Porównaj cenę pozycji', 'Cena w bazie, ostatnia faktura, ta faktura, najlepszy inny dostawca (90 dni).', 'quality'
      UNION ALL SELECT 3, 'Sprawdź efekt na produkty', 'Zmiana kosztu na sztukę i karton, marża przed / po, efekt na miesiąc.', NULL
      UNION ALL SELECT 4, 'Zdecyduj', '« Zatwierdź » (nowa cena w bazie), « Tylko ta dostawa », « Reklamacja / zapytaj dostawcę » albo inna jednostka.', NULL
      UNION ALL SELECT 5, 'Podsumowanie', 'Wszystkie pozycje sprawdzone : przycisk zielony. Produkty poniżej celu marży → Asystent cen (czwartek).', NULL) x
WHERE s.code = 'SOP-ZAK-001' AND NOT EXISTS (SELECT 1 FROM n_sop_step t WHERE t.id_sop = s.id);

INSERT INTO n_sop_step (id_sop, sort, title, body, tag, confirm)
SELECT s.id, x.sort, x.title, x.body, x.tag, 'check' FROM n_sop s
JOIN (SELECT 1 AS sort, 'Pobierz faktury z KSeF' AS title, 'Pobieranie samo co godzinę ; « Pobierz teraz », gdy faktura jest pilna.' AS body, NULL AS tag
      UNION ALL SELECT 2, 'Sprawdź nowe faktury', 'Dostawca, numer, data, kwota. Faktura usługi (transport, serwis) → « Finanse » (koszty usług miesiąca).', NULL
      UNION ALL SELECT 3, 'Przypisz pozycje do surowców', 'Nazwa dostawcy → nasz surowiec, raz : następne faktury przypisują się same. Sprawdź jednostkę (kg / szt. / worek).', 'quality'
      UNION ALL SELECT 4, 'Porównaj z zamówieniem', 'Ilości i ceny wobec zamówienia ; różnica → reklamacja do dostawcy.', NULL
      UNION ALL SELECT 5, 'Przejdź do cen', 'Ceny pozycji : procedura SOP-ZAK-001 (asystent « Ceny z faktur zakupu »).', NULL) x
WHERE s.code = 'SOP-ZAK-002' AND NOT EXISTS (SELECT 1 FROM n_sop_step t WHERE t.id_sop = s.id);

INSERT IGNORE INTO n_sop_link (id_sop, path) SELECT id, '/workshop/traceability/samples' FROM n_sop WHERE code = 'SOP-JAK-001';
INSERT IGNORE INTO n_sop_link (id_sop, path) SELECT id, '/workshop/supply' FROM n_sop WHERE code = 'SOP-ZAK-001';
INSERT IGNORE INTO n_sop_link (id_sop, path) SELECT id, '/workshop/supply' FROM n_sop WHERE code = 'SOP-ZAK-002';
INSERT IGNORE INTO n_sop_link (id_sop, path) SELECT id, '/settings/ksef/purchase-invoices' FROM n_sop WHERE code = 'SOP-ZAK-002';
INSERT INTO n_sop_event (id_sop, kind, version) SELECT s.id, 'created', 0 FROM n_sop s
WHERE s.code IN ('SOP-JAK-001', 'SOP-ZAK-001', 'SOP-ZAK-002') AND NOT EXISTS (SELECT 1 FROM n_sop_event e WHERE e.id_sop = s.id);

-- Zadanie dnia Kierownika produkcji : SOP dnia (zatwierdzona wersja dnia — poprawiona albo nowa, ze zrzutami ekranu).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA'),
    'SOP dnia : popraw jedną procedurę albo utwórz nową (etapy, zrzuty ekranu / zdjęcia) i zatwierdź',
    'SOP du jour : corriger une procédure ou en créer une nouvelle (étapes, captures / photos) et l''approuver',
    'daily', NULL, 15, '/sop', 'sop_daily',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'sop_daily');
