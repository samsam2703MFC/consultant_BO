<?php
namespace App\Repositories\Dashboard;

use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Workshop\WorkshopRepository;

/** Actions de développement du CA par groupe (n_growth_action) et données ERP pour les proposer. */
final class GrowthActionRepository extends WorkshopRepository
{
    protected string $table = 'n_growth_action';
    protected array $writable = ['id_section', 'title', 'id_final_product', 'product_name', 'pieces_per_shop_month', 'shops', 'selling_price_eur',
        'target_margin_pct', 'investment_eur', 'start_month', 'status', 'owner_user', 'notes', 'created_by'];

    public function list(?int $sectionId): array
    {
        return $this->all(
            "SELECT a.*, fp.name AS erp_product_name, fp.ppc, fp.pre_calculated_raw_price_per_carton, u.displayed_name AS owner_name
             FROM n_growth_action a
             LEFT JOIN final_product fp ON fp.id = a.id_final_product
             LEFT JOIN user u ON u.id = a.owner_user"
            . ($sectionId !== null ? ' WHERE a.id_section = ?' : '')
            . " ORDER BY FIELD(a.status, 'in_progress', 'planned', 'idea', 'developed', 'dropped'), a.created_at DESC, a.id DESC",
            $sectionId !== null ? [$sectionId] : []
        );
    }

    /** Produits actifs d'un groupe avec leur prix et leur coût matières par pièce (PLN). */
    public function products(int $sectionId): array
    {
        return $this->all(
            'SELECT fp.id, fp.name, fp.ppc, fp.standard_price, fp.pre_calculated_raw_price_per_carton, cp.target_margin_percentage
             FROM final_product fp LEFT JOIN category_product cp ON cp.id = fp.id_category
             WHERE fp.active = 1 AND fp.id_section = ? ORDER BY fp.name',
            [$sectionId]
        );
    }

    public function product(int $id): ?array
    {
        return $this->all('SELECT id, name, id_section, ppc, standard_price, pre_calculated_raw_price_per_carton FROM final_product WHERE id = ?', [$id])[0] ?? null;
    }

    public function section(int $id): ?array
    {
        return $this->all('SELECT id, name FROM production_sections WHERE id = ?', [$id])[0] ?? null;
    }

    /** Magasins ayant reçu une livraison (WDT) sur les 90 derniers jours. */
    public function activeShops(): int
    {
        return (int) $this->db->query('SELECT COUNT(DISTINCT s.id_customer) FROM (' . WdtSalesRepository::linesSql() . ') s WHERE s.sale_date >= CURDATE() - INTERVAL 90 DAY')->fetchColumn();
    }

    public function userExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM user WHERE id = ?', [$id]);
    }

    public function users(): array
    {
        return $this->all('SELECT id, displayed_name FROM user ORDER BY displayed_name');
    }
}
