<?php

namespace App\Services\Client\PriceList;

use App\Repositories\Client\ClientRepository;
use App\Services\Notification\AppNotificationService;

/** Creates user-facing notifications from the existing franchise-product snapshots. */
class FranchiseProductDiscrepancyNotificationService
{
    public function __construct(
        private ClientRepository $clientRepository,
        private FranchiseProductSnapshotService $snapshotService,
        private FranchiseProductCatalogService $catalogService,
        private AppNotificationService $notificationService
    ) {}

    public function refreshAndNotify(): array
    {
        $refreshed = 0;
        $failed = 0;
        foreach ($this->clientRepository->getFranchisee() as $client) {
            try {
                $snapshot = $this->snapshotService->refreshForClient((int) $client['id']);
                ($snapshot['status'] ?? 'failed') === 'success' ? $refreshed++ : $failed++;
            } catch (\Throwable $exception) {
                $failed++;
                error_log('[franchise_product_discrepancy_monitor] refresh failed for client ' . $client['id'] . ': ' . $exception->getMessage());
            }
        }

        $notified = 0;
        foreach ($this->catalogService->getOverview() as $product) {
            if (empty($product['has_problem']) && empty($product['has_discrepancy'])) {
                continue;
            }
            $fingerprint = hash('sha256', json_encode([
                'sku' => $product['sku'] ?? null,
                'problem_count' => $product['problem_count'] ?? 0,
                'has_discrepancy' => !empty($product['has_discrepancy']),
                'shops' => $product['shops'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $name = $product['product_name'] ?? ('SKU ' . ($product['sku'] ?? 'bez SKU'));
            $reason = !empty($product['has_discrepancy'])
                ? 'Wykryto rozbieżność danych produktu między zintegrowanymi aplikacjami.'
                : 'Wykryto nieprawidłowe dane produktu w zintegrowanej aplikacji.';
            $this->notificationService->publish(
                'product_sync_discrepancy',
                'warning',
                'Rozbieżność danych produktu: ' . $name,
                $reason,
                '/franchise-products',
                ['sku' => $product['sku'] ?? null, 'product_name' => $name, 'fingerprint' => $fingerprint],
                'product_sync_discrepancy:' . ($product['sku'] ?: 'missing') . ':' . $fingerprint
            );
            $notified++;
        }

        return compact('refreshed', 'failed', 'notified');
    }
}
