<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Receptury i dane produktów » [400] : lecture
 *  - des produits actifs (final_product.active = 1 : id_recipe, ppc, weight, cpl / lpp / cpp, ppb / bpc, shelf_life,
 *    id_category, id_section, pre_calculated_raw_price_per_carton — colonnes de FinalProductRepository) avec l'existence
 *    de leur recette (product_recipe), son nombre de składniki (product_recipe_connection.parent_recipe_id), les
 *    surowce de la recette aplatie sans prix (flattened_recipe_ingredient → raw_material.price_per_unit), l'existence de
 *    leur catégorie (category_product) et de leur dział (production_sections) ;
 *  - des recettes « na 1 sztukę » encore à décider : la règle de « Receptura produktu = 1 karton » [201]
 *    (RecipeUnitCheckRepository::candidates, appelée telle quelle par le service) — ici seulement la présence de ses tables.
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »), une colonne absente lue NULL.
 * N'écrit que n_recipes_month_review.
 */
final class RecipesMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_recipes_month_review';

    /** @var array<string, bool> */
    private array $tables = [];
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->all('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $this->columns[$table] ??= array_column($this->all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'c');
        return in_array($column, $this->columns[$table], true);
    }

    private function col(string $column): string
    {
        return $this->hasColumn('final_product', $column) ? 'fp.' . $column : 'NULL';
    }

    /** Produits actifs avec l'état de leur recette et de leurs données (null : table final_product absente). */
    public function activeProducts(): ?array
    {
        if (!$this->hasTable('final_product')) return null;
        $recipe = $this->hasTable('product_recipe') ? '(SELECT COUNT(*) FROM product_recipe pr WHERE pr.id = fp.id_recipe)' : 'NULL';
        $lines = $this->hasTable('product_recipe_connection') ? '(SELECT COUNT(*) FROM product_recipe_connection prc WHERE prc.parent_recipe_id = fp.id_recipe)' : 'NULL';
        $noPrice = $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('raw_material')
            ? "(SELECT GROUP_CONCAT(DISTINCT rm.name ORDER BY rm.name SEPARATOR '\n') FROM flattened_recipe_ingredient fri JOIN raw_material rm ON rm.id = fri.material_id
                WHERE fri.recipe_id = fp.id_recipe AND COALESCE(rm.price_per_unit, 0) <= 0)" : 'NULL';
        $category = $this->hasTable('category_product') ? '(SELECT cp.name FROM category_product cp WHERE cp.id = fp.id_category)' : 'NULL';
        $section = $this->hasTable('production_sections') && $this->hasColumn('final_product', 'id_section')
            ? '(SELECT ps.name FROM production_sections ps WHERE ps.id = fp.id_section)' : 'NULL';
        return $this->all("SELECT fp.id AS id_final_product, fp.name AS product_name, fp.id_recipe, {$this->col('ppc')} AS ppc, {$this->col('weight')} AS weight,
                {$this->col('ppb')} AS ppb, {$this->col('bpc')} AS bpc, {$this->col('cpl')} AS cpl, {$this->col('lpp')} AS lpp, {$this->col('cpp')} AS cpp,
                {$this->col('shelf_life')} AS shelf_life, {$this->col('id_category')} AS id_category, {$this->col('id_section')} AS id_section,
                {$this->col('pre_calculated_raw_price_per_carton')} AS card_cost,
                {$recipe} AS recipe_exists, {$lines} AS recipe_lines, {$noPrice} AS no_price_materials, {$category} AS category_name, {$section} AS section_name
            FROM final_product fp
            WHERE fp.active = 1
            ORDER BY fp.name, fp.id");
    }

    /** Tables de la règle « receptura = 1 karton » [201] présentes (sinon le signal n'est pas calculé). */
    public function unitCheckAvailable(): bool
    {
        foreach (['n_recipe_unit_check', 'n_activity_view', 'product_recipe_connection', 'flattened_recipe_ingredient', 'raw_material'] as $t) if (!$this->hasTable($t)) return false;
        return true;
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_recipes_month_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pendant le mois (restent à l'écran même sans braki). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_recipes_month_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** Une décision par (mois du contrôle, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, int $sourceId, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_recipes_month_review (month_start, item_key, source_id, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $sourceId, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
