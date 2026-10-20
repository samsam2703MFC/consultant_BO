<?php
namespace App\Repositories\Dashboard;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Lectures de la page « Rentowność » : ventes WDT par groupe (dział produkcyjny), coût du
 * personnel, coûts du groupe et coûts communs, tels que l'ERP les enregistre déjà par mois
 * (cost_month_*), recettes aplaties et historique des prix des matières (achats du mois).
 * Rien n'est recopié : tout est relu à chaque affichage.
 */
final class ProfitabilityRepository extends WorkshopRepository
{
    protected string $table = 'tfb_section_target';
    protected array $writable = ['staff_pct_target', 'margin_pct_target', 'bonus_pct', 'is_shared', 'updated_by'];

    public function sections(): array
    {
        return $this->all(
            'SELECT ps.id, ps.name, ps.depreciation, ps.water_cost, ps.power_supply_cost, ps.fuel_cost, ps.other_cost,
                    t.staff_pct_target, t.margin_pct_target, t.bonus_pct, COALESCE(t.is_shared, 0) AS is_shared
             FROM production_sections ps LEFT JOIN tfb_section_target t ON t.id_section = ps.id
             ORDER BY ps.id'
        );
    }

    /** Ventes (EUR), coût matières (PLN) et cartons par groupe et par mois, sur [from, to]. */
    public function sales(string $from, string $to, float $eurRate): array
    {
        return $this->all(
            "SELECT DATE_FORMAT(w.established_date, '%Y-%m') AS ym, fp.id_section,
                    SUM(CASE WHEN UPPER(w.currency) = 'PLN' THEN p.quantity * p.price_carton / :rate1 ELSE p.quantity * p.price_carton END) AS revenue_eur,
                    SUM(p.quantity * COALESCE(fp.pre_calculated_raw_price_per_carton, 0)) AS materials_pln,
                    SUM(p.quantity) AS cartons
             FROM document_wdt w
             JOIN document_wdt_product p ON p.id_document_wdt = w.id
             JOIN final_product fp ON fp.id = p.id_product
             WHERE w.established_date BETWEEN :from AND :to
             GROUP BY ym, fp.id_section",
            ['rate1' => $eurRate > 0 ? $eurRate : 1, 'from' => $from, 'to' => $to]
        );
    }

