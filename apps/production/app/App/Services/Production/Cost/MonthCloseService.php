<?php
namespace App\Services\Production\Cost;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\MarginRecalcRepository;
use App\Repositories\Production\Cost\CostMonthSectionRepository;
use App\Repositories\Production\Cost\MonthCloseRepository;
use App\Services\Analysis\MonthlyReportService;
use App\Services\Workshop\Manager\ManagerService;

/**
 * Assistant « Zamknięcie miesiąca » (audit 2, P14) : un mois de coûts fermé en 8 étapes — 1 kartony, 2 personel
 * (budżet, M-1, godziny z tabletów), 3 media, 4 koszty stałe i logistyka, 5 inwentaryzacja surowców, 6 przeliczenie
 * marż i stanów min. / maks., 7 wpisy kierownika produkcji, 8 zamknięcie i raport miesięczny. Le service ne fait que
 * lire l'état de chaque étape ; les actions réutilisent les fonctions existantes (calculateur de coûts, marges,
 * fiches produits, rapport). Lire l'état ne crée rien : un mois absent du calculateur se démarre explicitement.
 */
class MonthCloseService
{
    public const STEPS = ['cartons', 'staff', 'media', 'fixed', 'inventory', 'recalc', 'manager', 'close'];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    /** Dopełniacz (« z lipca 2026 ») do kopii kosztów. */
    private const MONTHS_GEN = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    /** Inventaire « de fin de mois » : du 7e jour avant la fin du mois au 10e jour du mois suivant. */
    private const INVENTORY_BEFORE_DAYS = 7;
    private const INVENTORY_AFTER_DAYS = 10;
    /** Recalcul « frais » : fait au plus tôt 6 jours avant la fin du mois. */
    private const RECALC_BEFORE_DAYS = 6;
    private const LOCKED = 'Miesiąc jest zamknięty — żeby coś zmienić, otwórz go ponownie w kalkulatorze kosztów.';

    public function __construct(
        private MonthCloseRepository $repo,
        private CostMonthService $months,
        private CostCalculatorService $calc,
        private CostMonthSectionRepository $sections,
        private MarginRecalcRepository $margins,
        private ManagerService $manager,
        private MonthlyReportService $reports,
        private TransactionManager $tx,
    ) {
    }

    /** Mois à fermer par défaut : le mois précédent. */
    public static function defaultMonth(?string $today = null): string
    {
        return date('Y-m', strtotime(($today ?? date('Y-m-d')) . ' first day of last month'));
    }

    public static function label(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    public static function labelGenitive(string $ym): string
    {
        return self::MONTHS_GEN[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    /** @return array{0:int,1:int} année, mois ; 422 si le mois est invalide ou à venir. */
    public static function parse(string $ym, ?string $today = null): array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m) || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
            throw new CustomException('Nieprawidłowy miesiąc (RRRR-MM).', 'errors', 422);
        }
        if ($ym > substr($today ?? date('Y-m-d'), 0, 7)) throw new CustomException('Miesiąc jeszcze się nie zaczął.', 'errors', 422);
        return [(int) $m[1], (int) $m[2]];
    }

