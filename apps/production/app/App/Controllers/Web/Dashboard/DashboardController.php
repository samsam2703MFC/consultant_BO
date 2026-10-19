<?php
namespace App\Controllers\Web\Dashboard;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Dashboard\GrowthActionService;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Dashboard\TodayService;
use App\Services\Staff\StaffBudgetService;
use Symfony\Component\HttpFoundation\Request;

class DashboardController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private TodayService $today,
        private ProfitabilityService $profitability,
        private GrowthActionService $growth,
        private StaffBudgetService $staffBudget,
    ) {}

    /** Strona startowa: rentowność produkcji (sprzedaż a koszt personelu na dział, brakująca sprzedaż do celu). */
    public function index()
    {
        $period = (string) ($_GET['period'] ?? 'month');
        $data = $this->profitability->overview($period);
        // view() nadpisuje „warnings” (komunikaty kontrolera) : ostrzeżenia o danych idą jako „notices”.
        $data['notices'] = $data['warnings'];
        unset($data['warnings']);
        $this->view('dashboard/profitability', $data + [
            'selected' => $data['period']['key'],
            'growth' => $this->growth->summaryBySection(),
            'staff_budget' => $this->staffBudget->fromOverview($data)['sections'],
        ]);
    }

    /** „Dzisiaj”: wysyłki, braki produkcji, reklamacje, proformy. */
    public function today()
    {
        $this->view('dashboard/dashboard', $this->today->overview());
    }

    /** Liczniki przy pozycjach menu i wskaźnik rentowności w górnym pasku (assets/js/notifications.js). */
    public function ajaxCounters()
    {
        return $this->json($this->today->counters() + ['profit' => $this->profitBadge()]);
    }

    public function saveTargets()
    {
        try {
            $this->profitability->saveGeneralTargets($this->getJson(Request::createFromGlobals()), $this->userId());
            unset($_SESSION['tfb_profit_badge']);
            return $this->json(['message' => 'Zapisano cele.']);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 422);
        }
    }

    public function saveSectionTarget($id)
    {
        try {
            $this->profitability->saveTarget((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            unset($_SESSION['tfb_profit_badge']);
            return $this->json(['message' => 'Zapisano cel działu.']);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 422);
        }
    }

    /** Okno grupy : sytuacja miesięczna, działania rozwoju sprzedaży, produkty grupy. */
    public function growthGroup($id)
    {
        try {
            return $this->json(['data' => $this->growth->group((int) $id, (string) ($_GET['period'] ?? 'month'))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveGrowth($id = null)
    {
        try {
            $action = $this->growth->save($this->getJson(Request::createFromGlobals()), $id === null ? null : (int) $id, $this->userId());
            return $this->json(['message' => 'Zapisano działanie.', 'data' => $action]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Wskaźnik bieżącego miesiąca, liczony najwyżej raz na 5 minut na sesję. */
    private function profitBadge(): ?array
    {
        $cached = $_SESSION['tfb_profit_badge'] ?? null;
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - 300) {
            return $cached['data'];
        }
        try {
            $t = $this->profitability->overview('month')['totals'];
            $data = $t['staff_pct'] === null ? null : [
                'staff_pct' => round($t['staff_pct'], 1),
                'target' => $t['staff_target'],
                'missing' => round($t['staff_missing']),
                'ok' => $t['staff_ok'],
            ];
        } catch (\Throwable $e) {
            $data = null;
        }
        $_SESSION['tfb_profit_badge'] = ['at' => time(), 'data' => $data];
        return $data;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