    /** Cartons vendus (WDT) par mois, groupe et produit, avec la recette et le coût matières de la fiche produit (PLN). */
    public function soldProducts(string $from, string $to): array
    {
        return $this->all(
            "SELECT DATE_FORMAT(w.established_date, '%Y-%m') AS ym, fp.id_section, fp.id AS product_id, fp.name AS product, fp.id_recipe,
                    SUM(p.quantity) AS cartons, COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS card_cost
             FROM document_wdt w
             JOIN document_wdt_product p ON p.id_document_wdt = w.id
             JOIN final_product fp ON fp.id = p.id_product
             WHERE w.established_date BETWEEN :from AND :to
             GROUP BY ym, fp.id_section, fp.id, fp.name, fp.id_recipe, fp.pre_calculated_raw_price_per_carton",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Recettes aplaties (ingrédients et emballages d'un carton, sous-recettes comprises). */
    public function recipeIngredients(array $recipeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds))));
        if (!$ids) return [];
        $in = implode(', ', array_fill(0, count($ids), '?'));
        return $this->all("SELECT recipe_id, material_id, quantity FROM flattened_recipe_ingredient WHERE recipe_id IN ({$in})", $ids);
    }

    /** Matières : nom, unité, catégorie (NULL si la catégorie n'existe plus), fournisseur actuel, prix unitaire actuel (PLN). */
    public function materialCatalog(): array
    {
        return $this->all(
            'SELECT m.id, m.name, u.name AS unit, m.id_category, c.name AS category, s.name AS supplier, m.price_per_unit
             FROM raw_material m
             LEFT JOIN unit u ON u.id = m.id_unit
             LEFT JOIN category_material c ON c.id = m.id_category
             LEFT JOIN supplier s ON s.id = m.id_current_supplier'
        );
    }

    /** Historique des prix : prix unitaire et fournisseur à chaque changement, du plus ancien au plus récent. */
    public function priceHistory(): array
    {
        return $this->all(
            'SELECT h.id_raw_material AS material_id, h.pack_price / h.package_size_in_base_unit AS unit_price, h.timestamp AS changed_at, s.name AS supplier
             FROM raw_material_price_changelog h
             LEFT JOIN supplier s ON s.id = h.id_supplier
             WHERE h.package_size_in_base_unit > 0
             ORDER BY h.timestamp, h.id_raw_material'
        );
    }

    /** Commandes pas encore expédiées (sans WDT) prévues entre deux dates, par groupe (EUR). */
    public function openOrders(string $from, string $to): array
    {
        return $this->all(
            'SELECT fp.id_section, SUM(cop.quantity_carton * cop.purchase_price) AS value_eur
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             WHERE co.id_wdt IS NULL AND co.status < 5 AND COALESCE(co.is_freebie, 0) = 0
               AND co.expected_shipping_date BETWEEN :from AND :to
             GROUP BY fp.id_section',
            ['from' => $from, 'to' => $to]
        );
    }

    /** @return array<string,int> 'YYYY-MM' => id du mois de coûts */
    public function costMonths(): array
    {
        $out = [];
        foreach ($this->all('SELECT id, year, month FROM cost_month ORDER BY year, month') as $m) {
            $out[sprintf('%04d-%02d', $m['year'], $m['month'])] = (int) $m['id'];
        }
        return $out;
    }

    /** @return array<int,array<int,float>> mois de coûts => groupe => salaires (PLN) */
    public function staff(): array
    {
        $out = [];
        foreach ($this->all('SELECT month_id, section_id, SUM(monthly_cost) AS cost FROM cost_month_employee WHERE is_included = 1 GROUP BY month_id, section_id') as $r) {
            $out[(int) $r['month_id']][(int) $r['section_id']] = (float) $r['cost'];
        }
        return $out;
    }

    /** @return array<int,array<int,float>> mois de coûts => groupe => médias répartis (PLN) */
    public function media(): array
    {
        $out = [];
        $rows = $this->all(
            'SELECT csa.cost_month_id, csa.section_id, SUM(cmm.amount * csa.allocation_percent / 100) AS cost
             FROM cost_media_section_allocation csa
             JOIN cost_media_month cmm ON cmm.cost_month_id = csa.cost_month_id AND cmm.media_type_id = csa.media_type_id
             JOIN cost_media_type cmt ON cmt.id = csa.media_type_id AND cmt.is_active = 1
             GROUP BY csa.cost_month_id, csa.section_id'
        );
        foreach ($rows as $r) {
            $out[(int) $r['cost_month_id']][(int) $r['section_id']] = (float) $r['cost'];
        }
        return $out;
    }

    /** @return array<int,float> mois de coûts => coûts communs de l'entreprise (PLN) */
    public function fixedCosts(): array
    {
        return array_map('floatval', array_column($this->all('SELECT month_id, SUM(amount) AS total FROM cost_month_fixed_cost GROUP BY month_id'), 'total', 'month_id'));
    }

    public function eurRate(): float
    {
        return (float) ($this->db->query('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    public function lastSaleDate(): ?string
    {
        return $this->db->query('SELECT MAX(established_date) FROM document_wdt')->fetchColumn() ?: null;
    }

    public function saveTarget(int $sectionId, array $data): void
    {
        $data = $this->filter($data);
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO tfb_section_target (id_section, %s) VALUES (:id_section, %s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(fn($c) => ':' . $c, $columns)),
            implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns))
        );
        $this->db->prepare($sql)->execute(['id_section' => $sectionId] + $data);
    }

    public function sectionExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM production_sections WHERE id = ?', [$id]);
    }
}
