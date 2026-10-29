<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Receptura produktu = 1 karton [201] : produkty, których receptura wygląda na 1 sztukę (masa składników ≈ waga sztuki
 * zamiast kartonu, albo produkt z kreatora z masą poniżej połowy kartonu) i decyzje operatora (n_recipe_unit_check).
 */
final class RecipeUnitCheckRepository extends WorkshopRepository
{
    protected string $table = 'n_recipe_unit_check';

    /**
     * Kandydaci do sprawdzenia (bez decyzji). Masa = surowce w g i ml spłaszczonej receptury ; kreator = produkt utworzony
     * w czasie wizyty na /products/new-with-recipe (rejestr aktywności).
     * @return list<array>
     */
    public function candidates(?int $productId = null): array
    {
        return $this->all("SELECT fp.id, fp.name, fp.ppc, fp.weight, fp.id_recipe, fp.create_timestamp,
                COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS cost, m.mass,
                (SELECT COUNT(*) FROM final_product f2 WHERE f2.id_recipe = fp.id_recipe) AS shared,
                (SELECT COUNT(*) FROM product_recipe_connection c WHERE c.child_recipe_id = fp.id_recipe) AS used_in,
                EXISTS (SELECT 1 FROM n_activity_view v WHERE v.path = '/products/new-with-recipe'
                    AND fp.create_timestamp BETWEEN v.started_at - INTERVAL 5 SECOND AND v.last_seen_at + INTERVAL 120 SECOND) AS from_wizard
            FROM final_product fp
            JOIN (SELECT fri.recipe_id, SUM(fri.quantity) AS mass FROM flattened_recipe_ingredient fri
                    JOIN raw_material rm ON rm.id = fri.material_id WHERE rm.id_unit IN (3, 4) GROUP BY fri.recipe_id) m ON m.recipe_id = fp.id_recipe
            LEFT JOIN n_recipe_unit_check k ON k.id_product = fp.id
            WHERE fp.active = 1 AND fp.ppc > 1 AND fp.weight > 0 AND k.id_product IS NULL" . ($productId !== null ? ' AND fp.id = ?' : '') . "
            HAVING (from_wizard = 1 AND m.mass < 0.5 * fp.weight * fp.ppc)
                OR (m.mass < 0.35 * fp.weight * fp.ppc AND m.mass BETWEEN 0.4 * fp.weight AND 2.5 * fp.weight)
            ORDER BY fp.create_timestamp DESC, fp.id DESC", $productId !== null ? [$productId] : []);
    }

    /** @return list<array{child_recipe_id: ?int, child_ingredient_id: ?int, quantity: int}> składniki receptury */
    public function connections(int $recipeId): array
    {
        return $this->all('SELECT child_recipe_id, child_ingredient_id, quantity FROM product_recipe_connection WHERE parent_recipe_id = ?', [$recipeId]);
    }

    /** @return list<array{id_preparation: int, duration_second: int}> czynności receptury */
    public function preparations(int $recipeId): array
    {
        return $this->all('SELECT id_preparation_type AS id_preparation, duration_second FROM product_recipe_preparation_connection WHERE id_recipe = ?', [$recipeId]);
    }

    public function cost(int $productId): float
    {
        return (float) ($this->all('SELECT COALESCE(pre_calculated_raw_price_per_carton, 0) AS c FROM final_product WHERE id = ?', [$productId])[0]['c'] ?? 0);
    }

    public function decide(int $productId, int $recipeId, string $decision, ?int $factor, float $costBefore, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_recipe_unit_check (id_product, id_recipe, decision, factor, cost_before, decided_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$productId, $recipeId, $decision, $factor, round($costBefore, 2), $userId]);
    }
}
