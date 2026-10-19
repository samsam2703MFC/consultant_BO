<?php
namespace App\Services\Finances;

use App\Repositories\Analysis\SalesAnalyticsRepository;

class SalesAnalyticsService
{
    public function __construct(
        private SalesAnalyticsRepository $repo
    ) {
    }

    /**
     * Zwraca domyślny zakres dat: ostatnie 12 miesięcy.
     */
    public function getDefaultDateRange(?int $customerId = null): array
    {
        $range = $this->repo->getAvailableDateRange($customerId);
        $maxDate = $range['date_max'] ?? date('Y-m');

        // 12 miesięcy wstecz od max daty
        $maxTs = strtotime($maxDate . '-01');
        $minTs = strtotime('-11 months', $maxTs);

        return [
            'from' => date('Y-m', $minTs),
            'to'   => $maxDate,
        ];
    }

    /**
     * KPI podsumowania dla zakresu.
     */
    public function getSummary(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        return $this->repo->getSummary($dateFrom, $dateTo, $customerId);
    }

    /**
     * Dane wykresu miesięcznego przychodu (labels + quantity_values + value_values).
     */
    public function getMonthlyChartData(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $rows = $this->repo->getMonthlyRevenue($dateFrom, $dateTo, $customerId);

        $labels  = [];
        $values  = [];
        $cartons = [];

        foreach ($rows as $row) {
            $labels[]  = $row['period'];
            $values[]  = round((float)$row['total_value'], 2);
            $cartons[] = (int)$row['total_cartons'];
        }

        return compact('labels', 'values', 'cartons');
    }

    /**
     * Średnia miesięczna sprzedaż produktów.
     */
    public function getAverageProductSales(string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $rows = $this->repo->getAverageProductSales($dateFrom, $dateTo, $customerId);

        return array_map(function ($row) {
            $row['avg_quantity'] = round((float)$row['avg_quantity'], 2);
            $row['avg_value']    = round((float)$row['avg_value'], 2);
            $row['total_value']  = round((float)$row['total_value'], 2);
            return $row;
        }, $rows);
    }

    /**
     * Trend sprzedaży wybranego produktu.
     */
    public function getProductTrend(int $productId, string $dateFrom, string $dateTo, ?int $customerId = null): array
    {
        $product = $this->repo->getProductById($productId);
        $rows    = $this->repo->getProductSalesTrend($productId, $dateFrom, $dateTo, $customerId);

        $labels   = [];
        $quantities = [];
        $values   = [];

        foreach ($rows as $row) {
            $labels[]     = $row['period'];
            $quantities[] = (float)$row['quantity'];
            $values[]     = round((float)$row['value'], 2);
        }

        return [
            'product'    => $product,
            'labels'     => $labels,
            'quantities' => $quantities,
            'values'     => $values,
        ];
    }

    public function getFilterableCustomers(): array
    {
        return $this->repo->getFilterableCustomers();
    }
}

