<?php

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Services\Client\Order\OrderService;
use App\Services\Production\ProductionListService;

/**
 * Listy dnia (PDF) na stronie « Plan zespołów » : dni wysyłki z oczekującymi zamówieniami i liczba produktów
 * ze zwrotów. PDF-y (lista produkcyjna na sekcje, zapotrzebowanie materiałowe, lista dla zwrotów) z istniejących
 * /ajax/production-list/* — tylko odczyt, nic nie jest zapisywane.
 */
class ProductionDayListsController extends BaseController
{
    public const MAX_DATES = 4;

    public function __construct(
        private ProductionListService $productionListService,
        private OrderService $orderService,
    )
    {
    }

    /** GET /ajax/production-plan/days — oczekujące zamówienia według dnia wysyłki. */
    public function ajaxDays()
    {
        $days = [];
        foreach ($this->orderService->getPending() as $order) {
            $date = (string) $order['expected_shipping_date'];
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date, 'orders' => [], 'kg' => 0.0];
            }
            $days[$date]['orders'][] = (int) $order['id'];
            $days[$date]['kg'] += (float) ($order['totalSumKg'] ?? 0);
        }

        return $this->json(['days' => array_values($days), 'max_dates' => self::MAX_DATES]);
    }

    /** GET /ajax/production-plan/complaints — ile produktów obejmie lista dla zwrotów. */
    public function ajaxComplaints()
    {
        try {
            $data = $this->productionListService->getComplaintProductionListDocumentData();
        } catch (\Throwable $e) {
            error_log('[production-plan] complaint preview failed: ' . $e->getMessage());
            return $this->json(['message' => 'Nie udało się odczytać zwrotów do produkcji.'], 500);
        }

        $sections = array_map(static fn($s) => [
            'name'     => (string) $s['section_name'],
            'products' => count($s['section_products'] ?? []),
        ], $data['section_data'] ?? []);

        return $this->json([
            'products' => array_sum(array_column($sections, 'products')),
            'sections' => $sections,
        ]);
    }
}
