<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Web\BaseController;
use App\Services\Finances\SalesAnalyticsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SalesAnalyticsController extends BaseController
{
    public function __construct(
        private SalesAnalyticsService $salesAnalyticsService
    ) {
    }

    /**
     * GET /finances/sales-analytics
     * Widok główny: tabela średniej sprzedaży produktów + wykres miesięczny.
     */
    public function overview(): void
    {
        $request    = Request::createFromGlobals();
        $customerId = $this->customerId($request);
        $defaults   = $this->salesAnalyticsService->getDefaultDateRange($customerId);
        $dateFrom   = $request->query->get('date_from', $defaults['from']);
        $dateTo     = $request->query->get('date_to',   $defaults['to']);

        $summary      = $this->salesAnalyticsService->getSummary($dateFrom, $dateTo, $customerId);
        $monthlyChart = $this->salesAnalyticsService->getMonthlyChartData($dateFrom, $dateTo, $customerId);
        $products     = $this->salesAnalyticsService->getAverageProductSales($dateFrom, $dateTo, $customerId);

        $data = [
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'customer_id'   => $customerId ?? 0,
            'customers'     => $this->salesAnalyticsService->getFilterableCustomers(),
            'summary'       => $summary,
            'monthly_chart' => $monthlyChart,
            'products'      => $products,
        ];

        $this->view('finances/sales-analytics/overview', $data);
    }

    /**
     * GET /ajax/finances/sales-analytics/product-trend?product_id=X&date_from=YYYY-MM&date_to=YYYY-MM
     * Zwraca dane trendu sprzedażowego wybranego produktu jako JSON.
     */
    public function ajaxProductTrend(): JsonResponse
    {
        $request   = Request::createFromGlobals();
        $productId = (int)$request->query->get('product_id', 0);
        $customerId = $this->customerId($request);
        $defaults  = $this->salesAnalyticsService->getDefaultDateRange($customerId);
        $dateFrom  = $request->query->get('date_from', $defaults['from']);
        $dateTo    = $request->query->get('date_to',   $defaults['to']);

        if ($productId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Brak product_id'], 400);
        }

        $trend = $this->salesAnalyticsService->getProductTrend($productId, $dateFrom, $dateTo, $customerId);
        return $this->json(['ok' => true, 'data' => $trend]);
    }

    private function customerId(Request $request): ?int
    {
        $value = filter_var($request->query->get('customer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }
}

