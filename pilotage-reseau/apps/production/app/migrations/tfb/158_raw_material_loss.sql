-- [251] Karta surowca : waste_perc = ODPAD przy obróbce [%] (obieranie, gniazda, pestki, ości) — 0 = bez odpadu,
--   jabłko 35 (z 1 kg zostaje 650 g). Dotąd pole znaczyło « wykorzystanie » (część użyteczna, 100 = bez odpadu) i tak
--   wpisano większość wartości (132 × 100, 76 × 99, jabłko 65…). Przeliczenie : odpad = 100 − dawna wartość.
--   Wyjątki (do sprawdzenia, waste_review = 1) : dawna wartość ≤ 20 wygląda na wpisany już odpad (jogurt 1, skórka
--   z cytryny 1, pomidory puszka 5, cytryny 20…) — zostaje bez zmian ; wartość > 100 (błąd) → 0. Zapis karty surowca
--   potwierdza wartość (waste_review = 0). Dawne wartości : n_raw_material_waste_before158 (powrót możliwy).
--   Koszt receptur bez zmian (cena zakupu bez odpadu). Rejouable : przeliczenie zawsze z kopii dawnych wartości.

CREATE TABLE IF NOT EXISTS n_raw_material_waste_before158 (
    id_raw_material INT NOT NULL,
    waste_perc_old  INT NULL COMMENT 'dawna wartość (« wykorzystanie » albo już odpad)',
    review          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = przeniesiona bez przeliczenia albo błędna — do sprawdzenia',
    saved_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_raw_material_waste_before158 (id_raw_material, waste_perc_old, review)
SELECT id, waste_perc, IF(waste_perc IS NULL OR waste_perc <= 20 OR waste_perc > 100, 1, 0) FROM raw_material;

-- (MySQL nie zna ADD COLUMN IF NOT EXISTS : sprawdzenie w information_schema, jak w 155 i 157.)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material' AND COLUMN_NAME = 'waste_review');
SET @s := IF(@c = 0,
    'ALTER TABLE raw_material ADD COLUMN waste_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = odpad do sprawdzenia (przeniesiony automatycznie) ; zapis karty potwierdza'' AFTER waste_perc',
    'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE raw_material rm
JOIN n_raw_material_waste_before158 b ON b.id_raw_material = rm.id
SET rm.waste_perc = CASE
        WHEN b.waste_perc_old IS NULL OR b.waste_perc_old > 100 OR b.waste_perc_old < 0 THEN 0
        WHEN b.waste_perc_old <= 20 THEN b.waste_perc_old
        ELSE 100 - b.waste_perc_old
    END,
    rm.waste_review = b.review;

-- Typ po przeliczeniu (wartości 0–100 ; dawne > 999 nie zablokują zmiany typu).
ALTER TABLE raw_material MODIFY COLUMN waste_perc DECIMAL(5,2) NOT NULL DEFAULT 0
    COMMENT 'Odpad przy obróbce [%] : 0 = bez odpadu ; jabłko 35 = z 1 kg zostaje 650 g';
