-- Uprawnienia stanowisk : które strony ERP (pozycje railu) widzi każde stanowisko. Administrator (rola prod_admin)
-- widzi wszystko ; strony « tylko administrator » (moduł kierownika produkcji, uprawnienia) nie są przyznawane.
-- id_position = 0 : użytkownicy bez stanowiska. Strony spoza katalogu (Dzisiaj, Moje zadania…) są dla wszystkich.
--
-- Start = stan dotychczasowy : każde istniejące stanowisko widzi wszystkie strony (poza stronami administratora) ;
-- bez stanowiska — tylko strony wspólne. Klucze = App\Core\Web\PageAccess::PAGES.

CREATE TABLE IF NOT EXISTS tfb_page_access (
    id_position  INT NOT NULL COMMENT '0 = bez stanowiska',
    page_key     VARCHAR(40) NOT NULL,
    updated_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_position, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_page_access (id_position, page_key)
SELECT p.id, k.page_key
FROM tfb_position p
CROSS JOIN (
    SELECT 'dashboard' AS page_key UNION ALL SELECT 'sales' UNION ALL SELECT 'margins'
    UNION ALL SELECT 'production' UNION ALL SELECT 'tablet' UNION ALL SELECT 'supply' UNION ALL SELECT 'employees' UNION ALL SELECT 'budget'
    UNION ALL SELECT 'product_cards' UNION ALL SELECT 'products' UNION ALL SELECT 'materials' UNION ALL SELECT 'bdo' UNION ALL SELECT 'ppwr'
    UNION ALL SELECT 'orders' UNION ALL SELECT 'clients' UNION ALL SELECT 'franchise' UNION ALL SELECT 'invoices' UNION ALL SELECT 'returns'
    UNION ALL SELECT 'communication' UNION ALL SELECT 'archive'
    UNION ALL SELECT 'logistics' UNION ALL SELECT 'shipping'
    UNION ALL SELECT 'price_lists' UNION ALL SELECT 'purchasing' UNION ALL SELECT 'ksef_purchases'
    UNION ALL SELECT 'positions' UNION ALL SELECT 'settings' UNION ALL SELECT 'devices'
) k
WHERE NOT EXISTS (SELECT 1 FROM tfb_page_access);

-- Moduł kierownika produkcji tylko dla administratora : rola « Kierownik produkcji » (prod_manager) traci dostęp
-- do API modułu (plan, raport, premia, rozmowa, audyt). « Moje wskaźniki » na stronie Dzisiaj zostają (osoba na stanowisku).
DELETE FROM tfb_prod_role_permission WHERE role_code = 'prod_manager' AND permission IN ('prod.manager.read', 'prod.manager.report', 'prod.manager.write');
