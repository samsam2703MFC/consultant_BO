<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Domain\Client\Order\OpenOrders;
use App\Domain\Stock\ToProduce;

use App\Repositories\Analysis\WdtSalesRepository;

/**
 * Articles planifiables (tfb_prod_item) : un produit fini ou un semi-fini de l'ERP, avec ses
 * réglages de production, sa gamme et les pertes de sa nomenclature. Nom, colisage et
 * nomenclature sont relus dans les tables de l'ERP, jamais recopiés.
 */
final class ItemRepository extends WorkshopRepository
{
    protected string $table = 'tfb_prod_item';
    protected array $writable = ['item_type', 'id_final_product', 'id_recipe', 'base_unit'];

    private const SETTINGS = ['mode', 'never_frozen', 'stock_min', 'stock_max', 'coverage_days', 'batch_min', 'batch_multiple', 'batch_people', 'batch_minutes',
        'shelf_life_workshop_days', 'shelf_life_shop_days', 'id_primary_group', 'status', 'wizard_step', 'validated_at', 'validated_by', 'updated_by', 'bake_state', 'stock_calc_at'];
    private const STEP = ['step_no', 'name_pl', 'name_fr', 'id_workstation', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass',
        'operators_required', 'min_level_order', 'delay_after_s', 'delay_id_workstation', 'cleaning_required', 'cleaning_time_s', 'temperature_c'];
    private const LOGISTICS = ['carton_length_mm', 'carton_width_mm', 'carton_height_mm', 'carton_tare_g', 'carton_type', 'carton_ean', 'pallet_type', 'storage', 'updated_by'];
    /** Champs logistiques de l'ERP (final_product) modifiables depuis la fiche. */
    private const ERP_LOGISTICS = ['weight', 'ppc', 'ppb', 'bpc', 'cpl', 'lpp', 'cpp'];
    private const ALT = ['id_workstation', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'priority'];

    private const SELECT = "SELECT i.id, i.item_type, i.id_final_product, i.id_recipe, i.base_unit, i.created_at,
            COALESCE(fp.name, pr.name) AS name, fp.ppc, fp.weight, fp.id_section, fp.active AS erp_active, fp.id_recipe AS product_recipe_id,
            fp.ppb, fp.bpc, fp.cpl, fp.lpp, fp.cpp, fp.id_category, fp.label_type, fp.shelf_life, fp.stock_minimum,
            s.mode, s.never_frozen, s.bake_state, s.stock_min, s.stock_max, s.coverage_days, s.batch_min, s.batch_multiple, s.batch_people, s.batch_minutes, s.stock_calc_at,
            s.shelf_life_workshop_days, s.shelf_life_shop_days, s.id_primary_group, s.status, s.wizard_step,
            s.validated_at, s.validated_by, s.updated_at AS settings_updated_at,
            lg.carton_length_mm, lg.carton_width_mm, lg.carton_height_mm, lg.carton_tare_g, lg.carton_type, lg.carton_ean, lg.pallet_type, lg.storage,
            (SELECT COUNT(*) FROM tfb_prod_routing_step r WHERE r.id_item = i.id) AS step_count
        FROM tfb_prod_item i
        LEFT JOIN final_product fp ON fp.id = i.id_final_product
        LEFT JOIN product_recipe pr ON pr.id = i.id_recipe
        LEFT JOIN tfb_prod_product_settings s ON s.id_item = i.id
        LEFT JOIN tfb_prod_item_logistics lg ON lg.id_item = i.id";

