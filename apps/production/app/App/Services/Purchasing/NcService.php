<?php
namespace App\Services\Purchasing;

use App\Core\Api\Workshop\Validator;
use App\Exceptions\CustomException;
use App\Repositories\Purchasing\NcRepository;

/** Registre des non-conformités : réception MP, production, client, interne. */
final class NcService
{
    public const SOURCES = ['supplier_delivery', 'production', 'customer', 'internal'];
    public const SEVERITIES = ['minor', 'major', 'critical'];
    public const STATUSES = ['open', 'in_progress', 'closed'];
    private const FIELDS = ['reported_at', 'source', 'supplier_id', 'material_id', 'product_id', 'description', 'severity', 'quantity', 'unit',
        'immediate_action', 'root_cause', 'corrective_action', 'cost_impact', 'status', 'owner_user', 'due_date'];

    public function __construct(private NcRepository $nc)
    {
    }

    public function list(?string $status = null, ?string $source = null, ?int $supplierId = null): array
    {
        return $this->nc->list($status, $source, $supplierId);
    }

    public function counts(): array
    {
        return $this->nc->counts();
    }

    public function products(): array
    {
        return $this->nc->products();
    }

    public function users(): array
    {
        return $this->nc->users();
    }

    public function save(array $input, ?int $id, ?int $userId): int
    {
        $current = $id === null ? null : ($this->nc->find($id) ?? throw new CustomException('Nie znaleziono niezgodności.', 'errors', 404));
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->date('reported_at', false, true)
            ->choice('source', self::SOURCES, $current === null)
            ->integer('supplier_id', false, 1, null, true)
            ->integer('material_id', false, 1, null, true)
            ->integer('product_id', false, 1, null, true)
            ->string('description', 5000, $current === null)
            ->choice('severity', self::SEVERITIES, false)
            ->decimal('quantity', false, 0, null, true)
            ->string('unit', 16, false, true)
            ->string('immediate_action', 5000, false, true)
            ->string('root_cause', 5000, false, true)
            ->string('corrective_action', 5000, false, true)
            ->decimal('cost_impact', false, 0, null, true)
            ->choice('status', self::STATUSES, false)
            ->integer('owner_user', false, 1, null, true)
            ->date('due_date', false, true);
        $v->throwIfInvalid();

        $data = $v->clean();
        foreach (['supplier_id' => 'id_supplier', 'material_id' => 'id_raw_material', 'product_id' => 'id_final_product'] as $api => $column) {
            if (array_key_exists($api, $data)) { $data[$column] = $data[$api]; unset($data[$api]); }
        }
        if (($data['status'] ?? null) === 'closed' && ($current['status'] ?? null) !== 'closed') {
            $data['closed_at'] = date('Y-m-d H:i:s');
        } elseif (isset($data['status']) && $data['status'] !== 'closed') {
            $data['closed_at'] = null;
        }
        if ($current === null) {
            $data['reported_at'] ??= date('Y-m-d');
            $data['number'] = $this->nc->nextNumber((int) substr($data['reported_at'], 0, 4));
            $data['created_by'] = $userId;
            return $this->nc->insert($data);
        }
        $this->nc->update($id, $data);
        return $id;
    }
}
