<?php

namespace App\Controllers\Web\Client\PriceList;

use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Client\PriceList\FranchiseProductSnapshotService;

class FranchiseProductSnapshotDiagnosticController extends BaseController
{
    public function __construct(
        private ClientService $clientService,
        private FranchiseProductSnapshotService $snapshotService
    ) {
    }

    public function overview($id)
    {
        $client = $this->clientService->getById((int)$id);
        if (!$client) {
            $this->errors[] = 'Nie znaleziono klienta.';
        }

        $latestSuccessful = $client
            ? $this->snapshotService->getLatestSuccessfulForClient((int)$id)
            : [];
        $latestAttempt = $client
            ? $this->snapshotService->getLatestAttemptForClient((int)$id)
            : [];
        $items = $latestSuccessful['items'] ?? [];

        $this->view('client/price_list/franchise_product_snapshot', [
            'client' => $client ?: [],
            'latest_successful' => $latestSuccessful,
            'latest_attempt' => $latestAttempt,
            'items' => $items,
            'statistics' => $this->statistics($items),
        ]);
    }

    private function statistics(array $items): array
    {
        $active = 0;
        $inactive = 0;
        $problems = 0;

        foreach ($items as $item) {
            if (($item['is_active'] ?? null) == 1) {
                $active++;
            } elseif (($item['is_active'] ?? null) == 0) {
                $inactive++;
            }

            if (($item['diagnostic_status'] ?? 'invalid_payload') !== 'ok') {
                $problems++;
            }
        }

        return [
            'all' => count($items),
            'active' => $active,
            'inactive' => $inactive,
            'problems' => $problems,
        ];
    }
}
