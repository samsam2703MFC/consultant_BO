<?php

namespace App\Services\Audit;

use App\Exceptions\CustomException;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Product\ProductService;

class ProductWeightAuditService
{
    public function __construct(
        private FinalProductRepository $productRepository,
        private ProductService $productService
    ) {}

    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        $normalizedFilters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => $this->normalizeStatus($filters['status'] ?? 'attention'),
            'activity' => $this->normalizeActivity($filters['activity'] ?? 'active'),
            'sort' => in_array(($filters['sort'] ?? 'status'), ['status', 'name', 'id', 'weight'], true) ? $filters['sort'] : 'status',
            'direction' => strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
        ];
        $total = $this->productRepository->countWeightAudit($normalizedFilters);
        $rows = $this->productRepository->getWeightAuditPage($normalizedFilters, $perPage, ($page - 1) * $perPage);
        $data = array_map(function (array $row): array {
            $audit = ProductWeightAuditEvaluator::evaluate($row['weight'], $row['ppc']);
            return [
                'id_product' => (int) $row['id'],
                'sku' => (string) $row['id'],
                'name' => $row['name'],
                'category_name' => $row['category_name'],
                'is_active' => (bool) $row['active'],
                ...$audit,
            ];
        }, $rows);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
                'counts' => $this->productRepository->weightAuditCounts($normalizedFilters),
                'filters' => $normalizedFilters,
            ],
        ];
    }

    public function updateWeight(int $productId, $weight): array
    {
        if ($productId <= 0 || !$this->productService->first($productId)) {
            throw new CustomException('PRODUCT_NOT_FOUND', 'errors', 404);
        }
        try {
            $normalizedWeight = ProductWeightAuditEvaluator::validateInput($weight);
        } catch (\InvalidArgumentException $exception) {
            throw new CustomException($exception->getMessage(), 'errors', 422);
        }
        if (!$this->productService->update($productId, ['weight' => $normalizedWeight])) {
            throw new CustomException('PRODUCT_WEIGHT_UPDATE_FAILED', 'errors', 500);
        }

        $product = $this->productService->first($productId);
        $audit = ProductWeightAuditEvaluator::evaluate($product['weight'], $product['ppc']);
        return [
            'id_product' => $productId,
            'unit_weight_g' => $audit['unit_weight_g'],
            'units_per_carton' => $audit['units_per_carton'],
            'carton_net_content_weight_g' => $audit['carton_net_content_weight_g'],
            'carton_weight_reason' => $audit['carton_weight_reason'],
            'audit_status' => $audit['audit_status'],
            'audit_reason' => $audit['audit_reason'],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['attention', 'all', 'ERROR', 'WARNING', 'OK'], true) ? $status : 'attention';
    }

    private function normalizeActivity(string $activity): string
    {
        return in_array($activity, ['active', 'inactive', 'all'], true) ? $activity : 'active';
    }
}
