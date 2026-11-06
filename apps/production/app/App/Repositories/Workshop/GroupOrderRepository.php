<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Zamówienia między grupami (n_group_order) : kompozycja zamawiana przez grupę u grupy, która ją robi ; partie
 * kompozycji grupy robiącej ; kompozycje odcięte w partiach produktu (n_prod_output_component).
 */
final class GroupOrderRepository extends WorkshopRepository
{
    protected string $table = 'n_group_order';
    protected array $writable = ['id_recipe', 'id_item', 'id_group_from', 'id_group_to', 'quantity', 'unit', 'due_at', 'status', 'id_output',
        'id_picked_output', 'picked_quantity', 'note', 'ordered_by', 'id_device', 'accepted_by', 'accepted_at', 'ready_at', 'picked_at',
        'cancelled_by', 'cancelled_at', 'client_uuid'];

    private const ROW = "SELECT o.*, pr.name AS recipe_name, gf.name_pl AS from_pl, gf.name_fr AS from_fr, gf.color AS from_color,
                                gt.name_pl AS to_pl, gt.name_fr AS to_fr, gt.color AS to_color,
                                TRIM(CONCAT(pe.name, ' ', pe.surname)) AS ordered_by_name,
                                ob.lot_code, ob.created_at AS output_at, ob.quantity AS output_quantity, pk.lot_code AS picked_lot
                         FROM n_group_order o
                         JOIN product_recipe pr ON pr.id = o.id_recipe
                         JOIN n_prod_group gf ON gf.id = o.id_group_from
                         JOIN n_prod_group gt ON gt.id = o.id_group_to
                         LEFT JOIN n_prod_employee e ON e.id = o.ordered_by
                         LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                         LEFT JOIN n_prod_output ob ON ob.id = o.id_output
                         LEFT JOIN n_prod_output pk ON pk.id = o.id_picked_output";

