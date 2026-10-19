<?php
namespace App\Services\Staff;

use App\Exceptions\CustomException;
use App\Repositories\Staff\StaffRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;

/**
 * Budżet personelu działu = suma wynagrodzeń minimalnych + pula premii.
 *
 * Pula premii = % sprzedaży działu (pay.bonus_pool_pct albo % działu). Premia na osobę = pula ÷
 * aktywne osoby z prawem do premii. Dział wspólny bez własnej sprzedaży (pakowanie, administracja) :
 * bez własnego % jego osoby dostają średnią premię działów produkcyjnych na osobę ; z własnym % —
 * % sprzedaży całej firmy.
 * Przy takim modelu koszt personelu / sprzedaż = minimum / sprzedaż + %, więc sprzedaż potrzebna
 * do celu T to minimum ÷ (T − %) — osiągalna tylko, gdy % premii < T.
 *
 * Osoba bez podanego minimum : cały obecny koszt liczony jako minimum (ostrożnie, sygnalizowane).
 * Kwoty w EUR jak strona „Rentowność” (sprzedaż WDT), wynagrodzenia w PLN po kursie firmy.
 */
final class StaffBudgetService
{
    public function __construct(
        private ProfitabilityService $profitability,
        private StaffRepository $staff,
        private ParamRepository $params,
    ) {
    }

    public function overview(string $period = 'month'): array
    {
        $profit = $this->profitability->overview($period);
        $employees = $this->staff->employees();
        $budget = $this->fromOverview($profit, $employees);
        return [
            'period' => $profit['period'],
            'options' => $profit['options'],
            'rate' => $profit['rate'],
            'sections' => $budget['sections'],
            'totals' => $budget['totals'],
            'default_pct' => $budget['default_pct'],
            'notices' => array_values(array_unique([...$profit['warnings'], ...$budget['warnings']])),
        ];
    }

    /**
     * @param array $overview résultat de ProfitabilityService::overview / compute
     * @return array{sections:array<int,array>, totals:array, default_pct:float, warnings:list<string>}
     */
    public function fromOverview(array $overview, ?array $employees = null): array
    {
        $employees ??= $this->staff->employees();
        $rate = (float) $overview['rate'] > 0 ? (float) $overview['rate'] : 1.0;
        $months = $overview['period']['months'];
        $pace = array_sum($months);
        $full = count($months);
        $elapsed = (float) ($overview['period']['elapsed'] ?? 1.0);
        $current = (bool) $overview['period']['current'];
        $default = $this->defaultPct();
        $groups = array_column($overview['groups'], null, 'id');
        $totalRevenue = (float) $overview['totals']['revenue'];

        $bySection = [];
        foreach ($employees as $e) $bySection[(int) $e['id_section']][] = $e;
        $isEligible = fn($e) => (int) $e['active'] === 1 && (int) $e['bonus_eligible'] === 1;

        // Średnia premia na osobę działów produkcyjnych (dla działów wspólnych bez własnego %).
        $productionPool = 0.0;
        $productionEligible = 0;
        foreach ($overview['sections'] as $id => $s) {
            if ($s['is_shared']) continue;
            $productionPool += (float) $s['revenue'] * ($s['bonus_pct'] !== null ? (float) $s['bonus_pct'] : $default) / 100;
            $productionEligible += count(array_filter($bySection[$id] ?? [], $isEligible));
        }
        $averagePerPerson = $productionEligible > 0 ? $productionPool / $productionEligible : 0.0;

        $sections = [];
        $missingBase = 0;
        foreach ($overview['sections'] as $id => $s) {
            $people = $bySection[$id] ?? [];
            if (!$people && (float) $s['revenue'] == 0.0) continue;
            $shared = (bool) $s['is_shared'];
            $pct = $s['bonus_pct'] !== null ? (float) $s['bonus_pct'] : $default;
            $basis = $shared ? $totalRevenue : (float) $s['revenue'];

            $active = array_values(array_filter($people, fn($e) => (int) $e['active'] === 1));
            $eligible = array_values(array_filter($people, $isEligible));
            $baseMonth = 0.0;
            $costMonth = 0.0;
            $aboveMin = 0.0;
            $noBase = 0;
            foreach ($active as $e) {
                $cost = (float) $e['cost'];
                $costMonth += $cost;
                if ($e['base_monthly'] === null) {
                    $noBase++;
                    $baseMonth += $cost;
                } else {
                    $baseMonth += (float) $e['base_monthly'];
                    $aboveMin += $cost - (float) $e['base_monthly'];
                }
            }
            $missingBase += $noBase;

            $basePace = $baseMonth / $rate * $pace;
            $baseFull = $baseMonth / $rate * $full;
            $count = count($eligible);
            $average = $shared && $s['bonus_pct'] === null;
            $pool = $average ? $averagePerPerson * $count : $basis * $pct / 100;
            $budget = $basePace + $pool;
            $revenue = (float) $s['revenue'];
            // Premia miesięczna na osobę : pula okresu ÷ miesiące upłynięte (mies. bieżący = w obecnym tempie).
            $monthly = $count && $pace > 0 ? $pool / $pace / $count * $rate : null;

            $target = $shared ? null : ($groups[$id]['staff_target'] ?? null);
            $needed = $target !== null && $target > $pct ? $baseFull / (($target - $pct) / 100) : null;
            $poolAtTarget = $needed !== null ? $needed * $pct / 100 : null;

            $sections[$id] = [
                'id' => $id,
                'name' => $s['name'],
                'shared' => $shared,
                'pct' => $average ? null : $pct,
                'pct_custom' => $s['bonus_pct'] !== null,
                'average' => $average,
                'basis' => $basis,
                'revenue' => $revenue,
                'people' => count($people),
                'active' => count($active),
                'eligible' => $count,
                'no_base' => $noBase,
                'base_month_pln' => $baseMonth,
                'cost_month_pln' => $costMonth,
                'above_min_pln' => $aboveMin,
                'base' => $basePace,
                'base_full' => $baseFull,
                'pool' => $pool,
                'pool_projection' => $current && $elapsed > 0 ? $pool / $elapsed : null,
                'per_person' => $count ? $pool / $count : null,
                'per_person_pln' => $count ? $pool / $count * $rate : null,
                'per_person_projection_pln' => $count && $current && $elapsed > 0 ? $pool / $elapsed / $count * $rate : null,
                'budget' => $budget,
                'budget_pct' => $revenue > 0 && !$shared ? $budget / $revenue * 100 : null,
                'actual' => (float) $s['staff'],
                'actual_pct' => $revenue > 0 && !$shared ? (float) $s['staff'] / $revenue * 100 : null,
                'target' => $target,
                'reachable' => $target === null || $target > $pct,
                'needed' => $needed,
                'missing' => $needed !== null ? max(0.0, $needed - $revenue) : null,
                'per_person_at_target_pln' => $poolAtTarget !== null && $count ? $poolAtTarget / $count * $rate : null,
                'per_person_month_pln' => $monthly,
                'employees' => array_map(fn($e) => $this->employeeRow($e, $monthly), $people),
            ];
        }
        uasort($sections, fn($a, $b) => [$a['shared'], -$a['revenue']] <=> [$b['shared'], -$b['revenue']]);

        $totals = [
            'people' => array_sum(array_column($sections, 'people')),
            'active' => array_sum(array_column($sections, 'active')),
            'base' => array_sum(array_column($sections, 'base')),
            'pool' => array_sum(array_column($sections, 'pool')),
            'budget' => array_sum(array_column($sections, 'budget')),
            'actual' => array_sum(array_column($sections, 'actual')),
            'revenue' => $totalRevenue,
        ];
        $totals['budget_pct'] = $totalRevenue > 0 ? $totals['budget'] / $totalRevenue * 100 : null;
        $totals['actual_pct'] = $totalRevenue > 0 ? $totals['actual'] / $totalRevenue * 100 : null;

        $warnings = [];
        if ($missingBase > 0) {
            $warnings[] = "Wynagrodzenie minimalne nie jest podane dla {$missingBase} os. — do czasu uzupełnienia cały ich obecny koszt liczony jest jako minimum.";
        }
        return ['sections' => $sections, 'totals' => $totals, 'default_pct' => $default, 'warnings' => $warnings];
    }

