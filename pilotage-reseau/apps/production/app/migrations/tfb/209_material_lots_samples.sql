-- [352] Partie surowców → kontrola wejść produkcji → próbki z kodem QR (makiety 68 i 68 B).
--  - n_material_lot : rejestr partii surowców — każda pozycja faktury KSeF przypisana do surowca (towar) i każde przyjęcie
--    dostawy z numerem partii ; numer i DLC z przyjęcia (zdjęcie / OCR) albo wpisane raz w rejestrze ; partia aktywna = na
--    liście tabletu. Bez numeru : nieaktywna (« czeka na numer ») ; po DLC : wyłączona sama.
--  - n_material_trace : surowiec nieśledzony (np. sól) — bez listy partii na tablecie i poza licznikiem « uzupełnione ».
--  - n_prod_output_lot.id_material_lot : partia wybrana z listy ; missing_reason : brak partii z powodem (+ zdjęcie) —
--    partia produkcji uznana za uzupełnioną, « Zakończ » odblokowany.
--  - n_sample_rule : częstotliwość próbek wg kategorii produktu (partia, dzień, tydzień, miesiąc, bez próbek) ; reszta :
--    parametr samples.default_frequency.
--  - n_prod_sample : próbka partii produkcji — kod QR, przechowywać do DLC produktu (karta : shelf_life dni), wydruk A4,
--    odłożenie potwierdzone skanem.
--  - zadanie dnia KIEROWNIK_PRODUKCJI « Próbki produkcji » (asystent, zalicza się samo : samples_done).
-- Rejouable : CREATE TABLE IF NOT EXISTS, information_schema, INSERT … WHERE NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_material_lot (
    id              INT NOT NULL AUTO_INCREMENT,
    id_raw_material INT NOT NULL COMMENT 'raw_material.id',
    lot_number      VARCHAR(64) NULL,
    best_before     DATE NULL,
    source          ENUM('ksef','receipt','manual') NOT NULL,
    id_inv_line     INT NULL COMMENT 'n_inv_line.id (faktura KSeF / PDF)',
    id_po_receipt   INT NULL COMMENT 'n_po_receipt.id (przyjęcie dostawy)',
    id_supplier     INT NULL,
    received_on     DATE NOT NULL,
    quantity        DECIMAL(14,3) NULL COMMENT 'w jednostce bazowej surowca',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    off_reason      ENUM('manual','expired','no_number') NULL,
    updated_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_material_lot_inv_line (id_inv_line),
    UNIQUE KEY uq_material_lot_receipt (id_po_receipt),
    KEY idx_material_lot_material (id_raw_material, active, best_before),
    KEY idx_material_lot_number (lot_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Partie surowców (KSeF i przyjęcia) [352]';

CREATE TABLE IF NOT EXISTS n_material_trace (
    id_raw_material INT NOT NULL,
    tracked         TINYINT(1) NOT NULL DEFAULT 1,
    updated_by      INT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Surowiec śledzony (partia wymagana) albo nie [352]';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_output_lot' AND COLUMN_NAME = 'id_material_lot');
SET @sql := IF(@c = 0, 'ALTER TABLE n_prod_output_lot
        ADD COLUMN id_material_lot INT NULL COMMENT ''n_material_lot.id (wybrana z listy)'' AFTER id_material,
        ADD COLUMN missing_reason VARCHAR(255) NULL COMMENT ''brak partii : powód (ze zdjęciem)'' AFTER ocr_text,
        ADD KEY idx_output_lot_material_lot (id_material_lot)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_sample_rule (
    id_category INT NOT NULL COMMENT 'category_product.id',
    frequency   ENUM('lot','day','week','month','none') NOT NULL,
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Próbki : częstotliwość wg kategorii produktu [352]';

CREATE TABLE IF NOT EXISTS n_prod_sample (
    id          INT NOT NULL AUTO_INCREMENT,
    id_output   BIGINT NOT NULL COMMENT 'n_prod_output.id (partia produkcji)',
    id_item     INT NOT NULL,
    sample_date DATE NOT NULL COMMENT 'dzień produkcji',
    keep_until  DATE NULL COMMENT 'DLC produktu (karta : shelf_life dni od produkcji)',
    code        CHAR(12) NOT NULL COMMENT 'kod w QR',
    reason      VARCHAR(16) NOT NULL COMMENT 'lot / day / week / month / manual',
    status      ENUM('planned','printed','stored') NOT NULL DEFAULT 'planned',
    location    VARCHAR(80) NULL,
    printed_at  DATETIME NULL,
    stored_at   DATETIME NULL,
    stored_by   INT NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_sample_output (id_output),
    UNIQUE KEY uq_prod_sample_code (code),
    KEY idx_prod_sample_item (id_item, sample_date),
    KEY idx_prod_sample_date (sample_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Próbki partii produkcji (QR) [352]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'samples.default_frequency', 'week', 'week', 'string', NULL,
    'Próbki produkcji : częstotliwość dla kategorii bez własnej reguły (lot, day, week, month, none)',
    'Échantillons de production : fréquence des catégories sans règle propre (lot, day, week, month, none)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'samples.default_frequency');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'samples.location', 'Zamrażarka próbek', 'Zamrażarka próbek', 'string', NULL,
    'Próbki produkcji : miejsce przechowywania (na arkuszu A4)',
    'Échantillons de production : lieu de conservation (sur la feuille A4)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'samples.location');

-- Zadanie dnia stanowiska KIEROWNIK_PRODUKCJI (profil Produkcja) : próbki dnia — wybór, wydruk A4, skan przy odłożeniu.
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA'),
    'Próbki produkcji : wybierz partie, wydrukuj arkusz A4 z kodami QR, zeskanuj przy odłożeniu (asystent)',
    'Échantillons de production : choisir les lots, imprimer la feuille A4 avec QR, scanner au rangement (assistant)',
    'daily', NULL, 10, '/workshop/traceability/samples', 'samples_done',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'samples_done');
