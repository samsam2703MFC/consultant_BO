-- Lot 4 [230] : trzy nowe kreatory podpięte pod zadania stanowisk (« zadanie = kreator ») :
--   * Kontrola przyjęć dnia (Jakość, codziennie ; nikt na Jakości → zadanie u Kierownika produkcji) — dostawy dnia z
--     zamówień, pozycja po pozycji OK / niezgodność (ilość, partia, termin, temperatura, zdjęcia, decyzja) ; wpisywana
--     na tablecie pakowania (trzeci tryb przełącznika : Pakowanie / Paletyzacja / Przyjęcia), w ERP podsumowanie dnia ;
--   * Płatności dnia (Administracja, codziennie) — wpłaty proform (data, kwota), przypomnienia, różnice dostaw ;
--   * Ocena dostawców (Kierownik produkcji, co kwartał) — oceny A / B / C, surowce do zapytania, szkic zapytania ofertowego.
-- Tabele : kontrola zamówienia w dniu, zdjęcia niezgodności, temperatura przyjęcia, wpłaty proform, zakończenie kreatora
-- (wynik na karcie zadania), oceny dostawców. Zadania : przepięte na kreator po stanowisku, stronie i częstotliwości
-- (nie po id) ; rejouable.

CREATE TABLE IF NOT EXISTS n_receiving_check (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_po INT NOT NULL COMMENT 'zamówienie dostawcy (n_po)',
    day DATE NOT NULL COMMENT 'dzień kontroli (dzień dostawy)',
    lines_ok SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lines_nc SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    arrived TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = dostawa nie dotarła',
    nc_ids VARCHAR(255) NULL COMMENT 'JSON : niezgodności zapisane w kontroli',
    checked_by INT NULL COMMENT 'użytkownik ERP',
    id_employee INT NULL COMMENT 'operator tabletu pakowania (tryb Przyjęcia)',
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receiving_check (id_po, day),
    KEY idx_receiving_check_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Kontrola przyjęć dnia : zamówienie sprawdzone (pozycje OK / niezgodne)';

CREATE TABLE IF NOT EXISTS n_nc_photo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_nc INT NOT NULL,
    mime VARCHAR(40) NOT NULL,
    content MEDIUMBLOB NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_nc_photo_nc (id_nc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Niezgodności : zdjęcia (kreator przyjęć)';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_po_receipt' AND COLUMN_NAME = 'temperature_c') = 0,
    'ALTER TABLE n_po_receipt ADD COLUMN temperature_c DECIMAL(4,1) NULL COMMENT ''temperatura przy przyjęciu (°C, chłodnia / mroźnia)'' AFTER best_before',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_proforma_payment (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_proforma INT NOT NULL,
    paid_on DATE NOT NULL COMMENT 'data wpłaty z wyciągu',
    amount DECIMAL(12,2) NULL COMMENT 'kwota wpłaty z wyciągu',
    recorded_by INT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proforma_payment (id_proforma),
    KEY idx_proforma_payment_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Płatności dnia : wpłata proformy (data i kwota z wyciągu)';

CREATE TABLE IF NOT EXISTS n_wizard_done (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL COMMENT 'kod kreatora (TaskWizards::CATALOG)',
    day DATE NOT NULL COMMENT 'dzień zakończenia',
    summary VARCHAR(255) NULL COMMENT 'wynik na karcie zadania',
    done_by INT NULL,
    done_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wizard_done (code, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Kreatory zadań : zakończenie (płatności dnia, ocena dostawców)';

CREATE TABLE IF NOT EXISTS n_supplier_review (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quarter CHAR(7) NOT NULL COMMENT 'oceniany kwartał : 2026-Q3',
    id_supplier INT NOT NULL,
    score DECIMAL(5,1) NULL COMMENT 'ocena 0–100 (terminowość 40 %, niezgodności 30 %, ceny 30 %)',
    grade ENUM('A','B','C') NULL,
    decision ENUM('keep','watch','change') NOT NULL,
    metrics VARCHAR(1000) NULL COMMENT 'JSON : zakupy, dostawy, na czas, NC, zmiana cen',
    note VARCHAR(500) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_review (quarter, id_supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ocena dostawców co kwartał (kreator Kierownika produkcji)';

-- 1. Kontrola przyjęć dnia ← codzienne « kontrola przyjęć / rejestr NC » (Jakość) i « sprawdzić przyjęcia » (Kierownik).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code IN ('JAKOSC', 'KIEROWNIK_PRODUKCJI')
SET t.title_pl = 'Kontrola przyjęć dnia : dostawy z zamówień, pozycje OK / niezgodność, zdjęcia, decyzja (kreator przyjęć)',
    t.title_fr = 'Contrôle des réceptions du jour : livraisons des commandes, lignes OK / non-conformité, photos, décision (assistant réceptions)',
    t.link_path = '/workshop/receiving', t.auto_code = 'receiving_checked', t.extra_links = '["/purchasing/nc"]',
    t.due_time = COALESCE(t.due_time, '14:00:00'), t.minutes = COALESCE(t.minutes, 20)
WHERE t.active = 1 AND t.frequency = 'daily' AND t.link_path = '/purchasing/nc' AND t.auto_code IS NULL;

-- 2. Płatności dnia ← « kontrola wysłanych faktur i płatności » (Administracja, codziennie).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'ADMINISTRACJA'
SET t.title_pl = 'Płatności dnia : wpłaty proform, przypomnienia, różnice dostaw (kreator płatności)',
    t.title_fr = 'Paiements du jour : proformas payées, rappels, écarts de livraison (assistant paiements)',
    t.link_path = '/finances/payments-day', t.auto_code = 'payments_reviewed',
    t.extra_links = '["/communication/payment-reminders","/shipments/differences","/communication/messages"]',
    t.due_time = COALESCE(t.due_time, '11:00:00'), t.minutes = COALESCE(t.minutes, 25)
WHERE t.active = 1 AND t.frequency = 'daily' AND t.link_path = '/communication/messages' AND t.auto_code IS NULL;

-- 3. Ocena dostawców i zapytanie ofertowe (Kierownik, co kwartał) → kreator oceny.
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.link_path = '/purchasing/supplier-review', t.auto_code = 'suppliers_reviewed',
    t.extra_links = '["/purchasing/tenders","/purchasing/nc"]', t.minutes = COALESCE(t.minutes, 60)
WHERE t.active = 1 AND t.frequency = 'quarterly' AND t.link_path = '/purchasing/tenders' AND t.auto_code IS NULL;

-- Uprawnienia : nowe strony dla stanowisk, które widzą strony macierzyste (Zaopatrzenie / Produkcja, Przypomnienia, Zakupy).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT DISTINCT id_position, 'receiving' FROM n_page_access WHERE page_key IN ('supply', 'production');
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT DISTINCT id_position, 'payments_day' FROM n_page_access WHERE page_key IN ('reminders', 'invoices');
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT DISTINCT id_position, 'supplier_review' FROM n_page_access WHERE page_key = 'purchasing';
