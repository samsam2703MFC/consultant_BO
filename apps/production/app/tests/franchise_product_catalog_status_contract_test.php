<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/vendor/autoload.php';

function expectCatalogStatus(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repository = file_get_contents(__DIR__ . '/../App/Repositories/Client/PriceList/FranchiseProductCatalogRepository.php');
$service = file_get_contents(__DIR__ . '/../App/Services/Client/PriceList/FranchiseProductCatalogService.php');
$view = file_get_contents(__DIR__ . '/../App/Views/client/price_list/franchise_product_catalog.twig');

expectCatalogStatus(str_contains($repository, 'supplier_product.active AS supplier_is_active'), 'Catalog must load the supplier product activity state.');
expectCatalogStatus(str_contains($service, 'inactive_matched'), 'Matching inactive states must have a dedicated neutral status.');
expectCatalogStatus(str_contains($service, 'activity_mismatch'), 'Different activity states must be reported as a discrepancy.');
expectCatalogStatus(str_contains($view, "'inactive_matched': 'Wyłączony — zgodny'"), 'Catalog must show a neutral label for matching inactive states.');
expectCatalogStatus(str_contains($view, '<th>Dostawca</th>') && str_contains($view, '<th>Franczyza</th>'), 'Catalog details must show the supplier and franchise activity states for a mismatch.');

$reflection = new ReflectionClass(App\Services\Client\PriceList\FranchiseProductCatalogService::class);
$serviceInstance = $reflection->newInstanceWithoutConstructor();
$resolveStatus = $reflection->getMethod('resolveCatalogDiagnosticStatus');
$summarize = $reflection->getMethod('summarize');

expectCatalogStatus(
    $resolveStatus->invoke($serviceInstance, ['diagnostic_status' => 'inactive_supplier', 'is_active' => 0, 'supplier_is_active' => 0]) === 'inactive_matched',
    'Inactive product on both sides must be classified as matching.'
);
expectCatalogStatus(
    $resolveStatus->invoke($serviceInstance, ['diagnostic_status' => 'inactive_supplier', 'is_active' => 0, 'supplier_is_active' => 1]) === 'activity_mismatch',
    'Different activity states must be classified as a mismatch.'
);

$summary = $summarize->invoke($serviceInstance, [
    'sku' => '123',
    'product_name' => 'Test product',
    'shops' => [[
        'diagnostic_status' => 'inactive_supplier',
        'is_active' => 0,
        'supplier_is_active' => 0,
        'price_net' => null,
        'snapshot_created_at' => '2026-01-01 00:00:00',
    ]],
]);
expectCatalogStatus($summary['has_problem'] === false, 'Matching inactive product must not increase the problem count.');
expectCatalogStatus($summary['has_discrepancy'] === false, 'Matching inactive product must not be a discrepancy.');

$mismatchSummary = $summarize->invoke($serviceInstance, [
    'sku' => '123',
    'product_name' => 'Test product',
    'shops' => [[
        'customer_company' => 'Testowa franczyza',
        'diagnostic_status' => 'ok',
        'is_active' => 1,
        'supplier_is_active' => 0,
        'price_net' => 10,
        'snapshot_created_at' => '2026-01-01 00:00:00',
    ]],
]);
expectCatalogStatus($mismatchSummary['activity_mismatch_details'] === [[
    'customer_company' => 'Testowa franczyza',
    'supplier_is_active' => 0,
    'franchise_is_active' => 1,
]], 'Mismatch summary must identify the activity state on both sides.');

echo "franchise_product_catalog_status_contract_test: OK\n";
