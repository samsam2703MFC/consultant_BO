<?php

namespace App\Controllers\Web\Client\PriceList;

use App\Core\Web\BaseController;
use App\Services\Client\PriceList\FranchiseProductSnapshotService;

class FranchiseProductSnapshotController extends BaseController
{
    public function __construct(private FranchiseProductSnapshotService $snapshotService)
    {
    }

    public function refresh($id)
    {
        $snapshot = $this->snapshotService->refreshForClient((int)$id);
        return $this->json([
            'ok' => ($snapshot['status'] ?? null) === 'success',
            'snapshot' => $snapshot,
        ], ($snapshot['status'] ?? null) === 'success' ? 200 : 502);
    }

    public function latestSuccessful($id)
    {
        $snapshot = $this->snapshotService->getLatestSuccessfulForClient((int)$id);
        if (!$snapshot) {
            return $this->json(['ok' => false, 'message' => 'SNAPSHOT_NOT_FOUND'], 404);
        }

        return $this->json(['ok' => true, 'snapshot' => $snapshot]);
    }

    public function latestAttempt($id)
    {
        $snapshot = $this->snapshotService->getLatestAttemptForClient((int)$id);
        if (!$snapshot) {
            return $this->json(['ok' => false, 'message' => 'SNAPSHOT_NOT_FOUND'], 404);
        }

        return $this->json(['ok' => true, 'snapshot' => $snapshot]);
    }
}
