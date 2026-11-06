<?php
declare(strict_types=1);

function expectSalesAnalyticsCustomerFilter(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$controller = file_get_contents(__DIR__ . '/../App/Controllers/Web/Finances/Product/SalesAnalyticsController.php');
$service = file_get_contents(__DIR__ . '/../App/Services/Finances/SalesAnalyticsService.php');
$repository = file_get_contents(__DIR__ . '/../App/Repositories/Analysis/SalesAnalyticsRepository.php');
$view = file_get_contents(__DIR__ . '/../App/Views/finances/sales-analytics/overview.twig');

expectSalesAnalyticsCustomerFilter(str_contains($controller, 'customerId($request)') && str_contains($controller, 'getFilterableCustomers'), 'The analytics controller must read customer_id and provide customer choices.');
expectSalesAnalyticsCustomerFilter(substr_count($service, '?int $customerId = null') >= 5, 'The service must pass the optional customer filter through every analytics query.');
expectSalesAnalyticsCustomerFilter(str_contains($repository, 'getFilterableCustomers') && substr_count($repository, 'customerWhere(') >= 5, 'The repository must list WDT customers and filter each WDT aggregation.');
expectSalesAnalyticsCustomerFilter(str_contains($view, 'name="customer_id"') && str_contains($view, 'CUSTOMER_ID') && str_contains($view, "'&customer_id='"), 'The customer dropdown and product-trend request must preserve the selected client.');

echo "sales_analytics_customer_filter_contract_test: OK\n";