    /** État des 8 étapes du mois. */
    public function status(string $ym, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        [$year, $month] = self::parse($ym, $today);
        $from = $ym . '-01';
        $to = date('Y-m-t', strtotime($from));
        $row = $this->repo->month($year, $month);
        [$py, $pm] = $this->months->getPrevYearMonth($year, $month);
        $prev = $this->repo->month($py, $pm);
        $monthId = $row ? (int) $row['id'] : null;
        $locked = $row !== null && $row['status'] === 'LOCKED';

        $steps = [
            'cartons' => $this->cartons($monthId, $from, $to),
            'staff' => $this->staff($monthId, $prev ? (int) $prev['id'] : null, $from, $to),
            'media' => $this->costs('media', $monthId, $year, $month),
            'fixed' => $this->fixedAndLogistics($monthId, $year, $month),
            'inventory' => $this->inventory($to),
            'recalc' => $this->recalc($to),
            'manager' => $this->managerEntries($ym),
        ];
        $open = array_keys(array_filter($steps, fn($s) => !in_array($s['state'], ['done', 'skip'], true)));
        $steps['close'] = $this->close($ym, $row, $open);
        $last12 = [];
        for ($k = 0; $k < 12; $k++) $last12[] = date('Y-m', strtotime($from . " -{$k} month"));
        $statuses = $this->repo->statuses($last12);
        return [
            'month' => $ym, 'label' => self::label($ym), 'from' => $from, 'to' => $to, 'partial' => $to >= $today,
            'exists' => $row !== null, 'month_id' => $monthId, 'locked' => $locked,
            'locked_at' => $row['locked_at'] ?? null, 'locked_by' => $row['locked_by_name'] ?? null,
            'prev_month' => sprintf('%04d-%02d', $py, $pm),
            'steps' => $steps,
            'done' => count(array_filter($steps, fn($s) => in_array($s['state'], ['done', 'skip'], true))),
            'next' => array_values(array_filter(self::STEPS, fn($k) => !in_array($steps[$k]['state'], ['done', 'skip'], true)))[0] ?? null,
            'months' => array_map(fn($m) => ['ym' => $m, 'label' => self::label($m), 'status' => $statuses[$m]], $last12),
        ];
    }

    /** 1. Kartony działów : zapisane w miesiącu i sprzedane w WDT (porównanie). */
    private function cartons(?int $monthId, string $from, string $to): array
    {
        $saved = $monthId ? $this->sections->getByMonthId($monthId) : [];
        $wdt = $this->calc->previewWdtCartons($from, $to);
        $bySection = [];
        foreach ($wdt as $w) $bySection[(int) $w['section_id']] = ['name' => $w['section_name'], 'saved' => null, 'source' => null, 'wdt' => (int) $w['cartons_qty']];
        foreach ($saved as $s) {
            $sid = (int) $s['section_id'];
            $bySection[$sid] = ['name' => $s['section_name'], 'saved' => (int) $s['cartons_qty'], 'source' => $s['source'], 'wdt' => $bySection[$sid]['wdt'] ?? 0];
        }
        $total = array_sum(array_map(fn($r) => (int) $r['saved'], $bySection));
        $wdtTotal = array_sum(array_column($bySection, 'wdt'));
        $sources = array_values(array_unique(array_filter(array_column(array_filter($bySection, fn($r) => $r['saved']), 'source'))));
        $manual = count(array_filter($bySection, fn($r) => $r['source'] === 'MANUAL'));
        // Gotowe : kartony zapisane. Uwaga : różnią się od WDT o więcej niż 2 % (bez wpisów ręcznych, które są wyborem).
        $gap = $wdtTotal > 0 && !$manual ? abs($total - $wdtTotal) / $wdtTotal : 0;
        return ['state' => $total <= 0 ? 'todo' : ($gap > 0.02 ? 'warn' : 'done'), 'total' => $total, 'wdt_total' => $wdtTotal,
            'sources' => $sources, 'manual' => $manual, 'sections' => array_values($bySection)];
    }