    /** Minimum, udział w premii, nieobecność dłuższa ; puste minimum = nie podano. */
    public function savePay(int $employeeId, array $input, ?int $userId): array
    {
        $employee = $this->staff->employee($employeeId);
        if ($employee === null) throw new CustomException('Nie znaleziono pracownika.', 'errors', 404);
        $data = ['updated_by' => $userId];
        if (array_key_exists('base_monthly', $input)) {
            $raw = trim(str_replace([' ', ','], ['', '.'], (string) ($input['base_monthly'] ?? '')));
            if ($raw === '') {
                $data['base_monthly'] = null;
            } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000000) {
                throw new CustomException('Wynagrodzenie minimalne: kwota w zł (0 lub więcej).', 'errors', 422);
            } else {
                $data['base_monthly'] = round((float) $raw, 2);
            }
        }
        foreach (['bonus_eligible', 'active'] as $flag) {
            if (array_key_exists($flag, $input)) $data[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('notes', $input)) {
            $notes = trim((string) $input['notes']);
            $data['notes'] = $notes === '' ? null : mb_substr($notes, 0, 255);
        }
        $this->staff->savePay($employeeId, $data);
        $base = $data['base_monthly'] ?? null;
        return [
            'above_min' => $base !== null ? (float) $employee['cost'] - $base : null,
            'warning' => $base !== null && $base > (float) $employee['cost'] ? 'Minimum wyższe niż obecny koszt pracownika — sprawdź koszt w kartotece.' : null,
        ];
    }

    public function saveDefaultPct(mixed $value, ?int $userId): void
    {
        $raw = trim(str_replace(',', '.', (string) $value));
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw >= 100) {
            throw new CustomException('Pula premii: od 0 do 100 % sprzedaży.', 'errors', 422);
        }
        $this->params->setValue('pay.bonus_pool_pct', (string) round((float) $raw, 2), $userId);
    }

    public function defaultPct(): float
    {
        $value = $this->params->get('pay.bonus_pool_pct', '5');
        return is_numeric($value) && (float) $value >= 0 && (float) $value < 100 ? (float) $value : 5.0;
    }

    /** @param ?float $bonusPln premia miesięczna na osobę (zł) */
    private function employeeRow(array $e, ?float $bonusPln): array
    {
        $base = $e['base_monthly'] !== null ? (float) $e['base_monthly'] : null;
        $gets = (int) $e['active'] === 1 && (int) $e['bonus_eligible'] === 1;
        return [
            'id' => (int) $e['id'],
            'name' => trim($e['name'] . ' ' . $e['surname']),
            'cost' => (float) $e['cost'],
            'base' => $base,
            'above_min' => $base !== null ? (float) $e['cost'] - $base : null,
            'active' => (int) $e['active'] === 1,
            'bonus_eligible' => (int) $e['bonus_eligible'] === 1,
            'notes' => $e['notes'],
            'bonus_pln' => $gets ? $bonusPln : null,
            'model_pln' => $gets && $bonusPln !== null ? ($base ?? (float) $e['cost']) + $bonusPln : null,
        ];
    }
}
