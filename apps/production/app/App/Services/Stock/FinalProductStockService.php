<?php
namespace App\Services\Stock;

use App\Repositories\Stock\FinalProduct\FinalProductStockRepository;

class FinalProductStockService
{
    private FinalProductStockRepository $finalProductStockRepository;

    public function __construct(
        FinalProductStockRepository $finalProductStockRepository
    )
    {
        $this->finalProductStockRepository = $finalProductStockRepository;
    }

    public function getCategoryProductInStock()
    {
        return $this->finalProductStockRepository->getCategoryProductInStock();
    }

    public function getStockState()
    {
        return $this->finalProductStockRepository->getStockState();
    }

    public function getStockStateByCategory($idCategory)
    {
        return $this->finalProductStockRepository->getStockStateByCategory($idCategory);
    }

    public function getSupplyMonitorData(?array $selectedOrderIds = null): array
    {
        $orders = $this->finalProductStockRepository->getPendingOrderOptions();
        $availableOrderIds = array_map('intval', array_column($orders, 'id'));

        if ($selectedOrderIds === null) {
            $selectedOrderIds = $availableOrderIds;
        } else {
            $selectedOrderIds = array_values(array_intersect(
                $availableOrderIds,
                array_map('intval', $selectedOrderIds)
            ));
        }

        $rows = $this->finalProductStockRepository->getSupplyMonitorRows($selectedOrderIds);
        $rows = $this->enrichSupplyRows($rows);

        return [
            'orders' => $orders,
            'selected_order_ids' => $selectedOrderIds,
            'rows' => $rows,
            'summary' => $this->buildSupplySummary($rows, $orders, $selectedOrderIds),
        ];
    }

    private function enrichSupplyRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $cartonsPerPallet = (float)($row['cartons_per_pallet'] ?? 0);
            $ordered = (float)($row['ordered_quantity'] ?? 0);
            $remaining = (float)($row['remaining_to_produce'] ?? 0);
            $avgSold = (float)($row['avg_sold_last_4_weeks'] ?? 0);
            $yoy = (float)($row['ordered_quantity_yoy'] ?? 0);
            $totalAvailable = (float)($row['total_available'] ?? 0);

            $row['ordered_pallets'] = $cartonsPerPallet > 0 ? round($ordered / $cartonsPerPallet, 2) : null;
            $row['remaining_pallets'] = $cartonsPerPallet > 0 ? round($remaining / $cartonsPerPallet, 2) : null;
            $row['coverage_weeks'] = $avgSold > 0 ? round($totalAvailable / $avgSold, 1) : null;
            $row['yoy_delta'] = $ordered - $yoy;
            $row['yoy_delta_percent'] = $yoy > 0 ? round((($ordered - $yoy) / $yoy) * 100, 1) : null;

            if ($remaining > 0) {
                $row['state'] = 'danger';
                $row['state_label'] = 'Do produkcji';
            } elseif ($ordered > 0 && (float)$row['available_after_orders'] < 0) {
                $row['state'] = 'warning';
                $row['state_label'] = 'Ryzyko braku';
            } else {
                $row['state'] = 'success';
                $row['state_label'] = 'Pokryte';
            }
        }
        unset($row);

        return $rows;
    }

    private function buildSupplySummary(array $rows, array $orders, array $selectedOrderIds): array
    {
        $selectedLookup = array_flip($selectedOrderIds);
        $selectedOrders = array_filter($orders, fn($order) => isset($selectedLookup[(int)$order['id']]));
        $dates = array_values(array_filter(array_column($selectedOrders, 'expected_shipping_date')));

        $ordered = array_sum(array_map(fn($row) => (float)$row['ordered_quantity'], $rows));
        $available = array_sum(array_map(fn($row) => (float)$row['total_available'], $rows));
        $remaining = array_sum(array_map(fn($row) => (float)$row['remaining_to_produce'], $rows));
        $palletised = array_sum(array_map(fn($row) => (float)$row['palletised'], $rows));
        $avgSold = array_sum(array_map(fn($row) => (float)$row['avg_sold_last_4_weeks'], $rows));
        $yoy = array_sum(array_map(fn($row) => (float)$row['ordered_quantity_yoy'], $rows));

        return [
            'selected_orders_count' => count($selectedOrderIds),
            'available_orders_count' => count($orders),
            'ordered_quantity' => $ordered,
            'total_available' => $available,
            'palletised' => $palletised,
            'remaining_to_produce' => $remaining,
            'avg_sold_last_4_weeks' => round($avgSold, 2),
            'ordered_quantity_yoy' => $yoy,
            'yoy_delta' => $ordered - $yoy,
            'date_from' => !empty($dates) ? min($dates) : null,
            'date_to' => !empty($dates) ? max($dates) : null,
        ];
    }
}
