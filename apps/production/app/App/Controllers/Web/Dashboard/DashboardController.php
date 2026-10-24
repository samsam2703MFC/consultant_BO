<?php
namespace App\Controllers\Web\Dashboard;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Dashboard\GroupWeekProfitService;
use App\Services\Dashboard\GrowthActionService;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Dashboard\TodayService;
use App\Services\Organization\PositionTaskService;
use App\Services\Staff\StaffBudgetService;
use Symfony\Component\HttpFoundation\Request;
use App\Services\Workshop\Manager\ManagerService;
use App\Repositories\Workshop\AuthRepository;
use App\Repositories\Mail\UserMailRepository;

class DashboardController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private TodayService $today,
        private ProfitabilityService $profitability,
        private GrowthActionService $growth,
        private StaffBudgetService $staffBudget,
        private PositionTaskService $tasks,
        private ManagerService $manager,
        private AuthRepository $auth,
        private UserMailRepository $mail,
        private GroupWeekProfitService $groupWeeks,
    ) {}

    /** Strona startowa: rentowność produkcji (sprzedaż a koszt personelu na dział, brakująca sprzedaż do celu). */
    /** Pulpit rentowności : trzy liczby i grupy (personel / sprzedaż wobec celu, brakująca sprzedaż). */
    public function index()
    {
        $this->profitView('dashboard/profitability');
    }

    /** Wynik i produkcja grup : szczegóły grup, co wyprodukowały, od sprzedaży do wyniku, zakupy, 6 miesięcy, na osobę. */
    public function results()
    {
        $this->profitView('dashboard/results');
    }

    /** Rentowność grup : tydzień i miesiąc (godziny, produkcja, marża, sprzedaż), cobot i 12 tygodni na wykresie. */
    public function groups()
    {
        $this->view('dashboard/groups', $this->groupWeeks->overview());
    }

    private function profitView(string $view): void
    {
        $period = (string) ($_GET['period'] ?? 'month');
        $data = $this->profitability->overview($period);
        // view() nadpisuje „warnings” (komunikaty kontrolera) : ostrzeżenia o danych idą jako „notices”.
        $data['notices'] = $data['warnings'];
        unset($data['warnings']);
        $this->view($view, $data + [
            'selected' => $data['period']['key'],
            'growth' => $this->growth->summaryBySection(),
            'staff_budget' => $this->staffBudget->fromOverview($data)['sections'],
        ]);
    }

    /** „Dzisiaj”: wysyłki, braki produkcji, reklamacje, proformy. */
    /**
     * Dzisiaj : zadania stanowiska użytkownika, „Do zrobienia” oznaczone według stanowisk i rentowność
     * grup bieżącego miesiąca (personel / sprzedaż wobec celu, brakująca sprzedaż w €).
     */
    public function today()
    {
        $data = $this->today->overview();
        $userId = $this->userId();
        $mine = $userId ? $this->tasks->myTasks($userId) : ['user' => null, 'groups' => [], 'overdue' => [], 'counts' => []];
        $names = array_column($this->tasks->positions(), 'name_pl', 'code');
        $data['todo'] = $this->today->markTodo($data['todo'], $mine['user']['position_code'] ?? null, $names);
        $data['member'] = $mine['user'];
        $data['my_counts'] = $mine['counts'];
        $data['my_tasks'] = $this->openTasks($mine);
        try {
            $data['profit'] = $this->profitability->snapshot();
        } catch (\Throwable $e) {
            $data['profit'] = null;
        }
        // Moje wskaźniki : osoba na stanowisku kierownika produkcji (plan roku w module kierownika).
        try {
            $canRead = $userId && in_array('prod.manager.read', array_column($this->auth->rolePermissions($userId), 'permission'), true);
            $data['manager_kpis'] = $userId ? $this->manager->dashboard($userId, $canRead) : null;
        } catch (\Throwable $e) {
            $data['manager_kpis'] = null;
        }
        $this->view('dashboard/dashboard', $data);
    }

    /** Zadania stanowiska do zrobienia : zaległe, potem okres bieżący według terminu. */
    private function openTasks(array $mine): array
    {
        $items = array_map(fn($o) => ['task' => $o['task'], 'occurrence' => $o, 'overdue' => true], $mine['overdue']);
        $current = [];
        foreach ($mine['groups'] as $group) {
            foreach ($group['items'] as $it) {
                if ($it['occurrence'] && $it['occurrence']['status'] === 'open') $current[] = $it + ['overdue' => false];
            }
        }
        usort($current, fn($a, $b) => $a['occurrence']['due_date'] <=> $b['occurrence']['due_date']);
        return array_merge($items, $current);
    }

    /** Liczniki przy pozycjach menu i wskaźnik rentowności w górnym pasku (assets/js/notifications.js). */
    public function ajaxCounters()
    {
        return $this->json($this->today->counters() + ['mail' => $this->userId() ? $this->mail->unread($this->userId()) : 0, 'profit' => $this->profitBadge()]);
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
        // Liczniki (/ajax/rail-counters) działają z zamkniętą sesją : na chwilę ją otwieramy, by zapisać wynik.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['tfb_profit_badge'] = ['at' => time(), 'data' => $data];
        } elseif (!headers_sent() && session_start()) {
            $_SESSION['tfb_profit_badge'] = ['at' => time(), 'data' => $data];
            session_write_close();
        }
        return $data;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
