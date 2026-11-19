<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

use App\Services\Material\MaterialLoss;

/**
 * [349] Asystent « Ceny z faktur zakupu » : dane do efektu nowej ceny surowca — produkty z tym surowcem w recepturze,
 * sprzedaż (kartony, 3 miesiące), ostatnia faktura i ceny innych dostawców. Tylko odczyt.
 */
final class InvoicePriceImpactRepository extends WorkshopRepository
{
    protected string $table = 'n_inv';

    /**
     * Produkty aktywne z surowcem w recepturze : ilość kupiona na karton (receptura ÷ (1 − odpad), jak zapotrzebowanie),
     * koszt surowców na karton, cena (EUR / karton), sztuki w kartonie, kartony zamówione w ostatnich $months miesiącach.
     */
    public function products(int $materialId, int $months = 3): array
    {
        return $this->all(
            'SELECT fp.id, fp.name, fp.ppc, fp.standard_price, fp.pre_calculated_raw_price_per_carton AS raw_cost,
                    SUM(fri.quantity) * ' . MaterialLoss::factorSql('rm') . ' AS qty_per_carton,
                    COALESCE(o.cartons, 0) AS cartons
             FROM flattened_recipe_ingredient fri
             JOIN final_product fp ON fp.id_recipe = fri.recipe_id AND fp.active = 1
             JOIN raw_material rm ON rm.id = fri.material_id
             LEFT JOIN (SELECT cop.id_product, SUM(cop.quantity_carton) AS cartons
                        FROM customer_order co JOIN customer_order_product cop ON cop.id_order = co.id
                        WHERE co.expected_shipping_date >= CURDATE() - INTERVAL ? MONTH
                        GROUP BY cop.id_product) o ON o.id_product = fp.id
             WHERE fri.material_id = ?
             GROUP BY fp.id, fp.name, fp.ppc, fp.standard_price, fp.pre_calculated_raw_price_per_carton, o.cartons, rm.waste_perc',
            [$months, $materialId]
        );
    }

    /** Ostatnia inna faktura z tym surowcem : cena na jednostkę bazową (netto ÷ ilość × przelicznik). */
    public function lastInvoicePrice(int $materialId, int $exceptInvoice): ?array
    {
        return $this->all(
            "SELECT i.id, i.number, i.issue_date, i.id_supplier, s.name AS supplier_name,
                    l.net_value / NULLIF(l.quantity * COALESCE(NULLIF(l.factor, 0), 1), 0) AS price
             FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv LEFT JOIN supplier s ON s.id = i.id_supplier
             WHERE l.id_raw_material = ? AND i.id <> ? AND i.status <> 'rejected' AND l.net_value IS NOT NULL AND l.quantity > 0
             ORDER BY COALESCE(i.issue_date, DATE(i.created_at)) DESC, i.id DESC LIMIT 1",
            [$materialId, $exceptInvoice]
        )[0] ?? null;
    }

    /** Najniższa cena innego dostawcy w ostatnich $days dniach (faktury i dziennik cen bazowych). */
    public function otherSupplierBest(int $materialId, ?int $supplierId, int $days = 90): ?array
    {
        $rows = $this->all(
            "SELECT * FROM (
                SELECT i.id_supplier, s.name AS supplier_name, COALESCE(i.issue_date, DATE(i.created_at)) AS day,
                       l.net_value / NULLIF(l.quantity * COALESCE(NULLIF(l.factor, 0), 1), 0) AS price
                FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv LEFT JOIN supplier s ON s.id = i.id_supplier
                WHERE l.id_raw_material = ? AND i.status <> 'rejected' AND l.net_value IS NOT NULL AND l.quantity > 0
                  AND COALESCE(i.issue_date, DATE(i.created_at)) >= CURDATE() - INTERVAL ? DAY
                UNION ALL
                SELECT c.id_supplier, s.name, DATE(c.timestamp), c.pack_price / NULLIF(c.package_size_in_base_unit, 0)
                FROM raw_material_price_changelog c LEFT JOIN supplier s ON s.id = c.id_supplier
                WHERE c.id_raw_material = ? AND c.timestamp >= CURDATE() - INTERVAL ? DAY
             ) x WHERE x.price > 0 AND x.id_supplier IS NOT NULL" . ($supplierId ? ' AND x.id_supplier <> ?' : '') . '
             ORDER BY x.price, x.day DESC LIMIT 1',
            $supplierId ? [$materialId, $days, $materialId, $days, $supplierId] : [$materialId, $days, $materialId, $days]
        );
        return $rows[0] ?? null;
    }

    public function eurRate(): float
    {
        try {
            return (float) ($this->db->query('SELECT exchange_rate_euro FROM erp_company_other_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** Ostatnie zatwierdzenie faktury towarowej z cenami zapisanymi w kartotece (przycisk zielony : « sprawdzone … »). */
    public function lastPricesImport(): ?string
    {
        return $this->db->query("SELECT MAX(prices_imported_at) FROM n_inv WHERE kind = 'goods'")->fetchColumn() ?: null;
    }
}
