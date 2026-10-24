-- ============================================================
-- DANE TESTOWE – Moduł "Marża obowiązująca produktu"
-- ============================================================
-- Scenariusz testowy:
--   Produkt: "Pierogi ruskie" (id=901, dział id=10 "Produkcja zimna")
--   Produkt: "Kopytka z masłem" (id=902, ten sam dział id=10)
--   3 miesiące kosztowe: styczeń, luty, marzec 2026
--   Media: energia elektryczna (id=1), gaz (id=2)
--   Alokacja mediów: dział 10 dostaje 60% energii i 45% gazu
--   Dział 20 "Produkcja ciepła" dostaje pozostałe 40% / 55%
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. KATEGORIA PRODUKTU
-- ────────────────────────────────────────────────────────────
INSERT INTO category_product (id, name, target_margin_percentage)
VALUES (50, 'Wyroby gotowe schłodzone', 22.00)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ────────────────────────────────────────────────────────────
-- 2. DZIAŁY PRODUKCJI
-- ────────────────────────────────────────────────────────────
INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost)
VALUES
  (10, 'Produkcja zimna', 3500.00, 0.00, 0.00, 0.00, 0.00),
  (20, 'Produkcja ciepła', 2800.00, 0.00, 0.00, 0.00, 0.00)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  depreciation = VALUES(depreciation);

-- ────────────────────────────────────────────────────────────
-- 3. RECEPTURY
-- ────────────────────────────────────────────────────────────
-- precalculated_material_cost = koszt surowców na 1 karton
INSERT INTO product_recipe (id, name, id_product, is_subrecipe, id_type, id_unit, yield_quantity, precalculated_material_cost)
VALUES
  (201, 'Receptura Pierogi ruskie', 901, 0, 1, 1, 1, 28.50),
  (202, 'Receptura Kopytka z masłem', 902, 0, 1, 1, 1, 19.80)
ON DUPLICATE KEY UPDATE
  precalculated_material_cost = VALUES(precalculated_material_cost);

