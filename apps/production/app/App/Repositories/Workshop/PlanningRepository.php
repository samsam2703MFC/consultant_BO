<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Lectures du calcul des besoins (commandes, expéditions, stock de l'ERP, nomenclatures) et
 * écriture des plans (tfb_prod_plan, tfb_prod_plan_line) et des OF (tfb_prod_order).
 */
final class PlanningRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_plan';
    protected array $writable = ['period_start', 'period_end', 'status', 'version', 'created_by', 'validated_by', 'validated_at', 'frozen_at', 'closed_at', 'notes'];

    /** Articles planifiables avec réglages, colisage, recette et groupe. */
    public function items(): array
    {
        return $this->all(
            "SELECT i.id, i.item_type, i.id_final_product, i.id_recipe, i.base_unit, COALESCE(fp.name, pr.name) AS name,
                    fp.ppc, COALESCE(fp.id_recipe, i.id_recipe) AS recipe_id, fp.active AS erp_active,
                    s.mode, s.never_frozen, s.stock_min, s.stock_max, s.coverage_days, s.batch_min, s.batch_multiple,
                    s.id_primary_group, s.status, g.code AS group_code
             FROM tfb_prod_item i
             JOIN tfb_prod_product_settings s ON s.id_item = i.id
             LEFT JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             LEFT JOIN tfb_prod_group g ON g.id = s.id_primary_group
             ORDER BY i.id"
        );
    }

    /** Stałe partie co tydzień (📌) : produkt, dzień tygodnia ISO, ilość. */
    public function fixedRules(): array
    {
        return $this->all('SELECT id, id_item, weekday, quantity, start_time FROM tfb_prod_fixed_batch ORDER BY id_item, weekday');
    }

    /** Lignes de commandes clients pas encore expédiées, à expédier entre deux dates. */
    public function openOrderLines(string $from, string $to): array
    {
        return $this->all(
            'SELECT co.id AS customer_order_id, co.expected_shipping_date, co.create_order_date, co.id_customer,
                    cop.id_product, SUM(cop.quantity_carton) AS cartons
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             WHERE co.id_wdt IS NULL AND co.status < 4 AND COALESCE(co.is_freebie, 0) = 0
               AND co.expected_shipping_date BETWEEN ? AND ?
             GROUP BY co.id, co.expected_shipping_date, co.create_order_date, co.id_customer, cop.id_product
             HAVING cartons > 0',
            [$from, $to]
        );
    }

    /** @return array<int,float> produit => cartons expédiés (WDT) sur n jours */
    public function shippedCartons(int $days): array
    {
        $rows = $this->all(
            'SELECT p.id_product, SUM(p.quantity) AS cartons FROM document_wdt w JOIN document_wdt_product p ON p.id_document_wdt = w.id
             WHERE w.established_date >= CURDATE() - INTERVAL ? DAY AND w.established_date < CURDATE()
               AND w.id NOT IN (SELECT id_document_wdt FROM tfb_wdt_superseded)
             GROUP BY p.id_product',
            [$days]
        );
        return array_map('floatval', array_column($rows, 'cartons', 'id_product'));
    }

    /** @return array<int,int> produit => cartons en stock libre (status 1) */
    public function freeStockCartons(): array
    {
        return array_map('intval', array_column($this->all('SELECT id_product, COUNT(*) AS n FROM carton_stock WHERE status = 1 GROUP BY id_product'), 'n', 'id_product'));
    }

    /** Cartons physiquement au froid : en stock et palettisés pas encore expédiés. */
    public function freezerOccupancy(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM carton_stock WHERE status IN (1, 2)')->fetchColumn();
    }

    /** @return array<int,float> article => quantité des OF MTS pas encore terminés (planifiés après échéance : plus comptés, sauf en cours) */
    public function openMtsOrders(): array
    {
        $rows = $this->all("SELECT id_item, SUM(quantity) AS q FROM tfb_prod_order WHERE origin = 'MTS' AND (status = 'in_progress' OR (status IN ('planned', 'released') AND due_at >= CURDATE())) GROUP BY id_item");
        return array_map('floatval', array_column($rows, 'q', 'id_item'));
    }

    /**
     * Arbre des recettes de l'ERP : enfants de chaque recette (quantité dans l'unité de l'enfant,
     * pour une fournée du parent), unité et rendement de chaque recette, pertes saisies (P2).
     * @return array{children:array<int,list<array{0:int,1:float}>>, meta:array<int,array{unit:string,yield:float}>, loss:array<string,float>}
     */
    public function recipeGraph(): array
    {
        $children = [];
        foreach ($this->all('SELECT parent_recipe_id, child_recipe_id, quantity FROM product_recipe_connection WHERE child_recipe_id IS NOT NULL') as $c) {
            $children[(int) $c['parent_recipe_id']][] = [(int) $c['child_recipe_id'], (float) $c['quantity']];
        }
        $meta = [];
        foreach ($this->all('SELECT pr.id, u.name AS unit, pr.yield_quantity FROM product_recipe pr LEFT JOIN unit u ON u.id = pr.id_unit') as $r) {
            $meta[(int) $r['id']] = ['unit' => (string) $r['unit'], 'yield' => (float) $r['yield_quantity']];
        }
        $loss = [];
        foreach ($this->all("SELECT parent_recipe_id, child_id, loss_pct FROM tfb_prod_bom_loss WHERE child_kind = 'recipe'") as $l) {
            $loss[$l['parent_recipe_id'] . ':' . $l['child_id']] = (float) $l['loss_pct'];
        }
        return ['children' => $children, 'meta' => $meta, 'loss' => $loss];
    }

    /** @return array<int,list<array>> article => étapes de gamme */
    public function routings(): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM tfb_prod_routing_step ORDER BY id_item, step_no') as $s) $out[(int) $s['id_item']][] = $s;
        return $out;
    }

    public function plans(?string $status): array
    {
        return $this->all(
            'SELECT p.*, (SELECT COUNT(*) FROM tfb_prod_plan_line l WHERE l.id_plan = p.id) AS line_count,
                    (SELECT COALESCE(SUM(l.standard_hours), 0) FROM tfb_prod_plan_line l WHERE l.id_plan = p.id) AS standard_hours,
                    u.displayed_name AS created_by_name, v.displayed_name AS validated_by_name
             FROM tfb_prod_plan p LEFT JOIN user u ON u.id = p.created_by LEFT JOIN user v ON v.id = p.validated_by'
            . ($status !== null ? ' WHERE p.status = ?' : '') . ' ORDER BY p.period_start DESC, p.version DESC, p.id DESC LIMIT 100',
            $status !== null ? [$status] : []
        );
    }

    public function nextVersion(string $start, string $end): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM tfb_prod_plan WHERE period_start = ? AND period_end = ?');
        $stmt->execute([$start, $end]);
        return (int) $stmt->fetchColumn();
    }

    public function lines(int $planId): array
    {
        return $this->all(
            'SELECT l.*, i.item_type, i.base_unit, COALESCE(fp.name, pr.name) AS name, fp.ppc, s.mode, s.id_primary_group, s.stock_max, s.never_frozen, s.batch_multiple
             FROM tfb_prod_plan_line l
             JOIN tfb_prod_item i ON i.id = l.id_item
             LEFT JOIN tfb_prod_product_settings s ON s.id_item = i.id
             LEFT JOIN final_product fp ON fp.id = i.id_final_product
             LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             WHERE l.id_plan = ? ORDER BY l.due_date, s.id_primary_group, name',
            [$planId]
        );
    }

    /**
     * Surowce i opakowania planu : sztuki do produkcji ÷ sztuk w kartonie × receptura na karton
     * (receptura wypłaszczona : surowce półproduktów już w niej, więc linie półproduktów się nie liczą).
     */
    public function materials(int $planId): array
    {
        return $this->all(
            "SELECT rm.id, rm.name, u.name AS unit, rm.price_per_unit, COALESCE(c.id_group, 0) = 1 AS packaging,
                    SUM(l.qty_final / fp.ppc * fri.quantity) AS quantity, SUM(l.qty_final / fp.ppc * fri.quantity) * rm.price_per_unit AS value,
                    COUNT(DISTINCT i.id) AS products
             FROM tfb_prod_plan_line l
             JOIN tfb_prod_item i ON i.id = l.id_item AND i.item_type = 'product'
             JOIN final_product fp ON fp.id = i.id_final_product AND fp.ppc > 0
             JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             JOIN raw_material rm ON rm.id = fri.material_id
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN category_material c ON c.id = rm.id_category
             WHERE l.id_plan = ? AND l.qty_final > 0
             GROUP BY rm.id, rm.name, u.name, rm.price_per_unit, packaging
             ORDER BY packaging, value DESC, rm.name",
            [$planId]
        );
    }

    public function line(int $planId, int $lineId): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_plan_line WHERE id = ? AND id_plan = ?', [$lineId, $planId])[0] ?? null;
    }

    public function insertLine(int $planId, array $l): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_plan_line (id_plan, id_item, due_date, qty_mto, qty_mts, qty_semi, qty_fixed, qty_proposed, qty_final, batches, standard_hours)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$planId, $l['id_item'], $l['due_date'], $l['qty_mto'], $l['qty_mts'], $l['qty_semi'], $l['qty_fixed'] ?? 0, $l['qty_proposed'], $l['qty_final'], $l['batches'], $l['standard_hours']]);
        return (int) $this->db->lastInsertId();
    }

    public function updateLine(int $lineId, float $qtyFinal, int $batches, float $hours, ?int $userId, ?string $reason): void
    {
        $this->db->prepare('UPDATE tfb_prod_plan_line SET qty_final = ?, batches = ?, standard_hours = ?, adjusted_by = ?, adjusted_at = NOW(), adjust_reason = ? WHERE id = ?')
            ->execute([$qtyFinal, $batches, $hours, $userId, $reason, $lineId]);
    }

    public function nextOrderCode(string $day): string
    {
        $prefix = 'OF-' . str_replace('-', '', $day) . '-';
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tfb_prod_order WHERE code LIKE ?');
        $stmt->execute([$prefix . '%']);
        return sprintf('%s%03d', $prefix, (int) $stmt->fetchColumn() + 1);
    }

    public function insertOrder(array $o): int
    {
        $this->db->prepare('INSERT INTO tfb_prod_order (code, id_plan, id_item, quantity, origin, id_parent_order, due_at, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$o['code'], $o['id_plan'], $o['id_item'], $o['quantity'], $o['origin'], $o['id_parent_order'] ?? null, $o['due_at'], $o['priority'] ?? 100, 'planned']);
        return (int) $this->db->lastInsertId();
    }

    public function insertOrderDemand(int $orderId, int $customerOrderId, int $finalProductId, float $qty): void
    {
        $this->db->prepare('INSERT INTO tfb_prod_order_demand (id_order, id_customer_order, id_final_product, quantity) VALUES (?, ?, ?, ?)')
            ->execute([$orderId, $customerOrderId, $finalProductId, $qty]);
    }

    public function orders(int $planId): array
    {
        return $this->all(
            'SELECT o.*, COALESCE(fp.name, pr.name) AS name, i.base_unit,
                    (SELECT COUNT(*) FROM tfb_prod_order_demand d WHERE d.id_order = o.id) AS demand_count
             FROM tfb_prod_order o JOIN tfb_prod_item i ON i.id = o.id_item
             LEFT JOIN final_product fp ON fp.id = i.id_final_product LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
             WHERE o.id_plan = ? ORDER BY o.due_at, o.origin, o.id',
            [$planId]
        );
    }

    /** Plans validés ou figés qui recouvrent une période (un seul plan actif par jour). */
    public function overlappingActive(string $start, string $end, int $exceptId): array
    {
        return $this->all("SELECT id, period_start, period_end, status FROM tfb_prod_plan WHERE status IN ('validated', 'frozen') AND id <> ?
                           AND period_start <= ? AND period_end >= ?", [$exceptId, $end, $start]);
    }
}
