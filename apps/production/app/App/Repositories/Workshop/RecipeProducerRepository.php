<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Grupa, która przygotowuje półprodukt / kompozycję (n_recipe_producer) — czasem jedna grupa robi recepturę dla innej. */
final class RecipeProducerRepository extends WorkshopRepository
{
    protected string $table = 'n_recipe_producer';

    /** @return array<int, array{id:int, name_pl:string, name_fr:string, color:string, lead_days:?int}> receptura => grupa */
    public function forRecipes(array $recipeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($this->all("SELECT p.id_recipe, p.lead_days, g.id, g.name_pl, g.name_fr, g.color FROM n_recipe_producer p
                             JOIN n_prod_group g ON g.id = p.id_group WHERE p.id_recipe IN ({$in})") as $r) {
            $out[(int) $r['id_recipe']] = ['id' => (int) $r['id'], 'name_pl' => $r['name_pl'], 'name_fr' => $r['name_fr'], 'color' => $r['color'],
                'lead_days' => $r['lead_days'] !== null ? (int) $r['lead_days'] : null];
        }
        return $out;
    }

    /** @param int|null|false $leadDays false = délai inchangé */
    public function set(int $recipeId, ?int $groupId, ?int $userId, int|null|false $leadDays = false): void
    {
        if ($groupId === null) {
            $this->db->prepare('DELETE FROM n_recipe_producer WHERE id_recipe = ?')->execute([$recipeId]);
            return;
        }
        if ($leadDays === false) {
            $this->db->prepare('INSERT INTO n_recipe_producer (id_recipe, id_group, updated_by) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE id_group = VALUES(id_group), updated_by = VALUES(updated_by)')->execute([$recipeId, $groupId, $userId]);
            return;
        }
        $this->db->prepare('INSERT INTO n_recipe_producer (id_recipe, id_group, lead_days, updated_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_group = VALUES(id_group), lead_days = VALUES(lead_days), updated_by = VALUES(updated_by)')
            ->execute([$recipeId, $groupId, $leadDays, $userId]);
    }

    /**
     * Karta półproduktu kompozycji bez żadnej historii (partie, plany, dni, zamówienia, straty, zamówienia między grupami)
     * — utworzona przez wybór grupy robiącej ; usuwana, gdy grupę się zdejmie (pomyłka nie zostawia karty na liście grupy).
     */
    public function unusedSemiItem(int $recipeId): ?int
    {
        $id = $this->all("SELECT i.id FROM n_prod_item i WHERE i.id_recipe = ? AND i.item_type = 'semi'
                            AND NOT EXISTS (SELECT 1 FROM n_prod_output o WHERE o.id_item = i.id)
                            AND NOT EXISTS (SELECT 1 FROM n_prod_plan_line l WHERE l.id_item = i.id)
                            AND NOT EXISTS (SELECT 1 FROM n_prod_day_line d WHERE d.id_item = i.id)
                            AND NOT EXISTS (SELECT 1 FROM n_prod_order r WHERE r.id_item = i.id)
                            AND NOT EXISTS (SELECT 1 FROM n_prod_waste w WHERE w.id_item = i.id)
                            AND NOT EXISTS (SELECT 1 FROM n_group_order g WHERE g.id_item = i.id)", [$recipeId])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function deleteItem(int $itemId): void
    {
        $this->db->prepare('DELETE FROM n_prod_item WHERE id = ?')->execute([$itemId]);
    }

    /** @return array<int, int> receptura → grupa robiąca (wszystkie ustawione) */
    public function groupMap(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_recipe, id_group FROM n_recipe_producer') as $r) $out[(int) $r['id_recipe']] = (int) $r['id_group'];
        return $out;
    }

    /** Grupa główna produktu receptury (karta produkcyjna produktu ERP z tą recepturą). */
    public function productGroup(int $recipeId): ?array
    {
        $r = $this->all('SELECT g.id, g.name_pl, g.name_fr, g.color FROM final_product fp JOIN n_prod_item i ON i.id_final_product = fp.id
                         JOIN n_prod_product_settings s ON s.id_item = i.id JOIN n_prod_group g ON g.id = s.id_primary_group
                         WHERE fp.id_recipe = ? ORDER BY fp.active DESC, fp.id LIMIT 1', [$recipeId])[0] ?? null;
        return $r === null ? null : ['id' => (int) $r['id'], 'name_pl' => $r['name_pl'], 'name_fr' => $r['name_fr'], 'color' => $r['color'], 'lead_days' => null];
    }

    /** Jednostka receptury (g, kg, szt…) : jednostka bazowa karty półproduktu. */
    public function recipeUnit(int $recipeId): ?string
    {
        return $this->all('SELECT u.name FROM product_recipe pr LEFT JOIN unit u ON u.id = pr.id_unit WHERE pr.id = ?', [$recipeId])[0]['name'] ?? null;
    }

    public function groups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color FROM n_prod_group WHERE active = 1 ORDER BY sort_order, name_pl');
    }

    public function groupExists(int $groupId): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_group WHERE id = ? AND active = 1', [$groupId]);
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
                                JOIN n_prod_item i ON i.id_final_product = fp.id
                                JOIN n_prod_product_settings s ON s.id_item = i.id
                                JOIN n_prod_group g ON g.id = s.id_primary_group
                                WHERE f.subrecipe_id = pr.id AND s.id_primary_group <> p.id_group) AS for_groups
                        FROM n_recipe_producer p
                        JOIN product_recipe pr ON pr.id = p.id_recipe
                        LEFT JOIN unit u ON u.id = pr.id_unit
                        WHERE p.id_group = ? ORDER BY pr.name", [$groupId]));
    }
}
