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
     * GET /finances/sales-analytics — [254] « czy sprzedajemy więcej niż rok temu ? » (makiety 32 + 34) :
     * werdykt, przyciski działów (sprzedaż i marża), miesiące obok roku wcześniej, produkty w górę / w dół.
     * Domyślnie « Ten rok » (do ostatniego zamkniętego miesiąca) ; section_id = strona jednego działu.
     */
    public function overview(): void
    {
        $request    = Request::createFromGlobals();
        $customerId = $this->customerId($request);
        $sectionId  = $this->positiveInt($request, 'section_id');
        $presets    = $this->presets();
        $default    = array_column($presets, null, 'key')['year'];
        $dateFrom   = $this->month($request->query->get('date_from'), $default['from']);
        $dateTo     = $this->month($request->query->get('date_to'), $default['to']);
        if ($dateTo < $dateFrom) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

        $data = [
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'customer_id' => $customerId ?? 0,
            'customers'   => $this->salesAnalyticsService->getFilterableCustomers(),
            'section_id'  => $sectionId ?? 0,
            'presets'     => $presets,
            'page'        => $this->salesAnalyticsService->overview($dateFrom, $dateTo, $customerId, $sectionId),
            'margin_low'  => SalesAnalyticsService::MARGIN_LOW,
        ];

        $this->view('finances/sales-analytics/overview', $data);
    }

    /** Miesiąc RRRR-MM z zapytania albo wartość domyślna. */
    private function month(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : $default;
    }

    /**
     * Szybkie okresy (miesiące RRRR-MM) : ten miesiąc, ten kwartał, ten rok do ostatniego zamkniętego
     * miesiąca (w styczniu : styczeń), ostatnie 12 zamkniętych miesięcy.
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    private function presets(?int $now = null): array
    {
        $now ??= time();
        $month = date('Y-m', $now);
        $lastClosed = date('Y-m', strtotime(date('Y-m-01', $now) . ' -1 month'));
        $quarterStart = date('Y', $now) . '-' . sprintf('%02d', (int) (floor(((int) date('n', $now) - 1) / 3) * 3 + 1));
        $year = date('Y', $now);
        return [
            ['key' => '12m', 'label' => 'Ostatnie 12 mies.', 'title' => 'ostatnie 12 zamkniętych miesięcy', 'from' => date('Y-m', strtotime(date('Y-m-01', $now) . ' -12 months')), 'to' => $lastClosed],
            ['key' => 'year', 'label' => 'Ten rok', 'title' => 'od stycznia do ostatniego zamkniętego miesiąca', 'from' => $year . '-01', 'to' => $lastClosed >= $year . '-01' ? $lastClosed : $month],
            ['key' => 'quarter', 'label' => 'Ten kwartał', 'title' => 'do dziś', 'from' => $quarterStart, 'to' => $month],
            ['key' => 'month', 'label' => 'Ten miesiąc', 'title' => 'do dziś, obok tych samych dni rok temu', 'from' => $month, 'to' => $month],
        ];
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
        return $this->positiveInt($request, 'customer_id');
    }

    private function positiveInt(Request $request, string $key): ?int
    {
        $value = filter_var($request->query->get($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }
}

