-- Jedno źródło kosztu surowców produktu : koszt receptury (product_recipe.precalculated_material_cost) kopiowany
-- do karty produktu (final_product.pre_calculated_raw_price_per_carton). Pole karty nie było aktualizowane przez
-- żaden kod (94 produkty z 0, średnio −28 % względem receptury) ; od teraz przeliczenie receptury aktualizuje
-- oba pola. Produkt bez receptury zachowuje koszt wpisany ręcznie.

UPDATE final_product fp
JOIN product_recipe pr ON pr.id = fp.id_recipe
SET fp.pre_calculated_raw_price_per_carton = pr.precalculated_material_cost
WHERE fp.pre_calculated_raw_price_per_carton <> pr.precalculated_material_cost;
