<?php
namespace App\Repositories\Client\Complaint;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Zwroty do wysłania (audit 2, P8) : remplacements acceptés à préparer (complaint_product_pending), remplacements
 * préparés en attente d'expédition (complaint_product_to_send_log 0 attend / 1 emballé / 2 expédié), cartons retournés
 * à renvoyer avec un WZ + CMR (carton_stock_complaint 1), réclamations franchise sans décision.
 */
final class ReturnsRepository extends WorkshopRepository
{
    protected string $table = 'complaint_product_to_send_log';
    protected array $writable = ['status'];

    public function pendingReplacements(): array
    {
        return $this->all("SELECT p.id, p.id_customer, cu.company AS customer, p.id_final_product AS id_product, fp.name AS product, p.pending_product_qty AS quantity
            FROM complaint_product_pending p LEFT JOIN customer cu ON cu.id = p.id_customer LEFT JOIN final_product fp ON fp.id = p.id_final_product
            WHERE p.pending_product_qty > 0 ORDER BY cu.company, fp.name");
    }

    /** Remplacements préparés à expédier (0 attend, 1 emballé), du plus ancien au plus récent. */
    public function toShip(?array $customerIds = null): array
    {
        $ids = $customerIds === null ? null : array_values(array_filter(array_map('intval', $customerIds)));
        if ($ids === []) return [];
        return $this->all("SELECT l.id, l.id_customer, cu.company AS customer, l.id_product, fp.name AS product, l.carton_qty, l.status, l.shipping_date, l.insert_timestamp
            FROM complaint_product_to_send_log l LEFT JOIN customer cu ON cu.id = l.id_customer LEFT JOIN final_product fp ON fp.id = l.id_product
            WHERE l.status IN (0, 1)" . ($ids ? ' AND l.id_customer IN (' . implode(',', $ids) . ')' : '') . "
            ORDER BY COALESCE(l.shipping_date, DATE(l.insert_timestamp)), cu.company, fp.name");
    }

    /** Cartons retournés en attente de WZ + CMR, par client et jour d'expédition. */
    public function returnCartons(): array
    {
        return $this->all("SELECT c.id_customer, cu.company AS customer, c.shipping_date, COUNT(*) AS cartons
            FROM carton_stock_complaint c LEFT JOIN customer cu ON cu.id = c.id_customer
            WHERE c.status = 1 GROUP BY c.id_customer, cu.company, c.shipping_date ORDER BY c.shipping_date, cu.company");
    }

    /** Réclamations des franchisés sans décision (nouvelles ou en cours d'examen). */
    public function openComplaints(): array
    {
        return $this->all("SELECT c.id, COALESCE(c.acceptance_status, 'NEW') AS status, c.acceptance_timestamp AS received_at, c.complaint_reason,
                cu.company AS customer, c.id_customer, fp.name AS product
            FROM complaint c LEFT JOIN customer cu ON cu.id = c.id_customer LEFT JOIN final_product fp ON fp.id = c.id_product
            WHERE COALESCE(c.acceptance_status, 'NEW') IN ('NEW', 'IN_REVIEW') AND c.acceptance_timestamp IS NOT NULL
            ORDER BY c.acceptance_timestamp, c.id");
    }

    /**
     * WZ du client pour ce jour créé : les remplacements emballés (statut 1) prévus jusqu'à ce jour partent avec lui → « Wysłane ».
     * @return int lignes passées à « Wysłane »
     */
    public function markShippedWithWz(int $customerId, string $date): int
    {
        $stmt = $this->db->prepare("UPDATE complaint_product_to_send_log SET status = 2
            WHERE id_customer = ? AND status = 1 AND (shipping_date IS NULL OR shipping_date <= ?)");
        $stmt->execute([$customerId, $date]);
        return $stmt->rowCount();
    }
}