    /**
     * Kompozycje, które grupa może zamówić : podreceptury receptur jej kart, robione przez inną grupę.
     * @return list<array<string, mixed>>
     */
    public function orderables(int $groupId): array
    {
        return $this->all(
            "SELECT DISTINCT pr.id AS recipe_id, pr.name, u.name AS recipe_unit, pr.yield_quantity, p.lead_days,
                    g.id AS producer_id, g.name_pl AS producer_pl, g.name_fr AS producer_fr, g.color AS producer_color,
                    si.id AS item_id, si.base_unit
             FROM n_prod_item gi
             JOIN n_prod_product_settings gs ON gs.id_item = gi.id AND gs.id_primary_group = ?
             LEFT JOIN final_product fp ON fp.id = gi.id_final_product
             JOIN flattened_recipe_recipe f ON f.recipe_id = COALESCE(fp.id_recipe, gi.id_recipe)
             JOIN n_recipe_producer p ON p.id_recipe = f.subrecipe_id AND p.id_group <> ?
             JOIN product_recipe pr ON pr.id = p.id_recipe
             JOIN n_prod_group g ON g.id = p.id_group AND g.active = 1
             LEFT JOIN unit u ON u.id = pr.id_unit
             LEFT JOIN n_prod_item si ON si.id_recipe = pr.id
             WHERE fp.id IS NULL OR fp.active = 1
             ORDER BY pr.name",
            [$groupId, $groupId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->all(self::ROW . ' WHERE o.id = ?', [$id])[0] ?? null;
    }

    public function byUuid(string $uuid): ?array
    {
        return $this->all('SELECT id FROM n_group_order WHERE client_uuid = ?', [$uuid])[0] ?? null;
    }

    /** Zamówienia grupy (złożone) : otwarte i z ostatnich dni. */
    public function from(int $groupId, string $since): array
    {
        return $this->all(self::ROW . " WHERE o.id_group_from = ? AND (o.due_at >= ? OR o.status IN ('ordered','accepted','in_production','ready'))
                                        ORDER BY o.due_at, o.id LIMIT 60", [$groupId, $since]);
    }

    /** Zamówienia dla grupy (do zrobienia) : otwarte i odebrane w ostatnich dniach. */
    public function to(int $groupId, string $since): array
    {
        return $this->all(self::ROW . " WHERE o.id_group_to = ? AND (o.status IN ('ordered','accepted','in_production','ready')
                                        OR (o.status = 'picked' AND o.due_at >= ?)) ORDER BY o.due_at, o.id LIMIT 60", [$groupId, $since]);
    }

    /** ERP : zamówienia między grupami z okresu (opcjonalnie grupa — zamawiająca albo robiąca, albo kompozycje receptury). */
    public function between(string $from, string $to, ?int $groupId, array $recipeIds = []): array
    {
        $where = 'o.due_at >= ? AND o.due_at < ? + INTERVAL 1 DAY';
        $params = [$from, $to];
        if ($groupId !== null) { $where .= ' AND (o.id_group_from = ? OR o.id_group_to = ?)'; $params[] = $groupId; $params[] = $groupId; }
        if ($recipeIds) $where .= ' AND o.id_recipe IN (' . implode(',', array_map('intval', $recipeIds)) . ')';
        return $this->all(self::ROW . " WHERE {$where} ORDER BY o.due_at, o.id LIMIT 500", $params);
    }

    /** Otwarte zamówienie grupy na kompozycję, gotowe albo w produkcji (najbliższe) : podpowiedź partii w partii produktu. */
    public function openFor(int $fromGroup, int $recipeId): array
    {
        return $this->all(self::ROW . " WHERE o.id_group_from = ? AND o.id_recipe = ? AND o.status IN ('in_production','ready')
                                        ORDER BY o.due_at LIMIT 5", [$fromGroup, $recipeId]);
    }

    /**
     * Partie kompozycji grupy robiącej (karta półproduktu) z ostatnich dni, najnowsze najpierw.
     * @return list<array{id:int|string, lot_code:string, quantity:string, work_date:string, created_at:string, employee_name:?string}>
     */
    public function sourceBatches(int $itemId, int $groupId, string $since): array
    {
        return $this->all("SELECT o.id, o.lot_code, o.quantity, o.work_date, o.created_at, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name
                           FROM n_prod_output o
                           LEFT JOIN n_prod_employee e ON e.id = o.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE o.id_item = ? AND o.id_group = ? AND o.cancelled_at IS NULL AND o.work_date >= ?
                           ORDER BY o.created_at DESC LIMIT 8", [$itemId, $groupId, $since]);
    }

    /** Zamówienia grupy zamawiającej z daną partią grupy robiącej, jeszcze nieodebrane. */
    public function byOutput(int $outputId, int $fromGroup): array
    {
        return $this->all("SELECT id FROM n_group_order WHERE id_output = ? AND id_group_from = ? AND status IN ('in_production','ready')", [$outputId, $fromGroup]);
    }

    /** Karta półproduktu kompozycji. */
    public function itemForRecipe(int $recipeId): ?int
    {
        $id = $this->all('SELECT id FROM n_prod_item WHERE id_recipe = ?', [$recipeId])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function output(int $id): ?array
    {
        return $this->all('SELECT id, id_item, id_group, lot_code, quantity, work_date, cancelled_at FROM n_prod_output WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return array<int, array> kompozycja → wybrana partia (partia produktu) */
    public function components(int $outputId): array
    {
        $out = [];
        foreach ($this->all("SELECT c.id_recipe, c.id_source_output, c.quantity, c.unit, s.lot_code, s.created_at, s.quantity AS source_quantity
                             FROM n_prod_output_component c LEFT JOIN n_prod_output s ON s.id = c.id_source_output
                             WHERE c.id_output = ?", [$outputId]) as $r) {
            $out[(int) $r['id_recipe']] = $r;
        }
        return $out;
    }

    public function saveComponent(int $outputId, int $recipeId, ?int $sourceId, ?float $quantity, ?string $unit, ?int $employeeId): void
    {
        $this->db->prepare('INSERT INTO n_prod_output_component (id_output, id_recipe, id_source_output, quantity, unit, id_employee) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_source_output = VALUES(id_source_output), quantity = VALUES(quantity), unit = VALUES(unit), id_employee = VALUES(id_employee)')
            ->execute([$outputId, $recipeId, $sourceId, $quantity, $unit, $employeeId]);
    }
}
