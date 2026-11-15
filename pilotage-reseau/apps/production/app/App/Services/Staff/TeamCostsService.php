<?php
declare(strict_types=1);

namespace App\Services\Staff;

use App\Exceptions\CustomException;
use App\Repositories\Staff\TeamBonusRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use DateTimeImmutable;

/**
 * [267] « Koszty i premie » (onglet de Czas pracy, session Kierownik produkcji) : lecture seule, rien n'est décidé ici.
 *
 * Par groupe et par personne : coût employeur du mois (production_employee.cost), ventes du groupe et par personne,
 * palier de prime atteint et ce qui manque jusqu'au suivant, prime par personne (calculs de TeamBonusService, les mêmes
 * que l'onglet « Premia » des tablettes), chiffre d'affaires manquant pour l'objectif de coût du personnel (page
 * Rentowność). Prime du Kierownik : manager.sales_bonus_pct % du chiffre d'affaires général du mois (0,3 %, chaque mois —
 * décision du 11/11/2026 ; pas de prime trimestrielle).
 *
 * Option de l'administrateur : manager.show_bonuses = 0 → les primes (groupes, personnes, Kierownik) ne sortent pas du
 * serveur pour un compte qui n'est pas administrateur.
 */
final class TeamCostsService
{
    public const MAX_PCT = 5.0;

    public function __construct(
        private TeamBonusService $bonus,
        private TeamBonusRepository $repo,
        private ProfitabilityService $profitability,
        private ParamRepository $params,
    ) {
    }

    /** @return array{show_bonuses:bool, bonus_pct:float} */
    public function settings(): array
    {
        $pct = $this->params->get('manager.sales_bonus_pct', '0.3');
        return [
            'show_bonuses' => $this->params->get('manager.show_bonuses', '1') === '1',
            'bonus_pct' => is_numeric($pct) && (float) $pct >= 0 ? min(self::MAX_PCT, (float) $pct) : 0.0,
        ];
    }

