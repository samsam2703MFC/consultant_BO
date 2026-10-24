<?php
namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Lectures pour l'analyse des coûts de matières premières : prix courant (raw_material),
 * historique (raw_material_price_changelog), consommation déduite des commandes et des
 * recettes aplaties (une recette de produit fini = 1 carton), offres en cours, NC.
 */
final class MaterialCostRepository extends WorkshopRepository
{
    protected string $table = 'raw_material';

    public function materials(): array
    {
        return $this->all(
            'SELECT rm.id, rm.name, rm.name_fr, rm.price_per_unit, rm.package_size_in_base_unit, rm.is_part_of_package,
                    rm.id_unit, u.name AS unit, rm.id_current_supplier, s.name AS supplier_name, cm.name AS category
             FROM raw_material rm
             LEFT JOIN unit u ON u.id = rm.id_unit
             LEFT JOIN supplier s ON s.id = rm.id_current_supplier
             LEFT JOIN category_material cm ON cm.id = rm.id_category
             ORDER BY rm.name'
        );
    }

    public function material(int $id): ?array
    {
        $rows = $this->all(
            'SELECT rm.*, u.name AS unit, s.name AS supplier_name, s.email AS supplier_email
             FROM raw_material rm LEFT JOIN unit u ON u.id = rm.id_unit LEFT JOIN supplier s ON s.id = rm.id_current_supplier
             WHERE rm.id = ?',
            [$id]
        );
        return $rows[0] ?? null;
    }

    /** @return array<int,float> matière => quantité consommée (unité de base) sur la période */
    public function consumption(int $months): array
    {
        $rows = $this->all(
            'SELECT fri.material_id, SUM(cop.quantity_carton * fri.quantity) AS qty
             FROM customer_order co
             JOIN customer_order_product cop ON cop.id_order = co.id
             JOIN final_product fp ON fp.id = cop.id_product
             JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
             WHERE co.expected_shipping_date >= CURDATE() - INTERVAL ? MONTH
             GROUP BY fri.material_id',
            [$months]
        );
        return array_map('floatval', array_column($rows, 'qty', 'material_id'));
    }

    /** Historique des prix (prix par unité de base), du plus ancien au plus récent. */
    public function priceHistory(?int $materialId = null): array
    {
        $where = $materialId === null ? '' : 'WHERE c.id_raw_material = ?';
        return $this->all(
            "SELECT c.id_raw_material, c.id_supplier, s.name AS supplier_name, c.pack_price, c.package_size_in_base_unit,
                    c.pack_price / NULLIF(c.package_size_in_base_unit, 0) AS price_per_unit, c.timestamp
             FROM raw_material_price_changelog c
             LEFT JOIN supplier s ON s.id = c.id_supplier
             {$where}
             ORDER BY c.timestamp, c.id_raw_material",
            $materialId === null ? [] : [$materialId]
        );
    }

    /** Offres reçues et valables aujourd'hui. */
    public function validOffers(?int $materialId = null): array
    {
        $where = $materialId === null ? '' : 'AND o.id_raw_material = ?';
        return $this->all(
            "SELECT o.*, s.name AS supplier_name
             FROM n_mp_offer o LEFT JOIN supplier s ON s.id = o.id_supplier
             WHERE o.status = 'received' AND o.valid_from <= CURDATE() AND (o.valid_to IS NULL OR o.valid_to >= CURDATE()) {$where}
             ORDER BY o.price_per_base",
            $materialId === null ? [] : [$materialId]
        );
    }

    /** @return array<int,int> fournisseur => nombre de NC sur la période */
    public function supplierNcCounts(int $months): array
    {
        $rows = $this->all('SELECT id_supplier, COUNT(*) AS n FROM n_nc WHERE id_supplier IS NOT NULL AND reported_at >= CURDATE() - INTERVAL ? MONTH GROUP BY id_supplier', [$months]);
        return array_map('intval', array_column($rows, 'n', 'id_supplier'));
    }

    /** Produits actifs qui contiennent la matière : quantité par carton et cartons commandés sur la période. */
    public function productsUsing(int $materialId, int $months): array
    {
        return $this->all(
            'SELECT fp.id, fp.name, fp.ppc, fri.quantity AS qty_per_carton, pr.precalculated_material_cost,
                    COALESCE(sold.cartons, 0) AS cartons
             FROM flattened_recipe_ingredient fri
             JOIN final_product fp ON fp.id_recipe = fri.recipe_id AND fp.active = 1
             JOIN product_recipe pr ON pr.id = fri.recipe_id
             LEFT JOIN (SELECT cop.id_product, SUM(cop.quantity_carton) AS cartons
                        FROM customer_order co JOIN customer_order_product cop ON cop.id_order = co.id
                        WHERE co.expected_shipping_date >= CURDATE() - INTERVAL ? MONTH
                        GROUP BY cop.id_product) sold ON sold.id_product = fp.id
             WHERE fri.material_id = ?
             ORDER BY cartons DESC, fp.name',
            [$months, $materialId]
        );
    }

    public function suppliers(): array
    {
        return $this->all('SELECT id, name, email, phone, is_active FROM supplier ORDER BY is_active DESC, name');
    }

    public function companyName(): string
    {
        return (string) ($this->db->query('SELECT wdt_company_name FROM erp_company_data ORDER BY id LIMIT 1')->fetchColumn() ?: '');
    }
}
