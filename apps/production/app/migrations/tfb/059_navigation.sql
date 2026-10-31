-- Nawigacja (lot 4 audytu) :
--  1. Zakupy : « Ceny surowców i dostawcy » to osobna strona katalogu uprawnień (sekcja Zakupy) — stanowiska, które
--     widziały Surowce, widzą ją dalej (bez utraty dostępu).
--  2. Strona startowa stanowiska (logo, po zalogowaniu) : Dzisiaj albo Rentowność ; brak wiersza = Dzisiaj,
--     administrator zawsze na Rentowności.

INSERT IGNORE INTO tfb_page_access (id_position, page_key, updated_by)
SELECT id_position, 'supplier_prices', updated_by FROM tfb_page_access WHERE page_key = 'materials';

CREATE TABLE IF NOT EXISTS tfb_position_home (
    id_position INT NOT NULL COMMENT '0 = bez stanowiska',
    home_page   VARCHAR(40) NOT NULL COMMENT '/today albo /dashboard',
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
