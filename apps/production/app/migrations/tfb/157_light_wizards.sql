-- [247] Lot « lżej » (makiety 18–23) : każde ważne zadanie stanowiska = kreator w jednej ramie.
--   Plan na jutro (Kierownik produkcji, codziennie), Zebranie produkcyjne (protokół w 3 krokach — formularz bez zmian
--   w bazie), Niezgodności miesiąca (Jakość, co miesiąc ; audyt dostawców co kwartał w kroku 3), Koszty surowców
--   i oszczędności (co miesiąc), Wydajność i straty (co kwartał). Zadanie zalicza się samo (n_wizard_done).

-- Niezgodności : kategoria przyczyny (krok 2 kreatora : transport, dostawca, magazyn, produkcja, inne).
-- (MySQL nie zna ADD COLUMN IF NOT EXISTS : sprawdzenie w information_schema, jak w 155.)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_nc' AND COLUMN_NAME = 'cause_cat');
SET @s := IF(@c = 0, 'ALTER TABLE n_nc ADD COLUMN cause_cat VARCHAR(16) NULL AFTER root_cause', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Audyt dostawcy (co kwartał, krok 3 kreatora niezgodności) : planowany / zrobiony, wynik, notatka.
CREATE TABLE IF NOT EXISTS n_supplier_audit (
    id          INT NOT NULL AUTO_INCREMENT,
    quarter     CHAR(7) NOT NULL COMMENT 'np. 2026-Q4',
    id_supplier INT NOT NULL,
    reason      VARCHAR(120) NULL COMMENT 'dlaczego : liczba NC, ocena C…',
    planned_on  DATE NULL,
    done_on     DATE NULL,
    result      ENUM('ok','remarks','failed') NULL,
    note        VARCHAR(500) NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_supplier_audit (quarter, id_supplier),
    KEY ix_n_supplier_audit_supplier (id_supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Działania z przeglądów (wydajność i straty, zebranie) : kto, do kiedy, cel — wracają na zebraniu produkcyjnym.
CREATE TABLE IF NOT EXISTS n_wizard_action (
    id         INT NOT NULL AUTO_INCREMENT,
    code       VARCHAR(40) NOT NULL COMMENT 'kreator : yield_reviewed…',
    period     VARCHAR(10) NOT NULL COMMENT 'okres przeglądu : 2026-Q3, 2026-10…',
    title      VARCHAR(200) NOT NULL,
    goal       VARCHAR(120) NULL,
    id_user    INT NULL COMMENT 'osoba odpowiedzialna',
    id_group   INT NULL COMMENT 'grupa produkcji (baner na tablecie)',
    due_date   DATE NULL,
    status     ENUM('open','done') NOT NULL DEFAULT 'open',
    done_at    DATETIME NULL,
    done_by    INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_n_wizard_action_open (status, due_date),
    KEY ix_n_wizard_action_code (code, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Koszty surowców : decyzja miesiąca na surowiec (zmień dostawcę / zapytaj / zostaw) i oszczędność miesięczna.
CREATE TABLE IF NOT EXISTS n_cost_review (
    id               INT NOT NULL AUTO_INCREMENT,
    month            CHAR(7) NOT NULL COMMENT 'oceniany miesiąc YYYY-MM',
    id_raw_material  INT NOT NULL,
    decision         ENUM('change','ask','keep') NOT NULL,
    id_supplier_from INT NULL,
    id_supplier_to   INT NULL,
    price_from       DECIMAL(14,6) NULL COMMENT 'zł za jednostkę bazową',
    price_to         DECIMAL(14,6) NULL,
    saving_month     DECIMAL(12,2) NULL COMMENT 'zł / miesiąc przy zmianie dostawcy',
    id_offer         INT NULL,
    id_tender        INT NULL,
    decided_by       INT NULL,
    decided_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_cost_review (month, id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Zadania stanowisk → kreatory (tytuł krótki przed « : », opis po nim).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Plan produkcji na jutro : obciążenie grup, ludzie i surowce, wysyłka na tablety (kreator)',
    t.title_fr = 'Plan de production de demain : charge des groupes, personnes et matières, envoi aux tablettes (assistant)',
    t.link_path = '/production/tomorrow', t.auto_code = 'tomorrow_sent', t.extra_links = '["/workshop/team-sheet","/workshop/gantt"]',
    t.due_time = COALESCE(t.due_time, '14:00:00'), t.minutes = COALESCE(t.minutes, 15)
WHERE t.active = 1 AND t.frequency = 'daily' AND t.link_path = '/workshop/team-sheet' AND t.auto_code IS NULL;

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'JAKOSC'
SET t.title_pl = 'Niezgodności miesiąca : przyczyna i działanie dla każdej NC, dostawcy, audyt co kwartał (kreator)',
    t.title_fr = 'Non-conformités du mois : cause et action pour chaque NC, fournisseurs, audit chaque trimestre (assistant)',
    t.link_path = '/purchasing/nc-review', t.auto_code = 'nc_reviewed', t.extra_links = '["/purchasing/nc","/purchasing/supplier-review"]',
    t.minutes = COALESCE(t.minutes, 30)
WHERE t.active = 1 AND t.frequency = 'monthly' AND t.link_path = '/purchasing/nc' AND t.auto_code IS NULL;

-- Audyt dostawcy (Jakość, co kwartał) : w kroku 3 kreatora niezgodności — osobne zadanie znika.
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'JAKOSC'
SET t.active = 0
WHERE t.active = 1 AND t.frequency = 'quarterly' AND t.title_pl LIKE 'Audyt dostawc%' AND t.auto_code IS NULL;

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Koszty surowców i oszczędności : co zdrożało, tańsze oferty, decyzje (kreator)',
    t.title_fr = 'Coûts des matières et économies : hausses, offres moins chères, décisions (assistant)',
    t.link_path = '/purchasing/cost-review', t.auto_code = 'material_costs_reviewed', t.extra_links = '["/purchasing","/purchasing/tenders"]',
    t.minutes = COALESCE(t.minutes, 20)
WHERE t.active = 1 AND t.frequency = 'monthly' AND t.link_path = '/purchasing' AND t.auto_code IS NULL;

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Wydajność i straty kwartału : straty, sztuki na godzinę, TRS, działania (kreator)',
    t.title_fr = 'Rendement et pertes du trimestre : pertes, pièces par heure, TRS, actions (assistant)',
    t.link_path = '/production/yield-review', t.auto_code = 'yield_reviewed', t.extra_links = '["/production/follow-up"]',
    t.minutes = COALESCE(t.minutes, 45)
WHERE t.active = 1 AND t.frequency = 'quarterly' AND (t.link_path IS NULL OR t.link_path = '') AND t.title_pl LIKE 'Przegl%wydajno%' AND t.auto_code IS NULL;

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.minutes = COALESCE(t.minutes, 30)
WHERE t.active = 1 AND t.form_code = 'production_meeting';
