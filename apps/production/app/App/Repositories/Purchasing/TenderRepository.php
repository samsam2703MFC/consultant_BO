<?php
namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

final class TenderRepository extends WorkshopRepository
{
    protected string $table = 'n_tender';
    protected array $writable = ['code', 'title', 'status', 'reply_deadline', 'language', 'intro', 'created_by', 'sent_at', 'closed_at'];

    public function list(): array
    {
        return $this->all(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM n_tender_item i WHERE i.id_tender = t.id) AS item_count,
                    (SELECT COUNT(*) FROM n_tender_supplier s WHERE s.id_tender = t.id) AS supplier_count,
                    (SELECT COUNT(*) FROM n_tender_supplier s WHERE s.id_tender = t.id AND s.responded_at IS NOT NULL) AS responded_count
             FROM n_tender t ORDER BY t.created_at DESC, t.id DESC"
        );
    }

    public function nextCode(int $year): string
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM n_tender WHERE code LIKE ?');
        $stmt->execute(["AO-{$year}-%"]);
        return sprintf('AO-%d-%03d', $year, (int) $stmt->fetchColumn() + 1);
    }

    public function items(int $tenderId): array
    {
        return $this->all(
            'SELECT i.*, rm.name AS material_name, rm.name_fr AS material_name_fr, rm.price_per_unit AS current_price,
                    rm.id_current_supplier, u.name AS unit
             FROM n_tender_item i JOIN raw_material rm ON rm.id = i.id_raw_material LEFT JOIN unit u ON u.id = rm.id_unit
             WHERE i.id_tender = ? ORDER BY rm.name',
            [$tenderId]
        );
    }

    public function suppliers(int $tenderId): array
    {
        return $this->all(
            'SELECT ts.*, s.name AS supplier_name, m.status AS message_status, m.provider_message_id
             FROM n_tender_supplier ts JOIN supplier s ON s.id = ts.id_supplier
             LEFT JOIN communication_message m ON m.id = ts.id_message
             WHERE ts.id_tender = ? ORDER BY s.name',
            [$tenderId]
        );
    }

    public function addItem(int $tenderId, int $materialId, ?float $annualQty): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_tender_item (id_tender, id_raw_material, annual_qty_base) VALUES (?, ?, ?)')
            ->execute([$tenderId, $materialId, $annualQty]);
    }

    public function addSupplier(int $tenderId, int $supplierId, string $email): void
    {
        $this->db->prepare('INSERT IGNORE INTO n_tender_supplier (id_tender, id_supplier, email) VALUES (?, ?, ?)')
            ->execute([$tenderId, $supplierId, $email]);
    }

    public function markInvited(int $tenderSupplierId, int $messageId): void
    {
        $this->db->prepare('UPDATE n_tender_supplier SET id_message = ?, invited_at = NOW() WHERE id = ?')->execute([$messageId, $tenderSupplierId]);
    }

    public function markResponded(int $tenderId, int $supplierId): void
    {
        $this->db->prepare('UPDATE n_tender_supplier SET responded_at = COALESCE(responded_at, NOW()) WHERE id_tender = ? AND id_supplier = ?')
            ->execute([$tenderId, $supplierId]);
    }

    public function award(int $itemId, ?int $offerId): void
    {
        $this->db->prepare('UPDATE n_tender_item SET id_awarded_offer = ? WHERE id = ?')->execute([$offerId, $itemId]);
    }
}