-- ────────────────────────────────────────────────────────────
-- 4. PRODUKTY FINALNE
-- ────────────────────────────────────────────────────────────
-- standard_price w EUR (cena za karton)
-- ppc = sztuk w kartonie
INSERT INTO final_product (id, name, id_category, active, weight, ppc, ppb, bpc, cpl, lpp, cpp, id_section, id_recipe, label_type, stock_minimum, shelf_life, palette_cost, marge_perc, standard_price, pre_calculated_raw_price_per_carton, workload_points)
VALUES
  (901, 'Pierogi ruskie 500g x12', 50, 1, 500, 12, 4, 3, 10, 5, 50, 10, 201, 0, 24, 60, 0.00, 0, 8.50, 28.50, 4),
  (902, 'Kopytka z masłem 400g x12', 50, 1, 400, 12, 4, 3, 10, 5, 50, 10, 202, 0, 24, 60, 0.00, 0, 6.80, 19.80, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  id_section = VALUES(id_section),
  id_recipe = VALUES(id_recipe),
  standard_price = VALUES(standard_price),
  workload_points = VALUES(workload_points);

-- ────────────────────────────────────────────────────────────
-- 5. MIESIĄCE KOSZTOWE (styczeń – marzec 2026)
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_month (id, year, month, status)
VALUES
  (101, 2026, 1, 'LOCKED'),
  (102, 2026, 2, 'LOCKED'),
  (103, 2026, 3, 'OPEN')
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- ────────────────────────────────────────────────────────────
-- 6. TYPY MEDIÓW
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_media_type (id, code, name, unit, is_active)
VALUES
  (1, 'ENERGY', 'Energia elektryczna', 'PLN', 1),
  (2, 'GAS',    'Gaz ziemny',          'PLN', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ────────────────────────────────────────────────────────────
-- 7. KOSZTY MEDIÓW PER MIESIĄC (cost_media_month)
--    Energia elektryczna:  18 000 PLN/mies.
--    Gaz:                  12 000 PLN/mies.
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_media_month (cost_month_id, media_type_id, amount, note)
VALUES
  -- styczeń 2026
  (101, 1, 18000.00, 'energia styczeń'),
  (101, 2, 12000.00, 'gaz styczeń'),
  -- luty 2026
  (102, 1, 17500.00, 'energia luty'),
  (102, 2, 11800.00, 'gaz luty'),
  -- marzec 2026
  (103, 1, 18200.00, 'energia marzec'),
  (103, 2, 12200.00, 'gaz marzec')
ON DUPLICATE KEY UPDATE amount = VALUES(amount);

-- ────────────────────────────────────────────────────────────
-- 8. ALOKACJA MEDIÓW DO SEKCJI (cost_media_section_allocation)
--    Dział 10 (Produkcja zimna): energia 60%, gaz 45%
--    Dział 20 (Produkcja ciepła): energia 40%, gaz 55%
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_media_section_allocation (cost_month_id, media_type_id, section_id, allocation_percent)
VALUES
  -- styczeń
  (101, 1, 10, 60.00), (101, 1, 20, 40.00),
  (101, 2, 10, 45.00), (101, 2, 20, 55.00),
  -- luty
  (102, 1, 10, 60.00), (102, 1, 20, 40.00),
  (102, 2, 10, 45.00), (102, 2, 20, 55.00),
  -- marzec
  (103, 1, 10, 60.00), (103, 1, 20, 40.00),
  (103, 2, 10, 45.00), (103, 2, 20, 55.00)
ON DUPLICATE KEY UPDATE allocation_percent = VALUES(allocation_percent);

-- ────────────────────────────────────────────────────────────
-- 9. WYNAGRODZENIA PRACOWNIKÓW DZIAŁU 10 (cost_month_employee)
--    ~4 osoby: łącznie ok. 24 000 PLN/mies.
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included)
VALUES
  -- styczeń
  (101, 10, 'Anna',   'Kowalska',  7200.00, 1),
  (101, 10, 'Marek',  'Nowak',     6800.00, 1),
  (101, 10, 'Zofia',  'Wiśniewska',5500.00, 1),
  (101, 10, 'Piotr',  'Zając',     4500.00, 1),
  -- luty
  (102, 10, 'Anna',   'Kowalska',  7200.00, 1),
  (102, 10, 'Marek',  'Nowak',     6800.00, 1),
  (102, 10, 'Zofia',  'Wiśniewska',5500.00, 1),
  (102, 10, 'Piotr',  'Zając',     4500.00, 1),
  -- marzec
  (103, 10, 'Anna',   'Kowalska',  7400.00, 1),
  (103, 10, 'Marek',  'Nowak',     7000.00, 1),
  (103, 10, 'Zofia',  'Wiśniewska',5600.00, 1),
  (103, 10, 'Piotr',  'Zając',     4600.00, 1);

-- ────────────────────────────────────────────────────────────
-- 10. WOLUMEN PRODUKCJI SEKCJI (cost_month_section)
--     Rejestruje ile kartonów wyprodukowano w danym dziale
-- ────────────────────────────────────────────────────────────
INSERT INTO cost_month_section (month_id, section_id, cartons_qty, source)
VALUES
  (101, 10, 4200, 'MANUAL'),
  (102, 10, 3900, 'MANUAL'),
  (103, 10, 4500, 'MANUAL')
ON DUPLICATE KEY UPDATE cartons_qty = VALUES(cartons_qty);

-- ────────────────────────────────────────────────────────────
-- 11. KURS EUR (erp_company_other_settings)
-- ────────────────────────────────────────────────────────────
-- Sprawdź czy tabela istnieje i czy jest rekord
UPDATE erp_company_other_settings SET exchange_rate_euro = 4.2850 WHERE id = 1;
-- Jeśli tabela jest pusta:
-- INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.2850)
-- ON DUPLICATE KEY UPDATE exchange_rate_euro = 4.2850;

-- ────────────────────────────────────────────────────────────
-- 12. SPRZEDAŻ WDT (dokument_wdt + document_wdt_product)
--     Symuluje sprzedaż produktów 901 i 902 w 3 miesiącach
--     Cena za karton w EUR: Pierogi 8.50 EUR, Kopytka 6.80 EUR
-- ────────────────────────────────────────────────────────────

-- Najpierw sprawdź strukturę tabeli document_wdt – potrzebne kolumny:
-- id, established_date, currency, (inne wymagane)
-- Przykładowe dokumenty WDT (dostosuj do struktury swojej bazy):
/*
INSERT INTO document_wdt (id, established_date, currency, id_customer)
VALUES
  (5001, '2026-01-15', 'EUR', 1),
  (5002, '2026-01-28', 'EUR', 1),
  (5003, '2026-02-12', 'EUR', 1),
  (5004, '2026-02-25', 'EUR', 1),
  (5005, '2026-03-10', 'EUR', 1),
  (5006, '2026-03-20', 'EUR', 1)
ON DUPLICATE KEY UPDATE established_date = VALUES(established_date);

INSERT INTO document_wdt_product (id_document_wdt, id_product, quantity, price_carton)
VALUES
  -- styczeń: Pierogi 1200 kartonów, Kopytka 800 kartonów
  (5001, 901,  700, 8.50),
  (5001, 902,  500, 6.80),
  (5002, 901,  500, 8.55),
  (5002, 902,  300, 6.80),
  -- luty: Pierogi 1100 kartonów, Kopytka 750 kartonów
  (5003, 901,  650, 8.50),
  (5003, 902,  450, 6.75),
  (5004, 901,  450, 8.60),
  (5004, 902,  300, 6.80),
  -- marzec: Pierogi 1350 kartonów, Kopytka 900 kartonów
  (5005, 901,  800, 8.50),
  (5005, 902,  550, 6.80),
  (5006, 901,  550, 8.45),
  (5006, 902,  350, 6.85)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);
*/

