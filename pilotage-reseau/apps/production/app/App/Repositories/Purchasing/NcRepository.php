<?php
namespace App\Repositories\Purchasing;

use App\Repositories\Workshop\WorkshopRepository;

final class NcRepository extends WorkshopRepository
{
    protected string $table = 'n_nc';
    protected array $writable = ['number', 'reported_at', 'source', 'id_supplier', 'id_raw_material', 'id_final_product', 'description', 'severity',
        'quantity', 'unit', 'immediate_action', 'root_cause', 'corrective_action', 'cost_impact', 'status', 'owner_user', 'due_date', 'closed_at', 'created_by'];

    public function list(?string $status, ?string $source, ?int $supplierId): array
    {
        $where = [];
        $params = [];
        foreach (['n.status' => $status, 'n.source' => $source, 'n.id_supplier' => $supplierId] as $column => $value) {
            if ($value !== null) { $where[] = "{$column} = ?"; $params[] = $value; }
        }
        return $this->all(
            'SELECT n.*, s.name AS supplier_name, rm.name AS material_name, fp.name AS product_name, u.displayed_name AS owner_name
             FROM n_nc n
             LEFT JOIN supplier s ON s.id = n.id_supplier
             LEFT JOIN raw_material rm ON rm.id = n.id_raw_material
             LEFT JOIN final_product fp ON fp.id = n.id_final_product
             LEFT JOIN user u ON u.id = n.owner_user'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY FIELD(n.status, 'open', 'in_progress', 'closed'), n.reported_at DESC, n.id DESC LIMIT 500",
            $params
        );
    }

    public function nextNumber(int $year): string
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM n_nc WHERE number LIKE ?");
        $stmt->execute(["NC-{$year}-%"]);
        return sprintf('NC-%d-%03d', $year, (int) $stmt->fetchColumn() + 1);
    }

    public function counts(): array
    {
        return array_map('intval', array_column($this->all('SELECT status, COUNT(*) AS n FROM n_nc GROUP BY status'), 'n', 'status'));
    }

    public function products(): array
    {
        return $this->all('SELECT id, name FROM final_product WHERE active = 1 ORDER BY name');
    }

    public function users(): array
    {
        return $this->all('SELECT id, displayed_name FROM user ORDER BY displayed_name');
    }
}