    /**
     * Karta dla każdego aktywnego produktu ERP bez karty (na zamówienie, w przygotowaniu, grupa domyślna jego działu) :
     * zamówienia wszystkich produktów trafiają do produkcji, nie tylko produktów z kartą. Zwraca liczbę nowych kart.
     */
    public function createMissingCards(): int
    {
        $created = (int) $this->db->exec("INSERT IGNORE INTO tfb_prod_item (item_type, id_final_product, base_unit)
            SELECT 'product', fp.id, 'szt' FROM final_product fp
            WHERE fp.active = 1 AND NOT EXISTS (SELECT 1 FROM tfb_prod_item i WHERE i.id_final_product = fp.id)");
        if ($created > 0) {
            $this->db->exec("INSERT IGNORE INTO tfb_prod_product_settings (id_item, mode, never_frozen, id_primary_group, status, wizard_step)
                SELECT i.id, 'MTO', 0, (SELECT MIN(g.id) FROM tfb_prod_group g WHERE g.active = 1 AND g.id_section = fp.id_section), 'draft', 1
                FROM tfb_prod_item i JOIN final_product fp ON fp.id = i.id_final_product
                WHERE NOT EXISTS (SELECT 1 FROM tfb_prod_product_settings s WHERE s.id_item = i.id)");
        }
        return $created;
    }

    public function list(?string $type, ?int $groupId, ?string $status, ?string $mode, ?string $search, ?int $finalProductId = null): array
    {
        $where = [];
        $params = [];
        if ($finalProductId !== null) { $where[] = 'i.id_final_product = ?'; $params[] = $finalProductId; }
        if ($type !== null) { $where[] = 'i.item_type = ?'; $params[] = $type; }
        if ($groupId !== null) { $where[] = 's.id_primary_group = ?'; $params[] = $groupId; }
        if ($status !== null) { $where[] = "COALESCE(s.status, 'draft') = ?"; $params[] = $status; }
        if ($mode !== null) { $where[] = 's.mode = ?'; $params[] = $mode; }
        if ($search !== null && $search !== '') { $where[] = 'COALESCE(fp.name, pr.name) LIKE ?'; $params[] = '%' . $search . '%'; }
        return $this->all(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, i.id', $params);
    }

    public function detail(int $id): ?array
    {
        return $this->all(self::SELECT . ' WHERE i.id = ?', [$id])[0] ?? null;
    }

    public function idForProduct(int $finalProductId): ?int
    {
        $id = $this->all('SELECT id FROM tfb_prod_item WHERE id_final_product = ?', [$finalProductId])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function idForRecipe(int $recipeId): ?int
    {
        $id = $this->all('SELECT id FROM tfb_prod_item WHERE id_recipe = ?', [$recipeId])[0]['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function erpProduct(int $id): ?array
    {
        return $this->all('SELECT id, name, ppc, weight, id_section, id_recipe, active, shelf_life FROM final_product WHERE id = ?', [$id])[0] ?? null;
    }

    public function erpRecipe(int $id): ?array
    {
        return $this->all('SELECT id, name, id_type, is_subrecipe, yield_quantity FROM product_recipe WHERE id = ?', [$id])[0] ?? null;
    }

    /** Produits actifs et semi-finis de l'ERP pas encore planifiables (étape 1 de l'assistant). */
    public function candidates(string $type, ?string $search, int $limit): array
    {
        $like = '%' . ($search ?? '') . '%';
        if ($type === 'product') {
            return $this->all(
                'SELECT fp.id, fp.name, fp.ppc, fp.weight, fp.id_section FROM final_product fp
                 WHERE fp.active = 1 AND fp.name LIKE ? AND NOT EXISTS (SELECT 1 FROM tfb_prod_item i WHERE i.id_final_product = fp.id)
                 ORDER BY fp.name LIMIT ' . $limit,
                [$like]
            );
        }
        return $this->all(
            'SELECT pr.id, pr.name, pr.yield_quantity FROM product_recipe pr
             WHERE pr.is_subrecipe = 1 AND pr.name LIKE ? AND NOT EXISTS (SELECT 1 FROM tfb_prod_item i WHERE i.id_recipe = pr.id)
             ORDER BY pr.name LIMIT ' . $limit,
            [$like]
        );
    }

    public function saveSettings(int $itemId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::SETTINGS));
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_prod_product_settings (id_item%s) VALUES (?%s) ON DUPLICATE KEY UPDATE %s',
            $columns ? ', ' . implode(', ', $columns) : '',
            str_repeat(', ?', count($columns)),
            $columns ? implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns)) : 'id_item = id_item'
        );
        $this->db->prepare($sql)->execute([$itemId, ...array_map(fn($v) => is_bool($v) ? (int) $v : $v, array_values($data))]);
    }

    public function saveLogistics(int $itemId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::LOGISTICS));
        if (!$data) return;
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_prod_item_logistics (id_item, %s) VALUES (?%s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns), str_repeat(', ?', count($columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute([$itemId, ...array_values($data)]);
    }

    /** Pola logistyczne produktu w ERP (waga, sztuk / opakowanie / karton, kartony na warstwę i paletę). */
    /** Dane produktu ERP z kroku « Produkt » (nazwa, kategoria, dział, etykieta, przydatność, stan minimalny). */
    public function updateErpProduct(int $finalProductId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(['name', 'id_category', 'id_section', 'label_type', 'shelf_life', 'stock_minimum']));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE final_product SET {$set} WHERE id = ?")->execute([...array_values($data), $finalProductId]);
    }

