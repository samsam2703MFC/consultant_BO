<?php
namespace App\Repositories\Dashboard;

use App\Domain\Client\Order\OpenOrders;

use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Workshop\WorkshopRepository;

/**
 * Lectures de la page « Rentowność » : ventes WDT par groupe (dział produkcyjny), coût du
 * personnel, coûts du groupe et coûts communs, tels que l'ERP les enregistre déjà par mois
 * (cost_month_*), recettes aplaties et historique des prix des matières (achats du mois).
 * Rien n'est recopié : tout est relu à chaque affichage.
 */
final class ProfitabilityRepository extends WorkshopRepository
{
    protected string $table = 'n_section_target';
    protected array $writable = ['staff_pct_target', 'margin_pct_target', 'bonus_pct', 'is_shared', 'updated_by'];

    public function sections(): array
    {
        return $this->all(
            'SELECT ps.id, ps.name, ps.depreciation, ps.water_cost, ps.power_supply_cost, ps.fuel_cost, ps.other_cost,
                    t.staff_pct_target, t.margin_pct_target, t.bonus_pct, COALESCE(t.is_shared, 0) AS is_shared
             FROM production_sections ps LEFT JOIN n_section_target t ON t.id_section = ps.id
             ORDER BY ps.id'
        );
    }

    /** Ventes (EUR), coût matières (PLN) et cartons par groupe et par mois, sur [from, to] (définition commune des ventes WDT). */
    public function sales(string $from, string $to, float $eurRate): array
    {
        return $this->all(
            "SELECT s.ym, fp.id_section,
                    SUM(s.value_eur) AS revenue_eur,
                    SUM(s.cartons * COALESCE(fp.pre_calculated_raw_price_per_carton, 0)) AS materials_pln,
                    SUM(s.cartons) AS cartons
             FROM (" . WdtSalesRepository::linesSql() . ") s
             LEFT JOIN final_product fp ON fp.id = s.id_product
             WHERE s.sale_date BETWEEN :from AND :to
             GROUP BY s.ym, fp.id_section",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Ventes WDT par jour et groupe (EUR, cartons) — rentabilité des groupes par semaine. */
    public function dailySales(string $from, string $to): array
    {
        return $this->all(
            "SELECT s.sale_date AS day, s.ym, fp.id_section, SUM(s.value_eur) AS revenue_eur, SUM(s.cartons) AS cartons
             FROM (" . WdtSalesRepository::linesSql() . ") s
             LEFT JOIN final_product fp ON fp.id = s.id_product
             WHERE s.sale_date BETWEEN :from AND :to
             GROUP BY s.sale_date, s.ym, fp.id_section",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Prix moyen de vente d'un carton par produit (EUR) sur [from, to] — valeur de la production. */
    public function cartonPrices(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all(
            "SELECT s.id_product, SUM(s.value_eur) / NULLIF(SUM(s.cartons), 0) AS price
             FROM (" . WdtSalesRepository::linesSql() . ") s
             WHERE s.sale_date BETWEEN :from AND :to AND s.cartons > 0
             GROUP BY s.id_product",
            ['from' => $from, 'to' => $to]
        ) as $r) if ($r['price'] !== null) $out[(int) $r['id_product']] = (float) $r['price'];
        return $out;
    }

    /** Cartons produits (étiquette = entrée en stock) par jour, produit et groupe. */
    public function producedDaily(string $from, string $to): array
    {
        return $this->all(
            'SELECT cs.date_add AS day, cs.id_product, fp.id_section, COUNT(*) AS cartons, COALESCE(fp.ppc, 0) AS ppc
             FROM carton_stock cs LEFT JOIN final_product fp ON fp.id = cs.id_product
             WHERE cs.date_add BETWEEN :from AND :to
             GROUP BY cs.date_add, cs.id_product, fp.id_section, fp.ppc',
            ['from' => $from, 'to' => $to]
        );
    }

    /** Groupes de production (tablettes, pointage) et leur groupe de produits (dział). */
    public function productionGroups(): array
    {
        return $this->all('SELECT id, name_pl, name_fr, color, id_section, active FROM n_prod_group ORDER BY sort_order, id');
    }

    /** Cobots actifs (robots en mode collaboratif) par groupe de production. */
    public function cobots(): array
    {
        return $this->all("SELECT id, id_group, name_pl, operator_share FROM n_prod_workstation
                           WHERE type = 'robot' AND robot_mode = 'collaboratif' AND active = 1 ORDER BY name_pl");
    }

    /** Articles de production des produits finis (gamme) : id article => produit. */
    public function productItems(): array
    {
        $out = [];
        foreach ($this->all("SELECT id, id_final_product FROM n_prod_item WHERE item_type = 'product' AND id_final_product IS NOT NULL") as $r) {
            $out[(int) $r['id_final_product']] = (int) $r['id'];
        }
        return $out;
    }

    /** Cartons vendus (WDT) par mois, groupe et produit, avec la recette et le coût matières de la fiche produit (PLN). */
    public function soldProducts(string $from, string $to): array
    {
        return $this->all(
            "SELECT s.ym, fp.id_section, s.id_product AS product_id, COALESCE(fp.name, MAX(s.line_name)) AS product, fp.id_recipe,
                    SUM(s.cartons) AS cartons, COALESCE(fp.pre_calculated_raw_price_per_carton, 0) AS card_cost
             FROM (" . WdtSalesRepository::linesSql() . ") s
             LEFT JOIN final_product fp ON fp.id = s.id_product
             WHERE s.sale_date BETWEEN :from AND :to
             GROUP BY s.ym, fp.id_section, s.id_product, fp.name, fp.id_recipe, fp.pre_calculated_raw_price_per_carton",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Produits vendus (WDT) sur [from, to] : groupe, catégorie, cartons, pièces / carton, poids (g) et ventes (EUR). */
    public function producedProducts(string $from, string $to, float $eurRate): array
    {
        return $this->all(
            "SELECT fp.id_section, s.id_product AS product_id, COALESCE(fp.name, MAX(s.line_name), 'Nieznany produkt') AS product, fp.id_category, cp.name AS category,
                    COALESCE(fp.ppc, 0) AS ppc, COALESCE(fp.weight, 0) AS weight, SUM(s.cartons) AS cartons, SUM(s.value_eur) AS revenue_eur
             FROM (" . WdtSalesRepository::linesSql() . ") s
             LEFT JOIN final_product fp ON fp.id = s.id_product
             LEFT JOIN category_product cp ON cp.id = fp.id_category
             WHERE s.sale_date BETWEEN :from AND :to
             GROUP BY fp.id_section, s.id_product, fp.name, fp.id_category, cp.name, fp.ppc, fp.weight",
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

    /** Commandes ouvertes (OpenOrders, sans gratuits) prévues entre deux dates, par groupe (EUR) — retards du mois compris. */
    public function openOrders(string $from, string $to): array
    {
        return $this->all(
            'SELECT fp.id_section, SUM(cop.quantity_carton * cop.purchase_price) AS value_eur
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             WHERE ' . OpenOrders::quantitySql('co') . '
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

    /** @return array<int,array<int,int>> mois de coûts => groupe => personnes payées ce mois (lignes incluses) */
    public function headcount(): array
    {
        $out = [];
        foreach ($this->all('SELECT month_id, section_id, COUNT(*) AS people FROM cost_month_employee WHERE is_included = 1 GROUP BY month_id, section_id') as $r) {
            $out[(int) $r['month_id']][(int) $r['section_id']] = (int) $r['people'];
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
            'INSERT INTO n_section_target (id_section, %s) VALUES (:id_section, %s) ON DUPLICATE KEY UPDATE %s',
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
