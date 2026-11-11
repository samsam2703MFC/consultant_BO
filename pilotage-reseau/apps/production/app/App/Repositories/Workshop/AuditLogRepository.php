<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

final class AuditLogRepository extends WorkshopRepository
{
    protected string $table = 'n_prod_audit_log';
    protected array $writable = ['id_user', 'action', 'entity', 'entity_id', 'before_json', 'after_json', 'request_id'];

    public function record(?int $userId, string $action, string $entity, int|string|null $entityId, ?array $before, ?array $after, ?string $requestId): void
    {
        $json = fn(?array $v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->insert([
            'id_user' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before_json' => $json($before),
            'after_json' => $json($after),
            'request_id' => $requestId,
        ]);
    }
}
