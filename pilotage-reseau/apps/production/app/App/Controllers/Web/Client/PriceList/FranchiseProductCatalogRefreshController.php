<?php

namespace App\Controllers\Web\Client\PriceList;

use App\Core\Web\BaseController;
use App\Repositories\Client\ClientRepository;
use App\Services\Client\PriceList\FranchiseProductSnapshotService;

class FranchiseProductCatalogRefreshController extends BaseController
{
    public function __construct(
        private ClientRepository $clientRepository,
        private FranchiseProductSnapshotService $snapshotService
    ) {
    }

    public function refreshAll()
    {
        $clients = $this->clientRepository->query(
            "SELECT id, company FROM customer WHERE franchisee_uid IS NOT NULL AND franchisee_uid <> '' ORDER BY company ASC"
        ) ?: [];
        $results = [];

        foreach ($clients as $client) {
            try {
                $snapshot = $this->snapshotService->refreshForClient((int)$client['id']);
                $results[] = [
                    'client_id' => (int)$client['id'],
                    'company' => $client['company'],
                    'status' => $snapshot['status'] ?? 'failed',
                    'error_message' => $snapshot['error_message'] ?? null,
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'client_id' => (int)$client['id'],
                    'company' => $client['company'],
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ];
            }
        }

        $successful = count(array_filter($results, static fn(array $result) => $result['status'] === 'success'));

        return $this->json([
            'ok' => $successful === count($results),
            'total' => count($results),
            'successful' => $successful,
            'failed' => count($results) - $successful,
            'results' => $results,
        ], $successful === count($results) ? 200 : 207);
    }
}
