<?php
declare(strict_types=1);

namespace App\Services\Document;

final class ProformaSnapshotPolicy
{
    /** A paid document is immutable; all other legacy statuses stay mutable. */
    public static function isPaid(array $proforma): bool
    {
        return strtolower(trim((string) ($proforma['status'] ?? ''))) === 'paid';
    }
}
