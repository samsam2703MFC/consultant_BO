<?php

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Services\Client\Order\OrderService;
use App\Services\Production\ProductionListService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kreator „Planuj produkcję”: dni wysyłki → podgląd ilości na sekcje → PDF.
 * Tylko odczyty: podgląd korzysta z tej samej usługi co
 * POST /api/internal/v1/production-lists/preview (tabela tymczasowa sesji,
 * nic nie jest zapisywane), a PDF-y z istniejących /ajax/production-list/*.
 */
class ProductionPlanWizardController extends BaseController
{
    private const MAX_DATES = 4;

    public function __construct(
        private ProductionListService $productionListService,
        private OrderService $orderService,
        private \App\Repositories\Workshop\ParamRepository $params,
    )
    {
    }

    public function index()
    {
        $days = [];
        foreach ($this->orderService->getPending() as $order) {
            $date = (string) $order['expected_shipping_date'];
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date, 'orders' => [], 'kg' => 0.0];
            }
            $days[$date]['orders'][] = [
                'id'          => (int) $order['id'],
                'company'     => (string) $order['company'],
                'description' => (string) ($order['order_description'] ?? ''),
                'kg'          => (float) ($order['totalSumKg'] ?? 0),
                'supplement'  => ($order['order_type'] ?? 'STANDARD') === 'SUPPLEMENT',
            ];
            $days[$date]['kg'] += (float) ($order['totalSumKg'] ?? 0);
        }

        $this->view('production/plan_wizard', [
            'days'      => array_values($days),
            'max_dates' => self::MAX_DATES,
            'week_start_day' => \App\Services\Workshop\Planning\WeekStart::day($this->params),
        ]);
    }

    /**
     * POST /ajax/production-plan/preview  {order_ids:[int], stock_minimum:bool}
     * Ilości na sekcje, jak na liście produkcyjnej (PDF liczy „Do produkcji” tak samo).
     */
    public function ajaxPreview()
    {
        $payload = $this->getJson(Request::createFromGlobals());

        $orderIds = $this->validOrderIds($payload['order_ids'] ?? null);
        if (is_string($orderIds)) {
            return $this->json(['message' => $orderIds], 422);
        }
        $stockMinimum = filter_var($payload['stock_minimum'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->productionListService->getProductionListDocumentData([
                'order_id'      => $orderIds,
                'stock_minimum' => $stockMinimum ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('[production-plan] preview failed: ' . $e->getMessage());
            return $this->json(['message' => 'Nie udało się przygotować podglądu listy produkcyjnej.'], 500);
        }

        $sections = [];
        foreach ($data['section_data'] ?? [] as $section) {
            $products = [];
            foreach ($section['section_products'] as $p) {
                $need = (int) $p['day_1_pcs'] + ($stockMinimum ? (int) $p['stock_minimum_pcs'] : 0) - (int) $p['in_stock_pcs'];
                $products[] = [
                    'id'                => (int) $p['id_product'],
                    'name'              => (string) $p['name'],
                    'ppc'               => (int) $p['ppc'],
                    'in_stock_carton'   => (int) $p['in_stock_carton'],
                    'in_stock_pcs'      => (int) $p['in_stock_pcs'],
                    'stock_minimum_pcs' => (int) $p['stock_minimum_pcs'],
                    'day_carton'        => [(int) $p['day_1_carton'], (int) $p['day_2_carton'], (int) $p['day_3_carton'], (int) $p['day_4_carton']],
                    'day_pcs'           => [(int) $p['day_1_pcs'], (int) $p['day_2_pcs'], (int) $p['day_3_pcs'], (int) $p['day_4_pcs']],
                    'complaint_pcs'     => (int) $p['complaint_pcs'],
                    'to_produce_pcs'    => max(0, $need),
                ];
            }
            $sections[] = ['name' => (string) $section['section_name'], 'products' => $products];
        }

        $dates = array_values(array_filter(
            array_map(static fn($d) => (string) $d['expected_shipping_date'], $data['dates'] ?? []),
            static fn($d) => $d !== '-'
        ));

        return $this->json([
            'dates'         => $dates,
            'stock_minimum' => $stockMinimum,
            'sections'      => $sections,
        ]);
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

    /** @return list<int>|string identyfikatory zamówień albo komunikat błędu */
    private function validOrderIds($raw): array|string
    {
        if (!is_array($raw) || $raw === []) {
            return 'Wybierz co najmniej jedno zamówienie.';
        }
        $ids = [];
        foreach ($raw as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                return 'Nieprawidłowy identyfikator zamówienia.';
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));

        $dateById = [];
        foreach ($this->orderService->getPending() as $order) {
            $dateById[(int) $order['id']] = (string) $order['expected_shipping_date'];
        }
        $dates = [];
        foreach ($ids as $id) {
            if (!isset($dateById[$id])) {
                return 'Zamówienie #' . $id . ' nie oczekuje już na realizację — odśwież stronę.';
            }
            $dates[$dateById[$id]] = true;
        }
        if (count($dates) > self::MAX_DATES) {
            return 'Wybierz maksymalnie ' . self::MAX_DATES . ' dni wysyłki.';
        }

        return $ids;
    }
}
