<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Repositories\Workshop\AuditLogRepository;

/** Écrit chaque modification du module dans tfb_prod_audit_log (qui, quoi, avant, après). */
final class AuditTrail
{
    public function __construct(private AuditLogRepository $log)
    {
    }

    public function record(ApiContext $context, string $action, string $entity, int|string|null $id, ?array $before, ?array $after): void
    {
        $this->log->record($context->userId, $action, $entity, $id, $before, $after, $context->requestId);
    }
}