    /** Administrator (Premie zespołów) : montrer les primes au Kierownik, taux de sa prime mensuelle. */
    public function saveSettings(array $input, ?int $userId): void
    {
        if (array_key_exists('manager_bonus_pct', $input)) {
            $pct = trim(str_replace([' ', "\u{00a0}", ',', '%'], ['', '', '.', ''], (string) $input['manager_bonus_pct']));
            if ($pct === '') $pct = '0';
            if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > self::MAX_PCT) {
                throw new CustomException('Premia kierownika produkcji : % sprzedaży firmy od 0 do ' . rtrim(rtrim(number_format(self::MAX_PCT, 1, ',', ''), '0'), ',') . '.', 'errors', 422);
            }
            $this->params->setValue('manager.sales_bonus_pct', (string) round((float) $pct, 3), $userId);
        }
        if (array_key_exists('manager_show_bonuses', $input)) {
            $this->params->setValue('manager.show_bonuses', filter_var($input['manager_show_bonuses'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', $userId);
        }
    }

    /**
     * @param bool $admin l'administrateur voit toujours les primes
     * @return array vue du mois en cours (état à ce jour et prévision au rythme actuel), montants en zł
     */
    public function view(bool $admin, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        $showBonuses = $admin || $settings['show_bonuses'];
        $bonus = $this->bonus->overview($today);
        $profit = $this->profitability->overview('month', $today);
        $rate = (float) $profit['rate'] > 0 ? (float) $profit['rate'] : 1.0;
        $elapsed = (float) ($profit['period']['elapsed'] ?? 1.0);
        $bySection = array_column($profit['groups'], null, 'id');
        $costs = $this->repo->employerCosts();
        $remaining = TeamBonusService::workingDaysAfter($today);
        $bonusSettings = ['min' => $bonus['min'], 'tiers' => $bonus['tiers'], 'prorata' => $bonus['prorata']];

        $groups = [];
        $totals = ['people' => 0, 'cost' => 0.0, 'bonus' => 0.0];
        $seen = [];
        foreach ($bonus['groups'] as $g) {
            $now = $g['current'];
            $team = $this->bonus->persons($g['id'], (float) ($now['bonus_projection'] ?? 0.0), $bonusSettings, $today);
            $persons = [];
            $cost = 0.0;
            $groupBonus = 0.0;
            foreach ($team['persons'] as $p) {
                $c = $costs[$p['id']] ?? null;
                $cost += $c ?? 0.0;
                $groupBonus += $p['amount'];
                $row = ['id' => $p['id'], 'name' => $p['name'], 'cost' => $c, 'days' => $p['days']];
                if ($showBonuses) $row += ['share' => $p['share'], 'bonus' => $p['amount']];
                $persons[] = $row;
                // Une personne de deux groupes (équipe) : comptée une fois dans le total.
                if (!isset($seen[$p['id']])) { $seen[$p['id']] = true; $totals['people']++; $totals['cost'] += $c ?? 0.0; }
            }
            $pg = $g['section_id'] !== null ? ($bySection[$g['section_id']] ?? null) : null;
            $next = TeamBonusService::nextStep($now, $bonusSettings, $remaining);
            $row = [
                'id' => $g['id'], 'name' => $g['name'], 'color' => $g['color'],
                'people' => count($persons), 'group_days' => $team['group_days'], 'cost' => round($cost),
                'revenue' => $now['revenue_pln'],
                'revenue_projection' => $now['available'] && $now['projection'] !== null && $now['people'] ? round($now['projection'] * $now['people']) : null,
                'per_person' => $now['per_person'], 'per_person_projection' => $now['projection'],
                // Objectif de coût du personnel du groupe (Rentowność) : ventes nécessaires et ce qui manque.
                'staff_target' => $pg['staff_target'] ?? null,
                'staff_pct' => $pg['staff_pct'] ?? null,
                'missing_target' => $pg !== null ? round($pg['missing'] * $rate) : null,
                'persons' => $persons,
            ];
            if ($showBonuses) {
                $row += [
                    'tier' => $now['tier_projection'] !== null ? ['index' => $now['tier_projection'] + 1, 'from' => $bonus['tiers'][$now['tier_projection']]['from']] : null,
                    'below_min' => $now['available'] && $now['projection'] !== null && $now['projection'] < $bonus['min'],
                    'next' => $next === null ? null : ['from' => $next['from'], 'amount' => $next['amount'], 'missing' => $next['missing_group']],
                    'bonus_person' => $now['bonus_projection'] ?? 0.0,
                    'bonus_group' => round($groupBonus),
                ];
                $totals['bonus'] += $groupBonus;
            }
            $groups[] = $row;
        }

        $t = $profit['totals'];
        $revenue = (float) $t['revenue'] * $rate;
        $projection = $t['projection'] !== null ? (float) $t['projection'] * $rate : $revenue;
        $out = [
            'month' => $today->format('Y-m'),
            'label' => ProfitabilityService::monthLabel($today->format('Y-m')),
            'day' => (int) $today->format('j'), 'days' => (int) $today->format('t'), 'elapsed' => $elapsed, 'remaining_days' => $remaining,
            'show_bonuses' => $showBonuses,
            'min' => $bonus['min'],
            'company' => [
                'revenue' => round($revenue), 'projection' => round($projection),
                'staff' => round((float) $t['staff_full'] * $rate),
                'staff_pct' => $projection > 0 ? round((float) $t['staff_full'] * $rate / $projection * 100, 1) : null,
                'staff_target' => $t['staff_target'],
                'missing' => $t['staff_missing'] !== null ? round((float) $t['staff_missing'] * $rate) : null,
            ],
            'groups' => $groups,
            'totals' => ['people' => $totals['people'], 'cost' => round($totals['cost'])] + ($showBonuses ? ['bonus' => round($totals['bonus'])] : []),
            'warnings' => $profit['warnings'] ?? [],
        ];
        if ($showBonuses) {
            $pct = $settings['bonus_pct'];
            $out['manager'] = ['pct' => $pct, 'now' => round($revenue * $pct / 100), 'projection' => round($projection * $pct / 100)];
        }
        return $out;
    }
}
