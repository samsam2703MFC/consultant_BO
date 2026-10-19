<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Web\BaseController;
use App\Services\Finances\SectionFinanceDashboardService;

class SectionFinanceDashboardController extends BaseController
{
    public function __construct(
        private SectionFinanceDashboardService $dashboardService
    ) {
    }

    /**
     * GET /finances/section-dashboard
     */
    public function overview(): void
    {
        // Zapobiegamy cachowaniu GET przez przeglądarkę
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

        $defaults = $this->dashboardService->getDefaultDateRange();

        // Używamy $_GET bezpośrednio — Symfony Request::createFromGlobals()
        // w tym projekcie (routing przez ?url=...) może nie parsować poprawnie
        // query stringa gdy inne kontrolery też go modyfikują.
        $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== ''
            ? trim($_GET['date_from'])
            : $defaults['from'];

        $dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== ''
            ? trim($_GET['date_to'])
            : $defaults['to'];

        // Walidacja formatu YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $dateFrom)) {
            $dateFrom = $defaults['from'];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $dateTo)) {
            $dateTo = $defaults['to'];
        }

        // Upewnij się że from <= to
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $dashboard = $this->dashboardService->getDashboard($dateFrom, $dateTo);
        $trend     = $this->dashboardService->getMonthlyTrend($dateFrom, $dateTo);

        $this->view('finances/section-dashboard/overview', [
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'sections'      => $dashboard['sections'],
            'totals'        => $dashboard['totals'],
            'trend'         => $trend,
            'exchange_rate' => $dashboard['exchange_rate'],
        ]);
    }
}

