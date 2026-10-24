<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Grupa, która przygotowuje półprodukt / kompozycję (tfb_recipe_producer) — czasem jedna grupa robi recepturę dla innej. */
final class RecipeProducerRepository extends WorkshopRepository
{
    protected string $table = 'tfb_recipe_producer';

    /** @return array<int, array{id:int, name_pl:string, name_fr:string, color:string}> receptura => grupa */
    public function forRecipes(array $recipeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($this->all("SELECT p.id_recipe, g.id, g.name_pl, g.name_fr, g.color FROM tfb_recipe_producer p
                             JOIN tfb_prod_group g ON g.id = p.id_group WHERE p.id_recipe IN ({$in})") as $r) {
            $out[(int) $r['id_recipe']] = ['id' => (int) $r['id'], 'name_pl' => $r['name_pl'], 'name_fr' => $r['name_fr'], 'color' => $r['color']];
        }
        return $out;
    }

    public function set(int $recipeId, ?int $groupId, ?int $userId): void
    {
        if ($groupId === null) {
            $this->db->prepare('DELETE FROM tfb_recipe_producer WHERE id_recipe = ?')->execute([$recipeId]);
            return;
        }
        $this->db->prepare('INSERT INTO tfb_recipe_producer (id_recipe, id_group, updated_by) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_group = VALUES(id_group), updated_by = VALUES(updated_by)')->execute([$recipeId, $groupId, $userId]);
    }

    public function groups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color FROM tfb_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    public function groupExists(int $groupId): bool
    {
        return (bool) $this->all('SELECT 1 FROM tfb_prod_group WHERE id = ? AND active = 1', [$groupId]);
    }

    /** Półprodukt / kompozycja (nie produkt gotowy). */
    public function isComponent(int $recipeId): bool
    {
        return (bool) $this->all('SELECT 1 FROM product_recipe WHERE id = ? AND id_type IN (1, 2)', [$recipeId]);
    }

    /**
     * Receptury przygotowywane przez grupę, z grupami produktów, które ich używają (do listy na tablecie).
     * @return list<array{id:int, name:string, unit:string, yield:float, for_groups:string}>
     */
    public function recipesOfGroup(int $groupId): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'unit' => (string) $r['unit'], 'yield' => (float) $r['yield_quantity'],
            'for_groups' => (string) ($r['for_groups'] ?? '')],
            $this->all("SELECT pr.id, pr.name, u.name AS unit, pr.yield_quantity,
                               (SELECT GROUP_CONCAT(DISTINCT g.name_pl ORDER BY g.name_pl SEPARATOR ', ')
                                FROM flattened_recipe_recipe f
                                JOIN final_product fp ON fp.id_recipe = f.recipe_id AND fp.active = 1
                                JOIN tfb_prod_item i ON i.id_final_product = fp.id
                                JOIN tfb_prod_product_settings s ON s.id_item = i.id
                                JOIN tfb_prod_group g ON g.id = s.id_primary_group
                                WHERE f.subrecipe_id = pr.id AND s.id_primary_group <> p.id_group) AS for_groups
                        FROM tfb_recipe_producer p
                        JOIN product_recipe pr ON pr.id = p.id_recipe
                        LEFT JOIN unit u ON u.id = pr.id_unit
                        WHERE p.id_group = ? ORDER BY pr.name", [$groupId]));
    }
}
