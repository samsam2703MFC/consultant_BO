<?php

require __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Material\Ppwr\FinalProductPpwrSalesPackagingRepository;
use App\Services\Material\Ppwr\FinalProductPpwrSalesPackagingService;

final class InMemoryFinalProductPpwrSalesPackagingRepository extends FinalProductPpwrSalesPackagingRepository
{
    public array $configuration = [];

    public function __construct()
    {
    }

    public function getProductById(int $productId): array
    {
        return $productId === 25 ? ['id' => 25, 'name' => 'Bagietki', 'ppc' => 18, 'active' => 1] : [];
    }

    public function isCandidateForProduct(int $productId, int $materialId): bool
    {
        return $productId === 25 && $materialId === 11;
    }

    public function getByProductId(int $productId): array
    {
        return $this->configuration;
    }

    public function insert(array $data): int|bool
    {
        $this->configuration = ['id' => 101, ...$data];
        return 101;
    }

    public function update(mixed $id, array $data, $id_column = 'id'): bool
    {
        $this->configuration = ['id' => $id, ...$this->configuration, ...$data];
        return true;
    }
}

function productPpwrExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repository = new InMemoryFinalProductPpwrSalesPackagingRepository();
$service = new FinalProductPpwrSalesPackagingService($repository);
$result = $service->save(25, ['id_outer_raw_material' => '11']);

productPpwrExpect($result['sales_unit_name'] === 'Karton', 'Sales unit must always be stored as a carton.');
productPpwrExpect($repository->configuration['id_outer_raw_material'] === 11, 'Selected recipe packaging material must be saved.');
productPpwrExpect(!array_key_exists('products_per_sales_unit', $repository->configuration), 'PPC must not be duplicated in PPWR sales packaging configuration.');
productPpwrExpect($repository->configuration['verification_status'] === 'COMPLETE', 'Completed selection must be marked COMPLETE.');

echo "final_product_ppwr_sales_packaging_service_test: OK\n";
