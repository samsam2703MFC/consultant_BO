<?php
namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

final class OfferRepository extends WorkshopRepository
{
    protected string $table = 'tfb_mp_offer';
    protected array $writable = ['id_supplier', 'id_raw_material', 'pack_price', 'pack_size_base', 'price_per_base', 'currency', 'valid_from', 'valid_to',
        'min_order_qty', 'lead_time_days', 'source', 'id_tender', 'status', 'notes', 'created_by'];

    public function list(?int $materialId, ?int $supplierId, ?string $status, ?int $tenderId = null): array
    {
        $where = [];
        $params = [];
        foreach (['o.id_raw_material' => $materialId, 'o.id_supplier' => $supplierId, 'o.status' => $status, 'o.id_tender' => $tenderId] as $column => $value) {
            if ($value !== null) { $where[] = "{$column} = ?"; $params[] = $value; }
        }
        return $this->all(
            'SELECT o.*, s.name AS supplier_name, rm.name AS material_name, rm.price_per_unit AS current_price, u.name AS unit
             FROM tfb_mp_offer o
             LEFT JOIN supplier s ON s.id = o.id_supplier
             LEFT JOIN raw_material rm ON rm.id = o.id_raw_material
             LEFT JOIN unit u ON u.id = rm.id_unit'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY o.created_at DESC, o.id DESC LIMIT 500',
            $params
        );
    }

    public function supplierExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM supplier WHERE id = ?', [$id]);
    }

    public function materialExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM raw_material WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE tfb_mp_offer SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
}