    /** Kartony produktu wysłane (WDT bez duplikatów) w ostatnich $days dniach, bez dzisiaj. */
    public function shippedCartons(int $finalProductId, int $days): float
    {
        return (float) ($this->all(
            'SELECT COALESCE(SUM(s.cartons), 0) AS cartons FROM (' . WdtSalesRepository::linesSql() . ') s
             WHERE s.id_product = ? AND s.sale_date >= CURDATE() - INTERVAL ? DAY AND s.sale_date < CURDATE()',
            [$finalProductId, $days]
        )[0]['cartons'] ?? 0);
    }

    /**
     * Zapasy wg kategorii (lista kart) : produkty z trybem, stanami, kartonami na paletę i sprzedażą WDT
     * z ostatnich $days dni (bez dokumentów zastąpionych) — kartony i liczba sklepów (klientów), które kupiły.
     */
    /** @param string $erp 'active' (domyślnie : aktywne w ERP i produkty z kartą), 'inactive' (nieaktywne w ERP) albo 'all' */
    public function stockOverview(int $days, ?int $groupId, ?int $itemId = null, string $erp = 'active'): array
    {
        // Kurs EUR (WDT w PLN przeliczane jak w Rentowności) : średnia cena kartonu i koszt surowców w €.
        $rate = (float) ($this->all('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')[0]['exchange_rate_euro'] ?? 0) ?: 1.0;
        $params = [$rate, $days];
        // Wszystkie aktywne produkty ERP (bez karty : MTO, grupa z działu ERP) oraz karty produktów nieaktywnych.
        $where = match ($erp) {
            'inactive' => 'fp.active = 0',
            'all' => '1 = 1',
            default => '(i.id IS NOT NULL OR fp.active = 1)',
        };
        if ($groupId !== null) { $where .= ' AND COALESCE(s.id_primary_group, dg.id) = ?'; $params[] = $groupId; }
        if ($itemId !== null) { $where .= ' AND i.id = ?'; $params[] = $itemId; }
        return $this->all("SELECT i.id, fp.id AS id_final_product, fp.name, fp.id_category, cp.name AS category, fp.ppc, fp.cpp, fp.active AS erp_active, fp.workload_points, fp.id_recipe,
                                  s.mode, s.never_frozen, s.stock_min, s.stock_max, s.stock_calc_at, s.batch_multiple, COALESCE(s.id_primary_group, dg.id) AS id_primary_group, s.status,
                                  COALESCE(w.cartons, 0) AS shipped_cartons, COALESCE(w.shops, 0) AS shops, COALESCE(w.value_eur, 0) AS shipped_value_eur,
                                  fp.pre_calculated_raw_price_per_carton / ? AS material_cost_eur,
                                  COALESCE(cs.cartons, 0) AS free_cartons, COALESCE(cs.palletised, 0) AS palletised_cartons, COALESCE(oo.cartons, 0) AS ordered_cartons, COALESCE(mo.pieces, 0) AS in_production
                           FROM final_product fp
                           LEFT JOIN tfb_prod_item i ON i.id_final_product = fp.id AND i.item_type = 'product'
                           LEFT JOIN category_product cp ON cp.id = fp.id_category
                           LEFT JOIN tfb_prod_product_settings s ON s.id_item = i.id
                           LEFT JOIN (SELECT id_section, MIN(id) AS id FROM tfb_prod_group WHERE active = 1 AND id_section IS NOT NULL GROUP BY id_section) dg ON dg.id_section = fp.id_section
                           LEFT JOIN (SELECT sl.id_product, SUM(sl.cartons) AS cartons, COUNT(DISTINCT sl.id_customer) AS shops, SUM(sl.value_eur) AS value_eur
                                      FROM (" . WdtSalesRepository::linesSql() . ") sl
                                      WHERE sl.sale_date >= CURDATE() - INTERVAL ? DAY AND sl.sale_date < CURDATE()
                                      GROUP BY sl.id_product) w ON w.id_product = fp.id
                           LEFT JOIN (SELECT id_product, SUM(status = 1) AS cartons, SUM(status = 2) AS palletised FROM carton_stock WHERE status IN (" . OpenOrders::STOCK_STATUSES . ") GROUP BY id_product) cs ON cs.id_product = fp.id
                           LEFT JOIN (SELECT cop.id_product, SUM(cop.quantity_carton) AS cartons FROM customer_order co JOIN customer_order_product cop ON cop.id_order = co.id
                                      WHERE " . OpenOrders::quantitySql('co') . " GROUP BY cop.id_product) oo ON oo.id_product = fp.id
                           LEFT JOIN (SELECT id_item, SUM(quantity) AS pieces FROM tfb_prod_order WHERE " . ToProduce::IN_PRODUCTION_WHERE . " GROUP BY id_item) mo ON mo.id_item = i.id
                           WHERE {$where}
                           ORDER BY cp.name IS NULL, cp.name, fp.name, fp.id", $params);
    }

    /** Listy wyboru kroku « Produkt » : kategorie produktów i działy produkcji ERP. */
    public function erpOptions(): array
    {
        return [
            'categories' => $this->all('SELECT id, name FROM category_product ORDER BY name'),
            'sections' => $this->all('SELECT id, name FROM production_sections ORDER BY name'),
        ];
    }

    public function categoryExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM category_product WHERE id = ?', [$id]);
    }