    /** 2. Personel : koszt miesiąca, budżet (karta pracownika), M-1 i godziny z tabletów. */
    private function staff(?int $monthId, ?int $prevId, string $from, string $to): array
    {
        $hours = $this->repo->tabletHours($from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00');
        $tablet = ['hours' => round(array_sum(array_column($hours, 'hours')), 1), 'people' => array_sum(array_column($hours, 'people')),
            'open' => array_sum(array_column($hours, 'open')), 'unlinked_hours' => $hours[0]['hours'] ?? 0.0];
        $prev = $prevId ? $this->repo->staffTotal($prevId) : ['count' => 0, 'total' => 0.0];
        if (!$monthId) return ['state' => 'todo', 'count' => 0, 'total' => 0.0, 'prev' => $prev, 'budget_diff' => 0, 'missing' => null, 'tablet' => $tablet, 'rows' => []];
        $rows = array_map(function ($r) use ($hours) {
            $ref = $r['employee_ref_id'] !== null ? (int) $r['employee_ref_id'] : null;
            return ['name' => $r['name'], 'section' => $r['section'], 'cost' => (float) $r['monthly_cost'],
                'budget' => $r['budget_cost'] !== null ? (float) $r['budget_cost'] : null, 'prev' => $r['prev_cost'] !== null ? (float) $r['prev_cost'] : null,
                'hours' => $ref !== null && isset($hours[$ref]) ? $hours[$ref]['hours'] : null,
                'differs' => $r['budget_cost'] !== null && abs((float) $r['monthly_cost'] - (float) $r['budget_cost']) > 0.005];
        }, $this->repo->staff($monthId, $prevId));
        $diff = count(array_filter($rows, fn($r) => $r['differs']));
        $missing = $this->repo->staffMissing($monthId);
        $total = round(array_sum(array_column($rows, 'cost')), 2);
        return ['state' => !$rows || $total <= 0 ? 'todo' : ($diff || $missing ? 'warn' : 'done'), 'count' => count($rows), 'total' => $total,
            'prev' => $prev, 'budget_diff' => $diff, 'missing' => $missing, 'tablet' => $tablet, 'rows' => $rows];
    }

    /** 3. Media (i część kroku 4) : wiersze miesiąca, M-1 i źródło kopii (ostatni miesiąc z wierszami). */
    private function costs(string $category, ?int $monthId, int $year, int $month): array
    {
        $cur = $monthId ? $this->repo->categoryTotal($category, $monthId) : ['count' => 0, 'total' => 0.0];
        $src = $this->repo->latestWith($category, $year, $month);
        $source = $src ? ['ym' => sprintf('%04d-%02d', $src['year'], $src['month'])] + $this->repo->categoryTotal($category, (int) $src['id']) : null;
        if ($source) $source += ['label' => self::label($source['ym']), 'label_gen' => self::labelGenitive($source['ym'])];
        return ['state' => $cur['count'] > 0 ? 'done' : 'todo', 'count' => $cur['count'], 'total' => $cur['total'], 'source' => $source];
    }

    /** 4. Koszty stałe i logistyka. */
    private function fixedAndLogistics(?int $monthId, int $year, int $month): array
    {
        $fixed = $this->costs('fixed', $monthId, $year, $month);
        $logistics = $this->costs('logistics', $monthId, $year, $month);
        $done = ($fixed['count'] > 0) + ($logistics['count'] > 0);
        return ['state' => $done === 2 ? 'done' : ($done === 1 ? 'warn' : 'todo'), 'fixed' => $fixed, 'logistics' => $logistics];
    }

    /** 5. Inwentaryzacja surowców : arkusz inwentaryzacji w okolicy końca miesiąca. */
    private function inventory(string $to): array
    {
        $winFrom = date('Y-m-d', strtotime($to . ' -' . self::INVENTORY_BEFORE_DAYS . ' days'));
        $winTo = date('Y-m-d', strtotime($to . ' +' . self::INVENTORY_AFTER_DAYS . ' days'));
        $last = $this->repo->lastInventories(3);
        $inWindow = array_values(array_filter($last, fn($i) => $i['count_date'] >= $winFrom && $i['count_date'] <= $winTo))[0] ?? null;
        return ['state' => $inWindow ? 'done' : ($last ? 'warn' : 'todo'), 'window' => ['from' => $winFrom, 'to' => $winTo],
            'inventory' => $inWindow, 'last' => $last[0] ?? null, 'counted_in_window' => $this->repo->countedMaterials($winFrom, $winTo)];
    }

    /** 6. Przeliczenie marż (ceny surowców) i stanów min. / maks. (sprzedaż) po ostatnich danych miesiąca. */
    private function recalc(string $to): array
    {
        $since = date('Y-m-d', strtotime($to . ' -' . self::RECALC_BEFORE_DAYS . ' days')) . ' 00:00:00';
        $run = $this->margins->lastRun();
        $stocks = $this->repo->stockCalc();
        $marginsFresh = $run !== null && $run['run_at'] >= $since;
        // « Przelicz stany » zapisuje wszystkie produkty MTS ze sprzedażą naraz : ostatnie obliczenie w okresie = przeliczone.
        $stocksFresh = $stocks['mts'] === 0 || ($stocks['latest'] !== null && $stocks['latest'] >= $since);
        return ['state' => $marginsFresh && $stocksFresh ? 'done' : ($marginsFresh || $stocksFresh ? 'warn' : 'todo'), 'since' => $since,
            'margins' => ['fresh' => $marginsFresh, 'run' => $run, 'changes' => $run ? $this->repo->marginChanges($run['run_at'], 10) : []],
            'stocks' => ['fresh' => $stocksFresh] + $stocks];
    }

    /** 7. Kierownik produkcji : wpisy ręczne miesiąca i 3 działania ; bez planu roku krok jest pomijany. */
    private function managerEntries(string $ym): array
    {
        try {
            $e = $this->manager->monthEntries($ym);
        } catch (\Throwable $ex) {
            error_log('[month-close] manager entries: ' . $ex->getMessage());
            return ['state' => 'warn', 'error' => true, 'plan' => false, 'holder' => null, 'missing' => [], 'unverified' => [], 'actions' => 0];
        }
        $state = !$e['plan'] ? 'skip' : ($e['missing'] || !$e['actions'] ? 'warn' : 'done');
        return ['state' => $state, 'error' => false] + $e;
    }

    /** 8. Zamknięcie miesiąca i raport : zamknięty ? raport wysłany (ręcznie albo automatycznie) ? */
    private function close(string $ym, ?array $row, array $open): array
    {
        $settings = $this->reports->settings();
        $sent = $this->repo->reportsSent($ym);
        $ok = array_values(array_filter($sent, fn($m) => $m['status'] !== 'failed'));
        $locked = $row !== null && $row['status'] === 'LOCKED';
        return ['state' => $locked && $ok ? 'done' : ($locked ? 'warn' : 'todo'), 'locked' => $locked, 'open_steps' => $open,
            'settings' => $settings, 'sent' => $sent, 'sent_ok' => count($ok)];
    }

    // --- Actions propres à l'assistant (les autres appellent les routes existantes) ------------------------------

    /** Démarre le mois dans le calculateur (créé s'il manque) avec le personnel de la liste et ses coûts du budget. */
    public function start(string $ym): array
    {
        [$year, $month] = self::parse($ym);
        $m = $this->months->getOrCreate($year, $month);
        if (($m['status'] ?? 'OPEN') === 'LOCKED') throw new CustomException(self::LOCKED, 'errors', 409);
        $this->months->ensureDefaultEmployees((int) $m['id']);
        $synced = $this->months->syncEmployeesFromBudget((int) $m['id']);
        return ['month_id' => (int) $m['id'], 'synced' => $synced];
    }

    /**
     * Copie une catégorie (media, fixed, logistics) depuis le dernier mois qui en a. Refusée (409) si le mois est
     * fermé ou si la catégorie a déjà des lignes : jamais de doublons ni d'écrasement d'une saisie.
     */
    public function copyCategory(string $ym, string $category): array
    {
        if (!isset(MonthCloseRepository::CATEGORIES[$category])) throw new CustomException('Nieznana kategoria kosztów.', 'errors', 422);
        [$year, $month] = self::parse($ym);
        $row = $this->repo->month($year, $month) ?? throw new CustomException('Miesiąc nie jest jeszcze rozpoczęty w kalkulatorze kosztów.', 'errors', 404);
        $monthId = (int) $row['id'];
        if ($row['status'] === 'LOCKED') throw new CustomException(self::LOCKED, 'errors', 409);
        if ($this->repo->categoryTotal($category, $monthId)['count'] > 0) throw new CustomException('Ta kategoria ma już koszty w tym miesiącu — popraw je w kalkulatorze.', 'errors', 409);
        $src = $this->repo->latestWith($category, $year, $month) ?? throw new CustomException('Brak wcześniejszego miesiąca z kosztami tej kategorii.', 'errors', 404);
        $this->tx->execute(fn() => $this->repo->copyCategory($category, (int) $src['id'], $monthId, $ym));
        $after = $this->repo->categoryTotal($category, $monthId);
        return ['copied' => $after['count'], 'total' => $after['total'], 'from' => sprintf('%04d-%02d', $src['year'], $src['month'])];
    }
}
