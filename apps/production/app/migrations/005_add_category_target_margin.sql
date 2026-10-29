-- Migracja: Dodanie kolumny target_margin_percentage do tabeli category_product
-- Data: 2026-02-18
-- Cel: Przechowywanie docelowej marży dla każdej kategorii produktów

-- Sprawdź czy kolumna nie istnieje i dodaj ją
ALTER TABLE category_product
ADD COLUMN IF NOT EXISTS target_margin_percentage DECIMAL(5,2) DEFAULT 0.00
COMMENT 'Docelowa marża dla kategorii (w procentach, np. 25.50 = 25.5%)';

-- Możesz opcjonalnie dodać indeks jeśli będziesz często filtrować po marży
-- CREATE INDEX idx_target_margin ON category_product(target_margin_percentage);

-- Przykładowe dane testowe (opcjonalnie, odkomentuj jeśli potrzebujesz)
/*
UPDATE category_product SET target_margin_percentage = 25.00 WHERE name = 'Ciasta';
UPDATE category_product SET target_margin_percentage = 30.00 WHERE name = 'Torty';
UPDATE category_product SET target_margin_percentage = 20.00 WHERE name = 'Chleby';
UPDATE category_product SET target_margin_percentage = 35.00 WHERE name = 'Desery';
*/

-- Weryfikacja
-- SELECT id, name, target_margin_percentage FROM category_product ORDER BY name;