    public function sectionExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM production_sections WHERE id = ?', [$id]);
    }

    public function updateErpLogistics(int $finalProductId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::ERP_LOGISTICS));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE final_product SET {$set} WHERE id = ?")->execute([...array_values($data), $finalProductId]);
    }

    public function steps(int $itemId): array
    {
        return $this->all(
            'SELECT r.*, w.code AS workstation_code, w.name_pl AS workstation_name_pl, w.name_fr AS workstation_name_fr, w.type AS workstation_type,
                    d.code AS delay_workstation_code, d.name_pl AS delay_workstation_name_pl, d.name_fr AS delay_workstation_name_fr
             FROM tfb_prod_routing_step r
             JOIN tfb_prod_workstation w ON w.id = r.id_workstation
             LEFT JOIN tfb_prod_workstation d ON d.id = r.delay_id_workstation
             WHERE r.id_item = ? ORDER BY r.step_no',
            [$itemId]
        );
    }

    public function step(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_prod_routing_step WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return array<int,list<array>> id étape => alternatives */
    public function alternatives(array $stepIds): array
    {
        if (!$stepIds) return [];
        $in = implode(', ', array_fill(0, count($stepIds), '?'));
        $out = [];
        foreach ($this->all(
            "SELECT a.*, w.code AS workstation_code, w.name_pl AS workstation_name_pl, w.name_fr AS workstation_name_fr
             FROM tfb_prod_routing_alternative a JOIN tfb_prod_workstation w ON w.id = a.id_workstation
             WHERE a.id_step IN ({$in}) ORDER BY a.priority, a.id",
            array_values($stepIds)
        ) as $row) {
            $out[(int) $row['id_step']][] = $row;
        }
        return $out;
    }

    /**
     * Remplace la gamme : étapes connues (id) mises à jour, nouvelles insérées, absentes supprimées
     * (avec leurs chronométrages). Les numéros sont d'abord décalés pour éviter les collisions.
     * @param list<array{id:?int, alternatives:list<array>}> $steps
     */
    public function replaceRouting(int $itemId, array $steps): void
    {
        $keep = array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $steps)));
        $params = [$itemId];
        $notIn = '';
        if ($keep) {
            $notIn = ' AND id NOT IN (' . implode(', ', array_fill(0, count($keep), '?')) . ')';
            $params = [...$params, ...$keep];
        }
        $this->db->prepare('DELETE FROM tfb_prod_routing_step WHERE id_item = ?' . $notIn)->execute($params);
        $this->db->prepare('UPDATE tfb_prod_routing_step SET step_no = step_no + 100000 WHERE id_item = ?')->execute([$itemId]);

        foreach ($steps as $step) {
            $data = array_intersect_key($step, array_flip(self::STEP));
            $data = array_map(fn($v) => is_bool($v) ? (int) $v : $v, $data);
            if (!empty($step['id'])) {
                $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
                $this->db->prepare("UPDATE tfb_prod_routing_step SET {$set} WHERE id = ? AND id_item = ?")->execute([...array_values($data), $step['id'], $itemId]);
                $stepId = (int) $step['id'];
            } else {
                $columns = array_keys($data);
                $this->db->prepare(sprintf('INSERT INTO tfb_prod_routing_step (id_item, %s) VALUES (?%s)', implode(', ', $columns), str_repeat(', ?', count($columns))))
                    ->execute([$itemId, ...array_values($data)]);
                $stepId = (int) $this->db->lastInsertId();
            }
            $this->db->prepare('DELETE FROM tfb_prod_routing_alternative WHERE id_step = ?')->execute([$stepId]);
            foreach ($step['alternatives'] ?? [] as $alt) {
                $alt = array_intersect_key($alt, array_flip(self::ALT));
                $columns = array_keys($alt);
                $this->db->prepare(sprintf('INSERT INTO tfb_prod_routing_alternative (id_step, %s) VALUES (?%s)', implode(', ', $columns), str_repeat(', ?', count($columns))))
                    ->execute([$stepId, ...array_values($alt)]);
            }
        }
    }

    public function updateStepTimes(int $stepId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(['fixed_time_s', 'variable_time_s']));
        if (!$data) return;
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
        $this->db->prepare("UPDATE tfb_prod_routing_step SET {$set} WHERE id = ?")->execute([...array_values($data), $stepId]);
    }

    /** Nomenclature directe d'une recette de l'ERP, avec le taux de perte ajouté par le module. */
    public function bom(int $recipeId): array
    {
        return $this->all(
            "SELECT CASE WHEN c.child_recipe_id IS NOT NULL THEN 'recipe' ELSE 'material' END AS child_kind,
                    COALESCE(c.child_recipe_id, c.child_ingredient_id) AS child_id,
                    COALESCE(sub.name, rm.name) AS name, c.quantity, u.name AS unit, COALESCE(l.loss_pct, 0) AS loss_pct
             FROM product_recipe_connection c
             LEFT JOIN product_recipe sub ON sub.id = c.child_recipe_id
             LEFT JOIN raw_material rm ON rm.id = c.child_ingredient_id
             LEFT JOIN unit u ON u.id = COALESCE(sub.id_unit, rm.id_unit)
             LEFT JOIN tfb_prod_bom_loss l ON l.parent_recipe_id = c.parent_recipe_id
                  AND l.child_kind = CASE WHEN c.child_recipe_id IS NOT NULL THEN 'recipe' ELSE 'material' END
                  AND l.child_id = COALESCE(c.child_recipe_id, c.child_ingredient_id)
             WHERE c.parent_recipe_id = ?
             ORDER BY child_kind DESC, name",
            [$recipeId]
        );
    }

    public function saveBomLoss(int $recipeId, string $kind, int $childId, float $lossPct, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO tfb_prod_bom_loss (parent_recipe_id, child_kind, child_id, loss_pct, updated_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE loss_pct = VALUES(loss_pct), updated_by = VALUES(updated_by)')
            ->execute([$recipeId, $kind, $childId, $lossPct, $userId]);
    }

    public function workstations(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id, type, active, capacity_per_pass, id_group FROM tfb_prod_workstation WHERE id IN ({$in})", array_values($ids)) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }
}
