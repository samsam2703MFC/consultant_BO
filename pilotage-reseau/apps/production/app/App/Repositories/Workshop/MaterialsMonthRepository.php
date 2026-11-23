<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Asystent « Kartoteka surowców » [401] : lecture
 *  - des surowce (raw_material : id_category, id_unit, price_per_unit, package_size_in_base_unit, id_current_supplier,
 *    is_part_of_package, valeurs nutritionnelles *_100g — colonnes de MaterialRepository) avec l'existence de leur
 *    catégorie (category_material, id_group 1 = opakowanie comme SupplyRepository), de leur unité (unit), de leur dostawca
 *    (supplier, is_active), le nombre de produits actifs qui les utilisent (flattened_recipe_ingredient → final_product),
 *    leurs alergeny (material_allergen_connection) et la date de leur première cena (raw_material_price_changelog) ;
 *  - du repère « nowe surowce » : le plus grand raw_material.id au dernier « Zakończ » (n_materials_month_mark).
 * Une source dont la table manque (base partielle) est rendue null (« brak danych »), une colonne absente lue NULL.
 * N'écrit que n_materials_month_review et n_materials_month_mark.
 */
final class MaterialsMonthRepository extends WorkshopRepository
{
    protected string $table = 'n_materials_month_review';
    /** Colonnes des valeurs nutritionnelles (écran Wartości odżywcze). */
    public const NUTRITION = ['energy_100g', 'fat_100g', 'saturated_fat_100g', 'carbohydrates_100g', 'sugars_100g', 'salt_100g', 'fiber_100g', 'proteins_100g'];

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
        return $this->hasColumn('raw_material', $column) ? 'rm.' . $column : 'NULL';
    }

    /** Tous les surowce avec l'état de leur fiche (null : table raw_material absente). */
    public function materials(): ?array
    {
        if (!$this->hasTable('raw_material')) return null;
        $cat = $this->hasTable('category_material');
        $sup = $this->hasTable('supplier');
        $nutrition = $this->hasColumn('raw_material', 'energy_100g')
            ? '(' . implode(' + ', array_map(fn($c) => "(COALESCE(rm.{$c}, 0) <> 0)", self::NUTRITION)) . ')' : 'NULL';
        $usage = $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('final_product')
            ? '(SELECT COUNT(DISTINCT fp.id) FROM flattened_recipe_ingredient fri JOIN final_product fp ON fp.id_recipe = fri.recipe_id AND fp.active = 1 WHERE fri.material_id = rm.id)' : 'NULL';
        $allergens = $this->hasTable('material_allergen_connection') ? '(SELECT COUNT(*) FROM material_allergen_connection mac WHERE mac.material_id = rm.id)' : 'NULL';
        $firstPrice = $this->hasTable('raw_material_price_changelog') ? '(SELECT MIN(pc.timestamp) FROM raw_material_price_changelog pc WHERE pc.id_raw_material = rm.id)' : 'NULL';
        return $this->all("SELECT rm.id AS id_raw_material, rm.name AS material_name, {$this->col('id_category')} AS id_category, {$this->col('id_unit')} AS id_unit,
                {$this->col('price_per_unit')} AS price_per_unit, {$this->col('package_size_in_base_unit')} AS package_size, {$this->col('id_current_supplier')} AS id_supplier,
                COALESCE({$this->col('is_part_of_package')}, 0) AS is_part_of_package,
                " . ($cat ? 'cm.name' : 'NULL') . " AS category_name, " . ($cat && $this->hasColumn('category_material', 'id_group') ? 'cm.id_group' : 'NULL') . " AS category_group,
                " . ($this->hasTable('unit') ? '(SELECT u.name FROM unit u WHERE u.id = rm.id_unit)' : 'NULL') . " AS unit_name,
                " . ($sup ? 's.name' : 'NULL') . " AS supplier_name, " . ($sup && $this->hasColumn('supplier', 'is_active') ? 's.is_active' : 'NULL') . " AS supplier_active,
                {$nutrition} AS nutrition_values, {$usage} AS active_products, {$allergens} AS allergens, {$firstPrice} AS first_price_at
            FROM raw_material rm
            " . ($cat ? 'LEFT JOIN category_material cm ON cm.id = rm.id_category' : '') . "
            " . ($sup ? 'LEFT JOIN supplier s ON s.id = rm.id_current_supplier' : '') . "
            ORDER BY rm.name, rm.id");
    }

    /** Tables de référence présentes (sans table, le signal correspondant n'est pas calculé). */
    public function references(): array
    {
        return ['category' => $this->hasTable('category_material'), 'unit' => $this->hasTable('unit'), 'supplier' => $this->hasTable('supplier'),
            'nutrition' => $this->hasColumn('raw_material', 'energy_100g'), 'allergens' => $this->hasTable('material_allergen_connection'),
            'usage' => $this->hasTable('flattened_recipe_ingredient') && $this->hasTable('final_product'), 'price_log' => $this->hasTable('raw_material_price_changelog')];
    }

    /** Repère du dernier « Zakończ » avant $monthStart : plus grand raw_material.id connu alors (null : aucun). */
    public function lastMark(string $monthStart): ?array
    {
        return $this->all('SELECT month_start, max_material_id, marked_at FROM n_materials_month_mark WHERE month_start < ? ORDER BY month_start DESC LIMIT 1', [$monthStart])[0] ?? null;
    }

    public function saveMark(string $monthStart, ?int $userId): void
    {
        $max = (int) ($this->all('SELECT COALESCE(MAX(id), 0) AS m FROM raw_material')[0]['m'] ?? 0);
        $this->db->prepare('INSERT INTO n_materials_month_mark (month_start, max_material_id, id_user, marked_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE max_material_id = VALUES(max_material_id), id_user = VALUES(id_user), marked_at = NOW()')->execute([$monthStart, $max, $userId]);
    }

    /** @return array<string, list<array>> élément → décisions (la plus récente d'abord), tous mois confondus */
    public function decisions(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) return [];
        $out = [];
        foreach ($this->all('SELECT r.*, COALESCE(NULLIF(TRIM(u.displayed_name), \'\'), u.login) AS decided_by
                FROM n_materials_month_review r LEFT JOIN user u ON u.id = r.id_user
                WHERE r.item_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY r.month_start DESC, r.updated_at DESC, r.id DESC', $keys) as $r) {
            $out[(string) $r['item_key']][] = $r;
        }
        return $out;
    }

    /** @return list<string> éléments décidés pendant le mois (restent à l'écran même sans braki). */
    public function decidedIn(string $monthStart): array
    {
        return array_column($this->all('SELECT item_key FROM n_materials_month_review WHERE month_start = ?', [$monthStart]), 'item_key');
    }

    /** Une décision par (mois du contrôle, élément) : la dernière enregistrée remplace la précédente. */
    public function saveDecision(string $monthStart, string $key, int $sourceId, string $decision, ?string $note, string $flags, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_materials_month_review (month_start, item_key, source_id, decision, note, flags, id_user) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), note = VALUES(note), flags = VALUES(flags), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$monthStart, $key, $sourceId, $decision, $note, mb_substr($flags, 0, 255), $userId]);
    }
}