-- ============================================================
-- WERYFIKACJA DANYCH – OCZEKIWANE WYNIKI KALKULACJI
-- ============================================================
-- Produkt: "Pierogi ruskie 500g x12" (id=901), dział 10, 3 miesiące
--
-- === FOODCOST ===
--   precalculated_material_cost = 28.50 PLN/karton
--
-- === KOSZTY MEDIÓW DZIAŁU 10 / KARTON ===
--   Styczeń: energia 18000*60% + gaz 12000*45% = 10800+5400 = 16200 PLN
--   Luty:    17500*60% + 11800*45% = 10500+5310 = 15810 PLN
--   Marzec:  18200*60% + 12200*45% = 10920+5490 = 16410 PLN
--   Suma mediów łącznie: 16200+15810+16410 = 48420 PLN
--   Wolumen łącznie: 4200+3900+4500 = 12600 kartonów
--   Śr. media/karton = 48420 / 12600 = 3.8429 PLN/karton
--
-- === WYNAGRODZENIA DZIAŁU 10 / KARTON ===
--   Styczeń: 7200+6800+5500+4500 = 24000 PLN
--   Luty:    24000 PLN
--   Marzec:  7400+7000+5600+4600 = 24600 PLN
--   Suma: 72600 PLN / 12600 kartonów = 5.7619 PLN/karton
--
-- === AMORTYZACJA DZIAŁU 10 / KARTON ===
--   3500 PLN/mies. × 3 mies. = 10500 PLN / 12600 kartonów = 0.8333 PLN/karton
--
-- === ŁĄCZNY KOSZT WYTWORZENIA ===
--   28.50 + 3.84 + 5.76 + 0.83 = 38.93 PLN/karton
--
-- === CENA SPRZEDAŻY ===
--   Śr. cena EUR = (8.50×1200 + 8.55×500 + 8.50×650... itp.) / łączne kartony
--   ≈ 8.51 EUR × 4.2850 PLN/EUR = 36.47 PLN/karton
--   (przy danych z WDT - sekcja 12 powyżej)
--
-- === MARŻA ===
--   36.47 - 38.93 = -2.46 PLN / 36.47 = -6.7%
--   → ujemna marża sygnalizuje, że cena sprzedaży nie pokrywa kosztów
--   → aby wyjść na 22% marży (cel kategorii), cena powinna wynosić:
--     38.93 / (1-0.22) = 49.91 PLN = 11.65 EUR
--
-- === SYMULACJA – wzrost wolumenu o 30% ===
--   Nowy wolumen: 12600 × 1.30 = 16380 kartonów
--   Media/karton:  48420 / 16380 = 2.957 PLN (↓ o 0.886)
--   Labor/karton:  72600 / 16380 = 4.432 PLN (↓ o 1.330)
--   Amortyzacja:  10500 / 16380 = 0.641 PLN (↓ o 0.192)
--   Nowy łączny koszt: 28.50 + 2.96 + 4.43 + 0.64 = 36.53 PLN
--   Marża: (36.47 - 36.53) / 36.47 = -0.16% (prawie 0)
--
-- === SYMULACJA – wzrost wolumenu o 30% + wzrost ceny mediów o 20% ===
--   Media/karton:  48420*1.20 / 16380 = 3.548 PLN
--   Labor/karton:  4.432 PLN (bez zmian)
--   Amortyzacja:   0.641 PLN (bez zmian)
--   Nowy łączny:  28.50 + 3.55 + 4.43 + 0.64 = 37.12 PLN
--   Marża: (36.47 - 37.12) / 36.47 = -1.78%
--
-- ============================================================
-- PROCENTOWY UDZIAŁ DZIAŁÓW W MEDIACH (avg z 3 miesięcy)
-- ============================================================
-- Łączne media wszystkich działów (suma):
--   Dział 10: 48420 PLN
--   Dział 20: energia 40% = (18000+17500+18200)*40% = 21480
--             gaz 55% = (12000+11800+12200)*55% = 19800
--             Dział 20 łącznie: 21480+19800 = 41280 PLN
--   Grand total mediów: 48420+41280 = 89700 PLN
--   Udział działu 10: 48420/89700 = 53.98%
--   Udział działu 20: 41280/89700 = 46.02%
-- ============================================================

