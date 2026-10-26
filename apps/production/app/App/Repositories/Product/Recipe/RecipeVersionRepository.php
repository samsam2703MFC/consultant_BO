<?php
declare(strict_types=1);

namespace App\Repositories\Product\Recipe;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Versions datées des recettes (n_recipe_version) et lecture de l'état actuel d'une recette pour en faire un
 * instantané : composition directe (product_recipe_connection, product_recipe_preparation_connection), liste
 * aplatie des matières (flattened_recipe_ingredient) et coût matières au prix actuel.
 */
final class RecipeVersionRepository extends WorkshopRepository
{
    protected string $table = 'n_recipe_version';
    protected array $writable = ['id_recipe', 'version_no', 'valid_from', 'source', 'note', 'content_hash', 'composition', 'ingredients', 'material_cost', 'created_by'];

    /** État actuel des recettes : en-tête, matières et półprodukty directs, préparations, matières aplaties, coût. */
    public function current(array $recipeIds): array
    {
        $ids = self::ids($recipeIds);
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT r.id, r.name, r.id_type, r.yield_quantity, u.name AS unit
                FROM product_recipe r LEFT JOIN unit u ON u.id = r.id_unit WHERE r.id IN ({$in})", $ids) as $r) {
            $out[(int) $r['id']] = ['recipe' => $r, 'materials' => [], 'components' => [], 'preparations' => [], 'ingredients' => [], 'cost' => 0.0];
        }
        foreach ($this->all("SELECT c.parent_recipe_id, c.child_ingredient_id, c.child_recipe_id, c.quantity,
                    m.name AS material, mu.name AS material_unit, sr.name AS component, sr.id_type AS component_type
                FROM product_recipe_connection c
                LEFT JOIN raw_material m ON m.id = c.child_ingredient_id
                LEFT JOIN unit mu ON mu.id = m.id_unit
                LEFT JOIN product_recipe sr ON sr.id = c.child_recipe_id
                WHERE c.parent_recipe_id IN ({$in})
                ORDER BY c.parent_recipe_id, c.child_ingredient_id, c.child_recipe_id", $ids) as $c) {
            $id = (int) $c['parent_recipe_id'];
            if (!isset($out[$id])) continue;
            if ($c['child_ingredient_id'] !== null) {
                $out[$id]['materials'][] = ['id' => (int) $c['child_ingredient_id'], 'name' => (string) $c['material'], 'unit' => $c['material_unit'], 'qty' => (float) $c['quantity']];
            } elseif ($c['child_recipe_id'] !== null) {
                $out[$id]['components'][] = ['id' => (int) $c['child_recipe_id'], 'name' => (string) $c['component'], 'type' => (int) $c['component_type'], 'qty' => (float) $c['quantity']];
            }
        }
        foreach ($this->all("SELECT p.id_recipe, p.id_preparation_type, p.duration_second, t.name
                FROM product_recipe_preparation_connection p
                LEFT JOIN product_preparation_type t ON t.id = p.id_preparation_type
                WHERE p.id_recipe IN ({$in}) ORDER BY p.id_recipe, p.id_preparation_type, p.duration_second", $ids) as $p) {
            if (!isset($out[(int) $p['id_recipe']])) continue;
            $out[(int) $p['id_recipe']]['preparations'][] = ['id' => (int) $p['id_preparation_type'], 'name' => (string) $p['name'], 'seconds' => (int) $p['duration_second']];
        }
        foreach ($this->all("SELECT f.recipe_id, f.material_id, f.quantity, m.price_per_unit
                FROM flattened_recipe_ingredient f LEFT JOIN raw_material m ON m.id = f.material_id
                WHERE f.recipe_id IN ({$in}) ORDER BY f.recipe_id, f.material_id", $ids) as $f) {
            $id = (int) $f['recipe_id'];
            if (!isset($out[$id])) continue;
            $out[$id]['ingredients'][] = [(int) $f['material_id'], (float) $f['quantity']];
            $out[$id]['cost'] += (float) $f['quantity'] * (float) $f['price_per_unit'];
        }
        return $out;
    }

    /** Dernière version de chaque recette : id_recipe => [version_no, hash, composition (JSON)]. */
    public function latest(array $recipeIds): array
    {
        $ids = self::ids($recipeIds);
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT v.id_recipe, v.version_no, v.content_hash, v.composition FROM n_recipe_version v
                JOIN (SELECT id_recipe, MAX(version_no) AS no FROM n_recipe_version WHERE id_recipe IN ({$in}) GROUP BY id_recipe) l
                  ON l.id_recipe = v.id_recipe AND l.no = v.version_no", $ids) as $v) {
            $out[(int) $v['id_recipe']] = ['version_no' => (int) $v['version_no'], 'hash' => (string) $v['content_hash'], 'composition' => (string) $v['composition']];
        }
        return $out;
    }

    /** Recettes sans aucune version (la version de départ reste à créer), les plus anciennes d'abord. */
    public function withoutVersion(int $limit): array
    {
        return array_map('intval', array_column($this->all("SELECT r.id FROM product_recipe r
            WHERE NOT EXISTS (SELECT 1 FROM n_recipe_version v WHERE v.id_recipe = r.id)
            ORDER BY r.id LIMIT " . max(1, $limit)), 'id'));
    }

    /** Toutes les versions d'une recette, la plus récente d'abord. */
    public function history(int $recipeId): array
    {
        return $this->all("SELECT v.id, v.version_no, v.valid_from, v.source, v.note, v.composition, v.ingredients, v.material_cost,
                v.created_at, COALESCE(NULLIF(u.displayed_name, ''), TRIM(CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.surname, '')))) AS author
            FROM n_recipe_version v LEFT JOIN user u ON u.id = v.created_by
            WHERE v.id_recipe = ? ORDER BY v.version_no DESC", [$recipeId]);
    }

    /** En-tête d'une recette (nom, type, produit fini lié) pour la page d'historique. */
    public function recipe(int $recipeId): ?array
    {
        $rows = $this->all("SELECT r.id, r.name, r.id_type, r.yield_quantity, u.name AS unit, fp.id AS product_id, fp.name AS product
            FROM product_recipe r LEFT JOIN unit u ON u.id = r.id_unit
            LEFT JOIN final_product fp ON fp.id = (SELECT MIN(f2.id) FROM final_product f2 WHERE f2.id_recipe = r.id)
            WHERE r.id = ?", [$recipeId]);
        return $rows[0] ?? null;
    }

    /** Matières : nom et unité (libellés de la liste aplatie). */
    public function materialNames(array $materialIds): array
    {
        $ids = self::ids($materialIds);
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT m.id, m.name, u.name AS unit FROM raw_material m LEFT JOIN unit u ON u.id = m.id_unit WHERE m.id IN ({$in})", $ids) as $m) {
            $out[(int) $m['id']] = ['name' => (string) $m['name'], 'unit' => $m['unit']];
        }
        return $out;
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
