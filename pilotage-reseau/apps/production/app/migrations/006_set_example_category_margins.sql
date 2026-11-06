-- Skrypt pomocniczy: Ustawienie przykładowych marż dla istniejących kategorii
-- Data: 2026-02-18
-- UWAGA: To tylko przykład! Dostosuj wartości do swoich potrzeb.

-- Najpierw sprawdź jakie kategorie masz w systemie:
SELECT id, name, target_margin_percentage
FROM category_product
ORDER BY name;

-- Przykładowe ustawienie marż (ODKOMENTUJ I DOSTOSUJ DO SWOICH KATEGORII):

/*
-- Pieczywo (marża 20%)
UPDATE category_product
SET target_margin_percentage = 20.00
WHERE name LIKE '%chleb%' OR name LIKE '%pieczywo%';

-- Ciasta i ciastka (marża 25%)
UPDATE category_product
SET target_margin_percentage = 25.00
WHERE name LIKE '%ciast%' OR name LIKE '%ciastk%' OR name LIKE '%babeczk%';

-- Torty (marża 30-35%)
UPDATE category_product
SET target_margin_percentage = 30.00
WHERE name LIKE '%tort%';

-- Desery premium (marża 35%)
UPDATE category_product
SET target_margin_percentage = 35.00
WHERE name LIKE '%deser%' OR name LIKE '%makaronik%' OR name LIKE '%pralin%';

-- Produkty sezonowe (marża 28%)
UPDATE category_product
SET target_margin_percentage = 28.00
WHERE name LIKE '%świąteczn%' OR name LIKE '%wielkanocn%';

-- Produkty podstawowe (marża 15%)
UPDATE category_product
SET target_margin_percentage = 15.00
WHERE name LIKE '%bułk%' OR name LIKE '%rogalik%';
*/

-- Alternatywnie: ustaw marże ręcznie po ID:
/*
UPDATE category_product SET target_margin_percentage = 25.00 WHERE id = 1; -- Ciasta
UPDATE category_product SET target_margin_percentage = 30.00 WHERE id = 2; -- Torty
UPDATE category_product SET target_margin_percentage = 20.00 WHERE id = 3; -- Chleby
UPDATE category_product SET target_margin_percentage = 35.00 WHERE id = 4; -- Desery
UPDATE category_product SET target_margin_percentage = 22.00 WHERE id = 5; -- Ciastka
*/

-- Weryfikacja po ustawieniu:
SELECT
    id,
    name,
    target_margin_percentage,
    CASE
        WHEN target_margin_percentage = 0 THEN '⚠️ Nie ustawiono'
        WHEN target_margin_percentage < 20 THEN '📉 Niska marża'
        WHEN target_margin_percentage BETWEEN 20 AND 30 THEN '✅ Standardowa marża'
        ELSE '📈 Wysoka marża'
    END as status
FROM category_product
ORDER BY target_margin_percentage DESC, name;

-- Statystyki:
SELECT
    COUNT(*) as total_categories,
    COUNT(CASE WHEN target_margin_percentage > 0 THEN 1 END) as with_margin,
    COUNT(CASE WHEN target_margin_percentage = 0 THEN 1 END) as without_margin,
    ROUND(AVG(target_margin_percentage), 2) as avg_margin,
    MIN(target_margin_percentage) as min_margin,
    MAX(target_margin_percentage) as max_margin
FROM category_product;

