-- Faktury zakupowe : surowce i opakowania ↔ usługi (koszty firmy, Finanse) ↔ odrzucone (27/10/2026, makiety 1 à 4). Rejouable.
-- 1. n_inv.kind : goods (Zaopatrzenie : surowce i opakowania) | service (Finanse : koszt firmy, pogrupowany po miesiącach) ;
--    status + 'rejected' (nie dotyczy firmy : ani zaopatrzenie, ani koszty) ; cost_category (kategoria kalkulatora kosztów :
--    fixed:RENT, media:POWER, log:FUEL…), service_month (miesiąc kosztu, RRRR-MM), cost_amount (kwota netto PLN do kosztów,
--    domyślnie suma netto faktury), id_rule (reguła dostawcy, która sklasyfikowała fakturę przy imporcie).
-- 2. n_inv_supplier_rule : NIP sprzedawcy → zawsze usługa (z kategorią) albo zawsze odrzucona — stosowana przy imporcie.
-- 3. n_service_month : miesiąc kosztów usług zamknięty (kto, kiedy, suma) → sumy w kalkulatorze kosztów.
-- 4. n_service_month_push : wiersze kalkulatora zapisane przy zamknięciu (i zastąpione wiersze ręczne) — cofane przy otwarciu.
-- 5. n_service_month_mark : « nie w tym miesiącu » — koszt oczekiwany (kategoria + dostawca z poprzedniego miesiąca) bez faktury.
-- 6. Uprawnienia prod.finance.read / prod.finance.write : prod_admin, direction (nie kierownik produkcji).

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'kind');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_inv ADD COLUMN kind ENUM(''goods'',''service'') NOT NULL DEFAULT ''goods'' COMMENT ''goods : surowce i opakowania (Zaopatrzenie) ; service : koszt firmy (Finanse)'' AFTER source',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE n_inv MODIFY COLUMN status ENUM('open','accepted','disputed','rejected') NOT NULL DEFAULT 'open'
    COMMENT 'open : do walidacji ; accepted : zatwierdzona ; disputed : reklamacja ; rejected : nie dotyczy firmy';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'cost_category');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_inv ADD COLUMN cost_category VARCHAR(40) NULL COMMENT ''Usługa : kategoria kalkulatora kosztów (fixed:RENT, media:POWER, log:FUEL…)'' AFTER kind',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'service_month');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_inv ADD COLUMN service_month CHAR(7) NULL COMMENT ''Usługa : miesiąc kosztu (RRRR-MM)'' AFTER cost_category',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'cost_amount');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_inv ADD COLUMN cost_amount DECIMAL(14,2) NULL COMMENT ''Usługa : kwota netto PLN do kosztów (null = suma netto faktury w PLN)'' AFTER service_month',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'id_rule');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_inv ADD COLUMN id_rule INT NULL COMMENT ''Reguła dostawcy (n_inv_supplier_rule), która sklasyfikowała fakturę przy imporcie'' AFTER cost_amount',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND INDEX_NAME = 'idx_n_inv_kind');
SET @sql := IF(@c = 0, 'ALTER TABLE n_inv ADD KEY idx_n_inv_kind (kind, status, service_month)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND INDEX_NAME = 'idx_n_inv_month');
SET @sql := IF(@c = 0, 'ALTER TABLE n_inv ADD KEY idx_n_inv_month (service_month, kind)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_inv_supplier_rule (
    id            INT NOT NULL AUTO_INCREMENT,
    seller_nip    VARCHAR(20) NOT NULL COMMENT 'NIP sprzedawcy (KSeF)',
    seller_name   VARCHAR(255) NULL,
    action        ENUM('service','reject') NOT NULL COMMENT 'service : zawsze koszt firmy (Finanse) ; reject : zawsze odrzucona',
    cost_category VARCHAR(40) NULL COMMENT 'Kategoria kosztów (action = service)',
    note          VARCHAR(255) NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_inv_supplier_rule_nip (seller_nip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_service_month (
    ym         CHAR(7) NOT NULL COMMENT 'RRRR-MM',
    total      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Suma kosztów usług zapisana w kalkulatorze',
    closed_by  INT NULL,
    closed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_service_month_push (
    id            INT NOT NULL AUTO_INCREMENT,
    ym            CHAR(7) NOT NULL,
    cost_category VARCHAR(40) NOT NULL,
    target        ENUM('fixed','media','logistics') NOT NULL COMMENT 'cost_month_fixed_cost | cost_media_month | cost_month_logistics',
    row_id        BIGINT NULL COMMENT 'Wiersz kalkulatora zapisany przy zamknięciu',
    amount        DECIMAL(14,2) NOT NULL,
    replaced      TEXT NULL COMMENT 'JSON : wiersze ręczne zastąpione (przywracane przy otwarciu miesiąca)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_n_service_month_push_ym (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_service_month_mark (
    ym            CHAR(7) NOT NULL,
    cost_category VARCHAR(40) NOT NULL,
    seller_key    VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'nip:… albo name:… (dostawca oczekiwany)',
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ym, cost_category, seller_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_prod_role_permission (role_code, permission) VALUES
('prod_admin', 'prod.finance.read'), ('prod_admin', 'prod.finance.write'),
('direction', 'prod.finance.read'), ('direction', 'prod.finance.write');

-- 7. Parametry : próg « drobnych » podwyżek (akceptacja jednym kliknięciem) i dzień, od którego poprzedni miesiąc usług
--    powinien być zamknięty (zadanie w Dzisiaj dla Administracji).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'finance.small_price_up_pct', '5', '5', 'decimal', '%',
    'Finanse : podwyżka ceny surowca poniżej tego progu — w grupie « drobne podwyżki » (akceptacja jednym kliknięciem)',
    'Finances : hausse de prix d''une matière sous ce seuil — groupe « petites hausses » (acceptation en un clic)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'finance.small_price_up_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'finance.service_due_day', '10', '10', 'int', 'dzień',
    'Finanse : od tego dnia miesiąca poprzedni miesiąc kosztów usług powinien być zamknięty (zadanie w Dzisiaj)',
    'Finances : à partir de ce jour du mois, le mois précédent des coûts de services doit être clôturé (tâche dans Aujourd''hui)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'finance.service_due_day');
