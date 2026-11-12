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
        private \App\Repositories\Workshop\TimeRepository $time,
        private GroupWeekProfitService $groupWeeks,
        private \App\Services\Organization\PageAccessService $access,
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
        // Mój tydzień : zadania stałe stanowiska dzień po dniu (minuty, link, zrobione) — plan ustala administrator.
        $week = $userId ? $this->tasks->week($userId) : null;
        $names = array_column($this->tasks->positions(), 'name_pl', 'code');
        $data['todo'] = $this->today->markTodo($data['todo'], $week['user']['position_code'] ?? null, $names);
        $data['member'] = $week['user'] ?? null;
        $data['week'] = $week;
        try {
            $data['profit'] = $this->profitability->snapshot();
        } catch (\Throwable $e) {
            $data['profit'] = null;
        }
        // [256] Osoba na stanowisku kierownika produkcji : tylko « Moja karta stanowiska » (A4 : misje, godziny, cele),
        // bez wyników i realizacji. Wskaźniki kierownika (wynik, cel, realizacja) — dyrekcja (prod.manager.read).
        $data['manager_card'] = false;
        try {
            $data['manager_card'] = $userId && $this->manager->card($userId) !== null;
            $canRead = $userId && in_array('prod.manager.read', array_column($this->auth->rolePermissions($userId), 'permission'), true);
            $data['manager_kpis'] = $userId && !$data['manager_card'] && $canRead ? $this->manager->dashboard($userId, true) : null;
        } catch (\Throwable $e) {
            $data['manager_kpis'] = null;
        }
        // [284] Produkcja (stanowisko albo sesja kierownika produkcji) : Dzisiaj = tylko zadania (bez wskaźników, rentowności
        // grup, planu wysyłek, « Do zrobienia » i ostatnich zamówień — są w swoich modułach).
        $data['tasks_focus'] = ($week['user']['position_code'] ?? null) === 'KIEROWNIK_PRODUKCJI';
        // [285] Minuty zadań (przydziela administrator, makieta 39) widzi tylko administrator — pracownik widzi swoje zadania.
        // Na razie także administrator w sesji stanowiska (widok operatora) ich nie widzi.
        $data['show_minutes'] = $this->access->showTaskMinutes($userId);
        if (!$data['show_minutes'] && is_array($data['week'])) $data['week'] = self::withoutMinutes($data['week']);
        $this->view('dashboard/dashboard', $data);
    }

    /** [285] Bez minut (klucze minutes / done_minutes) — Mój tydzień osoby, która nie jest administratorem. */
    private static function withoutMinutes(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($k === 'minutes' || $k === 'done_minutes') $a[$k] = is_array($v) ? $v : null;
            elseif (is_array($v)) $a[$k] = self::withoutMinutes($v);
        }
        return $a;
    }

    /** Liczniki przy pozycjach menu i wskaźnik rentowności w górnym pasku (assets/js/notifications.js). */
    public function ajaxCounters()
    {
        return $this->json($this->today->counters() + ['mail' => $this->userId() ? $this->mail->unread($this->userId()) : 0, 'queue' => $this->queueBadge(), 'profit' => $this->profitBadge(),
            'time' => $this->timeBadge()]);
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
        return $this->sessionCached('tfb_profit_badge', function () {
            $t = $this->profitability->overview('month')['totals'];
            return $t['staff_pct'] === null ? null : [
                'staff_pct' => round($t['staff_pct'], 1),
                'target' => $t['staff_target'],
                'missing' => round($t['staff_missing']),
                'ok' => $t['staff_ok'],
            ];
        });
    }

    /** [253] Licznik « Do obsłużenia » (pozycja Zamówienia i jej zakładka), najwyżej raz na 5 minut na sesję. */
    private function queueBadge(): int
    {
        return (int) $this->sessionCached('tfb_queue_badge', fn() => $this->today->queueTotal());
    }

    /** Wynik liczony najwyżej raz na 5 minut na sesję ; błąd = null. */
    private function sessionCached(string $key, callable $compute): mixed
    {
        $cached = $_SESSION[$key] ?? null;
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - 300) {
            return $cached['data'];
        }
        try {
            $data = $compute();
        } catch (\Throwable $e) {
            $data = null;
        }
        // Liczniki (/ajax/rail-counters) działają z zamkniętą sesją : na chwilę ją otwieramy, by zapisać wynik.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$key] = ['at' => time(), 'data' => $data];
        } elseif (!headers_sent() && session_start()) {
            $_SESSION[$key] = ['at' => time(), 'data' => $data];
            session_write_close();
        }
        return $data;
    }

    /** [262] Czas pracy : dni z odbiciami z ostatnich 31 dni (przed dzisiaj) do zatwierdzenia — dla tych, którzy widzą stronę. */
    private function timeBadge(): int
    {
        if (!$this->userId() || !\App\Core\Web\PageAccess::visible('/time')) return 0;
        try {
            return $this->time->daysToValidate(date('Y-m-d', strtotime('-31 days')), date('Y-m-d'));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
