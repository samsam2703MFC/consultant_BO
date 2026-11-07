<?php

namespace App\Services\Client\PriceList;

use App\Repositories\Client\PriceList\FranchiseProductCatalogRepository;

class FranchiseProductCatalogService
{
    public function __construct(private FranchiseProductCatalogRepository $repository)
    {
    }

    public function getOverview(): array
    {
        $products = [];

        foreach ($this->repository->currentItemsForAllFranchisees() as $item) {
            $sku = trim((string)($item['sku'] ?? ''));
            $key = $sku !== '' ? 'sku:' . $sku : 'missing:' . $item['id'];

            if (!isset($products[$key])) {
                $products[$key] = [
                    'sku' => $sku ?: null,
                    'product_name' => $item['product_name'] ?? null,
                    'shops' => [],
                ];
            }

            $products[$key]['shops'][] = $item;
        }

        $overview = array_map(fn(array $product) => $this->summarize($product), $products);
        usort($overview, static function (array $left, array $right): int {
            return [$right['has_problem'], $right['has_discrepancy'], $left['product_name'] ?? '', $left['sku'] ?? '']
                <=> [$left['has_problem'], $left['has_discrepancy'], $right['product_name'] ?? '', $right['sku'] ?? ''];
        });

        return $overview;
    }

    private function summarize(array $product): array
    {
        $shops = $product['shops'];
        $prices = [];
        $activeCount = 0;
        $inactiveCount = 0;
        $problemCount = 0;
        $missingPriceCount = 0;
        $activityMismatch = false;
        $activityMismatchDetails = [];
        $values = [
            'product_name' => [],
            'price_net' => [],
            'is_active' => [],
            'valid_from' => [],
            'package_size' => [],
            'package_unit' => [],
        ];

        foreach ($shops as &$shop) {
            if (($shop['is_active'] ?? null) == 1) {
                $activeCount++;
            } elseif (($shop['is_active'] ?? null) == 0) {
                $inactiveCount++;
            }

            $status = $this->resolveCatalogDiagnosticStatus($shop);
            $shop['diagnostic_status'] = $status;
            if (!in_array($status, ['ok', 'inactive_matched'], true)) {
                $problemCount++;
            }
            if ($status === 'activity_mismatch') {
                $activityMismatch = true;
                $activityMismatchDetails[] = [
                    'customer_company' => $shop['customer_company'] ?? null,
                    'supplier_is_active' => (int) $shop['supplier_is_active'],
                    'franchise_is_active' => (int) $shop['is_active'],
                ];
            }
            if (in_array($status, ['missing_price', 'zero_price'], true)) {
                $missingPriceCount++;
            }
            if (($shop['price_net'] ?? null) !== null && $shop['price_net'] !== '') {
                $prices[] = (float)$shop['price_net'];
            }

            foreach (array_keys($values) as $field) {
                $values[$field][] = (string)($shop[$field] ?? '');
            }
        }
        unset($shop);

        $hasDiscrepancy = $activityMismatch;
        foreach ($values as $fieldValues) {
            if (count(array_unique($fieldValues)) > 1) {
                $hasDiscrepancy = true;
                break;
            }
        }

        $latestSnapshotAt = max(array_map(static fn(array $shop) => $shop['snapshot_created_at'] ?? '', $shops));

        return [
            'sku' => $product['sku'],
            'product_name' => $product['product_name'],
            'shop_count' => count($shops),
            'active_count' => $activeCount,
            'inactive_count' => $inactiveCount,
            'problem_count' => $problemCount,
            'missing_price_count' => $missingPriceCount,
            'has_problem' => $problemCount > 0,
            'has_discrepancy' => $hasDiscrepancy,
            'activity_mismatch_details' => $activityMismatchDetails,
            'price_min' => $prices ? min($prices) : null,
            'price_max' => $prices ? max($prices) : null,
            'latest_snapshot_at' => $latestSnapshotAt ?: null,
            'shops' => $shops,
        ];
    }

    /**
     * The snapshot describes the franchisee state. The catalog additionally
     * compares it with the supplier application's local final_product state.
     */
    private function resolveCatalogDiagnosticStatus(array $shop): string
    {
        $snapshotStatus = $shop['diagnostic_status'] ?? 'invalid_payload';
        $franchiseIsActive = $shop['is_active'] ?? null;
        $supplierIsActive = $shop['supplier_is_active'] ?? null;

        if ($franchiseIsActive !== null && $supplierIsActive !== null
            && (int) $franchiseIsActive !== (int) $supplierIsActive) {
            return 'activity_mismatch';
        }

        if ($snapshotStatus === 'inactive_supplier'
            && (int) $franchiseIsActive === 0
            && (int) $supplierIsActive === 0) {
            return 'inactive_matched';
        }

        return $snapshotStatus;
    }
}
