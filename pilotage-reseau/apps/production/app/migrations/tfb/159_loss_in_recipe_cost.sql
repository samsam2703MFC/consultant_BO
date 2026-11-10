-- [251] Odpad przy obróbce w koszcie receptur : receptury podają wagę PO obróbce (obrane jabłka), więc koszt surowca
--   = ilość × cena zakupu ÷ (1 − odpad z karty surowca) — App\Services\Material\MaterialLoss. Przeliczenie kosztu
--   wszystkich receptur ze spłaszczonym składem (jak « Przelicz wszystkie marże » : product_recipe.precalculated_material_cost
--   → final_product.pre_calculated_raw_price_per_carton) i zapis przed / po w n_margin_recalc, aby Marże kategorii
--   pokazały efekt odpadu na produkt (kolumna « Efekt na sztukę »). Koszty sprzed zmiany : n_recipe_cost_before159.

CREATE TABLE IF NOT EXISTS n_recipe_cost_before159 (
    id_recipe INT NOT NULL,
    cost_old  DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'koszt surowców receptury przed odpadem',
    cost_new  DECIMAL(12,4) NULL COMMENT 'koszt z odpadem (po migracji 159)',
    saved_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_recipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_recipe_cost_before159 (id_recipe, cost_old)
SELECT id, COALESCE(precalculated_material_cost, 0) FROM product_recipe;

-- Koszt z odpadem : tylko receptury ze spłaszczonym składem (pozostałe bez zmian).
UPDATE product_recipe pr
JOIN (SELECT fri.recipe_id,
             SUM(fri.quantity * mat.price_per_unit / (1 - LEAST(GREATEST(COALESCE(mat.waste_perc, 0), 0), 99.99) / 100)) AS cost
      FROM flattened_recipe_ingredient fri
      JOIN raw_material mat ON mat.id = fri.material_id
      GROUP BY fri.recipe_id) x ON x.recipe_id = pr.id
SET pr.precalculated_material_cost = x.cost;

UPDATE n_recipe_cost_before159 b
JOIN product_recipe pr ON pr.id = b.id_recipe
JOIN (SELECT DISTINCT recipe_id FROM flattened_recipe_ingredient) f ON f.recipe_id = b.id_recipe
SET b.cost_new = pr.precalculated_material_cost
WHERE b.cost_new IS NULL;

-- Karta produktu = koszt receptury (jak RecipeCostRecalculationService::saveCost).
UPDATE final_product fp
JOIN n_recipe_cost_before159 b ON b.id_recipe = fp.id_recipe AND b.cost_new IS NOT NULL
SET fp.pre_calculated_raw_price_per_carton = ROUND(b.cost_new, 2);

-- Efekt na Marżach kategorii (przed / po na produkt, jak przycisk « Przelicz wszystkie marże »).
REPLACE INTO n_margin_recalc (id_product, old_cost_carton, new_cost_carton, ppc, recalculated_at, recalculated_by)
SELECT fp.id, ROUND(b.cost_old, 4), ROUND(b.cost_new, 4), fp.ppc, NOW(), NULL
FROM final_product fp
JOIN n_recipe_cost_before159 b ON b.id_recipe = fp.id_recipe AND b.cost_new IS NOT NULL
WHERE fp.active = 1 AND ABS(b.cost_new - b.cost_old) >= 0.00005;

INSERT INTO n_margin_recalc_run (run_at, run_by, recipes, products, changed)
SELECT NOW(), NULL,
       (SELECT COUNT(*) FROM n_recipe_cost_before159 WHERE cost_new IS NOT NULL),
       (SELECT COUNT(*) FROM final_product fp JOIN n_recipe_cost_before159 b ON b.id_recipe = fp.id_recipe WHERE fp.active = 1),
       (SELECT COUNT(*) FROM final_product fp JOIN n_recipe_cost_before159 b ON b.id_recipe = fp.id_recipe AND b.cost_new IS NOT NULL
         WHERE fp.active = 1 AND ABS(b.cost_new - b.cost_old) >= 0.00005);
