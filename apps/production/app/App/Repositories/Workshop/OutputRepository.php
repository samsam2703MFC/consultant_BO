<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Tablet grupy : partie wyrobu dnia, partie surowców i opakowań, zdjęcia etykiet (identyfikowalność). */
final class OutputRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_output';
    protected array $writable = ['work_date', 'id_group', 'id_item', 'quantity', 'lot_code', 'id_employee', 'id_device', 'client_uuid', 'note', 'cancelled_at', 'cancelled_by'];

    private const ITEM = "SELECT i.id, i.item_type, i.base_unit, i.id_final_product, COALESCE(i.id_recipe, fp.id_recipe) AS id_recipe,
            COALESCE(fp.name, pr.name) AS name, fp.ppc, s.id_primary_group, s.batch_multiple
        FROM n_prod_item i
        LEFT JOIN final_product fp ON fp.id = i.id_final_product
        LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
        LEFT JOIN n_prod_product_settings s ON s.id_item = i.id";

    /** Karty grupy (produkty aktywne w ERP i półprodukty). */
    public function groupItems(int $groupId): array
    {
        return $this->all(self::ITEM . ' WHERE s.id_primary_group = ? AND (fp.id IS NULL OR fp.active = 1) ORDER BY name, i.id', [$groupId]);
    }

    public function item(int $id): ?array
    {
        return $this->all(self::ITEM . ' WHERE i.id = ?', [$id])[0] ?? null;
    }

    /** @return array<int, float> karta => ilość zaplanowana na dzień (Gantt tygodnia) */
    public function planned(string $day, array $itemIds): array
    {
        if (!$itemIds) return [];
        $in = implode(',', array_map('intval', $itemIds));
        $out = [];
        foreach ($this->all("SELECT id_item, SUM(quantity) AS q FROM n_prod_week_slot WHERE work_date = ? AND id_item IN ({$in}) GROUP BY id_item", [$day]) as $r) {
            $out[(int) $r['id_item']] = (float) $r['q'];
        }
        return $out;
    }

    /** Partie dnia grupy (z anulowanymi), z liczbą wpisanych partii surowców. */
    public function day(string $day, int $groupId): array
    {
        return $this->all("SELECT o.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name,
                                  (SELECT COUNT(*) FROM n_prod_output_lot l WHERE l.id_output = o.id AND l.lot_number IS NOT NULL AND l.lot_number <> '') AS lots_done
                           FROM n_prod_output o
                           LEFT JOIN n_prod_employee e ON e.id = o.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE o.work_date = ? AND o.id_group = ? ORDER BY o.created_at, o.id", [$day, $groupId]);
    }

    public function output(int $id): ?array
    {
        return $this->all("SELECT o.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name
                           FROM n_prod_output o
                           LEFT JOIN n_prod_employee e ON e.id = o.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE o.id = ?", [$id])[0] ?? null;
    }

    public function byUuid(string $uuid): ?array
    {
        return $this->all('SELECT * FROM n_prod_output WHERE client_uuid = ?', [$uuid])[0] ?? null;
    }

    public function lotCodeTaken(string $code): bool
    {
        return (bool) $this->all('SELECT 1 FROM n_prod_output WHERE lot_code = ?', [$code]);
    }

    public function countForDay(string $day, int $groupId): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM n_prod_output WHERE work_date = ? AND id_group = ?', [$day, $groupId])[0]['n'] ?? 0);
    }

    /** Surowce i opakowania receptury : ilość na karton (produkt) albo na recepturę (półprodukt). */
    public function recipe(int $recipeId): array
    {
        return $this->all("SELECT fri.material_id, rm.name, rm.name_fr, u.name AS unit, fri.quantity, COALESCE(c.id_group, 0) = 1 AS packaging
                           FROM flattened_recipe_ingredient fri
                           JOIN raw_material rm ON rm.id = fri.material_id
                           LEFT JOIN unit u ON u.id = rm.id_unit
                           LEFT JOIN category_material c ON c.id = rm.id_category
                           WHERE fri.recipe_id = ? ORDER BY packaging, fri.quantity DESC, rm.name", [$recipeId]);
    }

    // --- Straty dnia i zatwierdzenie dnia ------------------------------------------------------------

    public function insertWaste(array $w): int
    {
        $this->db->prepare('INSERT INTO n_prod_waste (work_date, id_group, id_item, id_output, quantity, reason, note, id_employee, id_device, client_uuid)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$w['work_date'], $w['id_group'], $w['id_item'], $w['id_output'], $w['quantity'], $w['reason'], $w['note'], $w['id_employee'], $w['id_device'], $w['client_uuid']]);
        return (int) $this->db->lastInsertId();
    }

    public function waste(int $id): ?array
    {
        return $this->all('SELECT * FROM n_prod_waste WHERE id = ?', [$id])[0] ?? null;
    }

    public function wasteByUuid(string $uuid): ?array
    {
        return $this->all('SELECT * FROM n_prod_waste WHERE client_uuid = ?', [$uuid])[0] ?? null;
    }

    public function cancelWaste(int $id, ?int $employeeId): void
    {
        $this->db->prepare('UPDATE n_prod_waste SET cancelled_at = NOW(), cancelled_by = ? WHERE id = ? AND cancelled_at IS NULL')->execute([$employeeId, $id]);
    }

    /** Straty dnia grupy (z anulowanymi), z osobą i numerem partii wyrobu. */
    public function dayWaste(string $day, int $groupId): array
    {
        return $this->all("SELECT w.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name, o.lot_code
                           FROM n_prod_waste w
                           LEFT JOIN n_prod_output o ON o.id = w.id_output
                           LEFT JOIN n_prod_employee e ON e.id = w.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE w.work_date = ? AND w.id_group = ? ORDER BY w.created_at, w.id", [$day, $groupId]);
    }

    public function dayClose(string $day, int $groupId): ?array
    {
        return $this->all("SELECT c.*, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name
                           FROM n_prod_day_close c
                           LEFT JOIN n_prod_employee e ON e.id = c.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           WHERE c.work_date = ? AND c.id_group = ?", [$day, $groupId])[0] ?? null;
    }

    public function saveDayClose(string $day, int $groupId, ?int $employeeId, ?int $deviceId, ?string $note, string $summary): void
    {
        $this->db->prepare('INSERT INTO n_prod_day_close (work_date, id_group, id_employee, id_device, note, summary) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE id_employee = VALUES(id_employee), id_device = VALUES(id_device), note = VALUES(note),
                                summary = VALUES(summary), closed_at = NOW()')
            ->execute([$day, $groupId, $employeeId, $deviceId, $note, $summary]);
    }

    // --- Receptury (zakładka tabletu) ----------------------------------------------------------------

    public function recipeHead(int $recipeId): ?array
    {
        return $this->all('SELECT pr.id, pr.name, pr.yield_quantity, u.name AS unit, pr.description FROM product_recipe pr LEFT JOIN unit u ON u.id = pr.id_unit WHERE pr.id = ?', [$recipeId])[0] ?? null;
    }

    /** Składniki receptury jednego poziomu : surowce / opakowania i podreceptury (ilość na wydajność receptury). */
    public function recipeLines(int $recipeId): array
    {
        return $this->all("SELECT c.child_recipe_id, c.child_ingredient_id, c.quantity,
                                  rm.name AS material_name, rm.name_fr AS material_name_fr, um.name AS material_unit, COALESCE(cm.id_group, 0) = 1 AS packaging,
                                  pr.name AS recipe_name, pr.yield_quantity, ur.name AS recipe_unit
                           FROM product_recipe_connection c
                           LEFT JOIN raw_material rm ON rm.id = c.child_ingredient_id
                           LEFT JOIN unit um ON um.id = rm.id_unit
                           LEFT JOIN category_material cm ON cm.id = rm.id_category
                           LEFT JOIN product_recipe pr ON pr.id = c.child_recipe_id
                           LEFT JOIN unit ur ON ur.id = pr.id_unit
                           WHERE c.parent_recipe_id = ? ORDER BY c.child_recipe_id IS NULL, packaging, c.quantity DESC", [$recipeId]);
    }

    /** Etapy przygotowania receptury (formularz receptury ; starsze instrukcje, gdy brak etapów). @return list<string> */
    public function recipeSteps(int $recipeId): array
    {
        $steps = array_column($this->all('SELECT step_description FROM product_recipe_preparation_step WHERE recipe_id = ? ORDER BY step_number, id', [$recipeId]), 'step_description');
        if ($steps) return $steps;
        return array_column($this->all('SELECT prepare_step_desc FROM product_recipe_prepare_instruction_connection WHERE id_recipe = ? ORDER BY prepare_step_num, id', [$recipeId]), 'prepare_step_desc');
    }

    /** Etapy produkcji karty (Etapy i czasy) : nazwa, stanowisko, temperatura. */
    public function routing(int $itemId): array
    {
        return $this->all('SELECT rs.step_no, rs.name_pl, rs.name_fr, rs.temperature_c, w.name_pl AS ws_pl, w.name_fr AS ws_fr
                           FROM n_prod_routing_step rs LEFT JOIN n_prod_workstation w ON w.id = rs.id_workstation
                           WHERE rs.id_item = ? ORDER BY rs.step_no, rs.id', [$itemId]);
    }

    /** @return array<int, array> surowiec => partia wpisana do tej partii wyrobu */
    public function lots(int $outputId): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_prod_output_lot WHERE id_output = ?', [$outputId]) as $r) $out[(int) $r['id_material']] = $r;
        return $out;
    }

    /** @return array<int, array> surowiec => ostatnia partia użyta w grupie (podpowiedź « jak ostatnio ») */
    public function lastLots(int $groupId, array $materialIds, int $exceptOutput): array
    {
        if (!$materialIds) return [];
        $in = implode(',', array_map('intval', $materialIds));
        $out = [];
        foreach ($this->all("SELECT l.id_material, l.lot_number, l.best_before, l.updated_at
                             FROM n_prod_output_lot l JOIN n_prod_output o ON o.id = l.id_output
                             WHERE o.id_group = ? AND o.id <> ? AND o.cancelled_at IS NULL AND l.id_material IN ({$in})
                               AND l.lot_number IS NOT NULL AND l.lot_number <> '' AND o.work_date >= CURDATE() - INTERVAL 60 DAY
                             ORDER BY l.updated_at DESC", [$groupId, $exceptOutput]) as $r) {
            $out[(int) $r['id_material']] ??= $r;
        }
        return $out;
    }

    public function saveLot(int $outputId, int $materialId, ?float $quantity, ?string $lot, ?string $bestBefore, ?int $photoId, ?string $ocr, ?int $employeeId): void
    {
        $this->db->prepare('INSERT INTO n_prod_output_lot (id_output, id_material, quantity, lot_number, best_before, id_photo, ocr_text, id_employee)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), lot_number = VALUES(lot_number), best_before = VALUES(best_before),
                                id_photo = COALESCE(VALUES(id_photo), id_photo), ocr_text = COALESCE(VALUES(ocr_text), ocr_text), id_employee = VALUES(id_employee)')
            ->execute([$outputId, $materialId, $quantity, $lot, $bestBefore, $photoId, $ocr, $employeeId]);
    }

    public function insertPhoto(string $mime, string $bytes, ?int $deviceId, ?int $employeeId): int
    {
        $this->db->prepare('INSERT INTO n_prod_lot_photo (mime, bytes, sha256, image, id_device, id_employee) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$mime, strlen($bytes), hash('sha256', $bytes), $bytes, $deviceId, $employeeId]);
        return (int) $this->db->lastInsertId();
    }

    public function photo(int $id): ?array
    {
        return $this->all('SELECT id, mime, image, created_at FROM n_prod_lot_photo WHERE id = ?', [$id])[0] ?? null;
    }

    /**
     * Identyfikowalność (strona biura) : partie z okresu, grupy, wyszukiwanie po numerze partii wyrobu
     * lub surowca (wycofanie z rynku : które partie wyrobu zawierają daną partię surowca).
     */
    public function trace(string $from, string $to, ?int $groupId, ?string $search): array
    {
        $where = ['o.work_date BETWEEN ? AND ?'];
        $params = [$from, $to];
        if ($groupId) { $where[] = 'o.id_group = ?'; $params[] = $groupId; }
        if ($search !== null && $search !== '') {
            $where[] = '(o.lot_code LIKE ? OR COALESCE(fp.name, pr.name) LIKE ? OR EXISTS (SELECT 1 FROM n_prod_output_lot l2 JOIN raw_material rm2 ON rm2.id = l2.id_material
                         WHERE l2.id_output = o.id AND (l2.lot_number LIKE ? OR rm2.name LIKE ?)))';
            $like = '%' . addcslashes($search, '\\%_') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        return $this->all("SELECT o.*, COALESCE(fp.name, pr.name) AS item_name, i.base_unit, fp.ppc, g.name_pl AS group_name,
                                  TRIM(CONCAT(pe.name, ' ', pe.surname)) AS employee_name, d.name AS device_name
                           FROM n_prod_output o
                           JOIN n_prod_item i ON i.id = o.id_item
                           LEFT JOIN final_product fp ON fp.id = i.id_final_product
                           LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
                           JOIN n_prod_group g ON g.id = o.id_group
                           LEFT JOIN n_prod_employee e ON e.id = o.id_employee
                           LEFT JOIN production_employee pe ON pe.id = e.id_production_employee
                           LEFT JOIN n_prod_device d ON d.id = o.id_device
                           WHERE " . implode(' AND ', $where) . ' ORDER BY o.work_date DESC, o.created_at DESC LIMIT 500', $params);
    }

    /** @return array<int, list<array>> partia wyrobu => partie surowców */
    public function traceLots(array $outputIds): array
    {
        if (!$outputIds) return [];
        $in = implode(',', array_map('intval', $outputIds));
        $out = [];
        foreach ($this->all("SELECT l.*, rm.name AS material_name, u.name AS unit
                             FROM n_prod_output_lot l JOIN raw_material rm ON rm.id = l.id_material LEFT JOIN unit u ON u.id = rm.id_unit
                             WHERE l.id_output IN ({$in}) ORDER BY rm.name") as $r) {
            $out[(int) $r['id_output']][] = $r;
        }
        return $out;
    }
}
