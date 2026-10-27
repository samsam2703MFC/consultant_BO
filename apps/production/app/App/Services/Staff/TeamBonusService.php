<?php
namespace App\Services\Staff;

use App\Exceptions\CustomException;
use App\Core\TransactionManager;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Staff\TeamBonusRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use DateTimeImmutable;

/**
 * Premie zespołów (progi) : sprzedaż grupy w miesiącu ÷ osoby grupy = sprzedaż na osobę — ta sama liczba co
 * kolumna « Sprzedaż na osobę » strony Rentowność (sprzedaż WDT działu grupy ÷ osoby opłacone w miesiącu,
 * ProfitabilityService::monthly), przeliczona na zł po kursie firmy. Od minimum (bonus.min_per_person_pln) próg
 * osiągnięty daje premię na osobę (n_bonus_tier). Bieżący miesiąc : stan na dziś i prognoza w obecnym tempie.
 *
 * Premia osoby = premia progu × dni obecności ÷ dni pracy grupy (odbicia na tabletach) przy bonus.presence_prorata ;
 * grupa bez odbić w miesiącu : cała premia progu. Zakładka « Premia » tabletu : tylko grupy z bonus_visible.
 * Ten sam miesiąc rok wcześniej (N-1) : kreska na wskaźniku tabletu i kolumna strony ERP ; bez osób opłaconych
 * w tamtym miesiącu sprzedaż N-1 dzielona przez osoby bieżącego miesiąca (people_estimated).
 */
final class TeamBonusService
{
    public function __construct(
        private ProfitabilityService $profitability,
        private ProfitabilityRepository $profit,
        private TeamBonusRepository $repo,
        private ParamRepository $params,
        private TransactionManager $tx,
    ) {
    }

    public const INFO_MAX = 500;

    /** @return array{min:float, threshold:float, prorata:bool, show_people:bool, info_pl:string, info_fr:string, tiers:list<array{id:int, from:float, amount:float}>} */
    public function settings(): array
    {
        $min = $this->params->get('bonus.min_per_person_pln', '30000');
        $min = is_numeric($min) && (float) $min > 0 ? (float) $min : 30000.0;
        $tiers = $this->repo->tiers();
        $first = self::nextTier(null, $min, $tiers);
        return [
            'min' => $min,
            // Pierwsza sprzedaż na osobę, która daje premię : minimum albo pierwszy próg, jeśli wyżej.
            'threshold' => max($min, $first['from'] ?? $min),
            'prorata' => $this->params->get('bonus.presence_prorata', '1') === '1',
            'show_people' => $this->params->get('bonus.show_people', '1') === '1',
            // Informacja (i) w zakładce tabletu (np. coboty nie liczą się jako osoby) ; pusta = brak.
            'info_pl' => trim($this->params->get('bonus.info_pl', '')),
            'info_fr' => trim($this->params->get('bonus.info_fr', '')),
            'tiers' => $tiers,
        ];
    }

    /**
     * Próg osiągnięty przy danej sprzedaży na osobę : najwyższy próg ≤ sprzedaż, nie niższy niż minimum.
     * @param list<array{from:float, amount:float}> $tiers rosnąco
     * @return ?array{index:int, from:float, amount:float}
     */
    public static function tierFor(?float $perPerson, float $min, array $tiers): ?array
    {
        if ($perPerson === null || $perPerson < $min) return null;
        $hit = null;
        foreach (array_values($tiers) as $i => $t) {
            if ($t['from'] <= $perPerson + 1e-6) $hit = ['index' => $i, 'from' => $t['from'], 'amount' => $t['amount']];
        }
        return $hit;
    }

    /** Pierwszy próg powyżej danej sprzedaży na osobę (następny cel), od minimum. */
    public static function nextTier(?float $perPerson, float $min, array $tiers): ?array
    {
        foreach (array_values($tiers) as $i => $t) {
            if ($t['from'] >= $min && ($perPerson === null || $t['from'] > $perPerson + 1e-6)) return ['index' => $i, 'from' => $t['from'], 'amount' => $t['amount']];
        }
        return null;
    }

    /** Strona ERP : ustawienia, grupy z widocznością, bieżący miesiąc (stan i prognoza) i poprzedni (zamknięty). */
    public function overview(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        [$previous, $current, $rate, $lastYear] = $this->months($today);
        $groups = [];
        foreach ($this->repo->groups() as $g) {
            $section = $g['id_section'] !== null ? (int) $g['id_section'] : null;
            $now = $this->figures($current, $section, $rate, $settings);
            $groups[] = [
                'id' => (int) $g['id'], 'name' => $g['name_pl'], 'color' => $g['color'], 'visible' => (bool) $g['bonus_visible'],
                'section' => $section !== null,
                'current' => $now,
                'previous' => $this->figures($previous, $section, $rate, $settings),
                'last_year' => $this->lastYear($lastYear, $section, $rate, $settings, $now),
            ];
        }
        return $settings + [
            'rate' => $rate,
            'groups' => $groups,
            'current_label' => ProfitabilityService::monthLabel($current['ym']),
            'previous_label' => ProfitabilityService::monthLabel($previous['ym']),
            'last_year_label' => ProfitabilityService::monthLabel($lastYear['ym']),
            'elapsed' => $this->profitability->period('month', $today)['elapsed'],
        ];
    }

    /**
     * Zakładka « Premia » tabletu dla grupy : null, gdy zakładka grupy wyłączona.
     * $employeeId : osoba zalogowana na tablecie (jej wiersz « ja » ; bez bonus.show_people tylko ten wiersz).
     */
    public function forGroup(int $groupId, ?int $employeeId, ?DateTimeImmutable $today = null): ?array
    {
        $group = $this->repo->group($groupId);
        if ($group === null || !(int) $group['bonus_visible']) return null;
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        [$previous, $current, $rate, $lastYear] = $this->months($today);
        $section = $group['id_section'] !== null ? (int) $group['id_section'] : null;
        $now = $this->figures($current, $section, $rate, $settings);
        $before = $this->figures($previous, $section, $rate, $settings);
        $first = $today->modify('first day of this month');
        $remaining = self::workingDaysAfter($today);

        $next = $now['available'] ? self::nextTier($now['projection'], $settings['min'], $settings['tiers']) : null;
        if ($next !== null) {
            // Ile sprzedaży grupy jeszcze do końca miesiąca, żeby na koniec mieć próg (od stanu na dziś).
            $missing = max(0.0, $next['from'] * $now['people'] - $now['revenue_pln']);
            $next += ['missing_group' => round($missing), 'missing_per_person' => round($missing / $now['people']),
                'per_day' => $remaining > 0 ? round($missing / $remaining) : null];
        }

        $presence = $this->repo->presence($groupId, $first->format('Y-m-d'), $today->format('Y-m-d'));
        $amount = $now['bonus_projection'] ?? 0.0;
        $persons = [];
        foreach ($presence['people'] as $p) {
            $share = $settings['prorata'] && $presence['group_days'] > 0 ? min(1.0, $p['days'] / $presence['group_days']) : 1.0;
            $row = ['id' => $p['id'], 'name' => $p['name'], 'days' => $p['days'], 'share' => round($share, 3),
                'amount' => round($amount * $share), 'me' => $p['id'] === $employeeId];
            if ($settings['show_people'] || $row['me']) $persons[] = $row;
        }

        return [
            'group' => ['id' => (int) $group['id'], 'name_pl' => $group['name_pl'], 'name_fr' => $group['name_fr'] ?: $group['name_pl']],
            'ym' => $current['ym'],
            'previous_ym' => $previous['ym'],
            'elapsed' => $this->profitability->period('month', $today)['elapsed'],
            'remaining_days' => $remaining,
            'min' => $settings['min'],
            'threshold' => $settings['threshold'],
            'tiers' => array_map(fn($t) => ['from' => $t['from'], 'amount' => $t['amount'], 'below_min' => $t['from'] < $settings['min'],
                'reached' => $now['per_person'] !== null && $t['from'] >= $settings['min'] && $now['per_person'] + 1e-6 >= $t['from'],
                'projected' => $now['projection'] !== null && $t['from'] >= $settings['min'] && $now['projection'] + 1e-6 >= $t['from']], $settings['tiers']),
            'current' => $now,
            'previous' => $before,
            'last_year_ym' => $lastYear['ym'],
            'last_year' => $this->lastYear($lastYear, $section, $rate, $settings, $now),
            'info_pl' => $settings['info_pl'],
            'info_fr' => $settings['info_fr'],
            'next' => $next,
            'prorata' => $settings['prorata'] && $presence['group_days'] > 0,
            'group_days' => $presence['group_days'],
            'show_people' => $settings['show_people'],
            'persons' => $persons,
            'rate' => $rate,
        ];
    }

    public function saveSettings(array $input, ?int $userId): void
    {
        $num = fn($v) => trim(str_replace([' ', "\u{00a0}", ','], ['', '', '.'], (string) $v));
        $min = $num($input['min'] ?? '');
        if (!is_numeric($min) || (float) $min <= 0 || (float) $min > 10000000) {
            throw new CustomException('Minimum : sprzedaż na osobę w zł (więcej niż 0).', 'errors', 422);
        }
        $min = round((float) $min, 2);
        $tiers = [];
        foreach ((array) ($input['tiers'] ?? []) as $i => $t) {
            $from = $num($t['from'] ?? '');
            $amount = $num($t['amount'] ?? '');
            if ($from === '' && $amount === '') continue;
            $n = count($tiers) + 1;
            if (!is_numeric($from) || (float) $from <= 0 || (float) $from > 10000000) throw new CustomException("Próg {$n} : sprzedaż na osobę w zł.", 'errors', 422);
            if (!is_numeric($amount) || (float) $amount < 0 || (float) $amount > 1000000) throw new CustomException("Próg {$n} : premia na osobę w zł (0 lub więcej).", 'errors', 422);
            if ((float) $from < $min) throw new CustomException("Próg {$n} (" . number_format((float) $from, 0, ',', ' ') . ' zł) jest poniżej minimum ' . number_format($min, 0, ',', ' ') . ' zł.', 'errors', 422);
            $tiers[] = ['from' => round((float) $from, 2), 'amount' => round((float) $amount, 2)];
        }
        if (!$tiers) throw new CustomException('Dodaj co najmniej jeden próg premii.', 'errors', 422);
        usort($tiers, fn($a, $b) => $a['from'] <=> $b['from']);
        for ($i = 1; $i < count($tiers); $i++) {
            if (abs($tiers[$i]['from'] - $tiers[$i - 1]['from']) < 0.005) throw new CustomException('Dwa progi od tej samej sprzedaży na osobę.', 'errors', 422);
        }
        $flag = fn($k, $default) => array_key_exists($k, $input) ? (filter_var($input[$k], FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : $default;
        $info = [];
        foreach (['info_pl', 'info_fr'] as $k) {
            if (!array_key_exists($k, $input)) continue;
            $text = trim(preg_replace('/\s+/u', ' ', (string) $input[$k]));
            if (mb_strlen($text) > self::INFO_MAX) throw new CustomException('Informacja (i) : najwyżej ' . self::INFO_MAX . ' znaków.', 'errors', 422);
            $info['bonus.' . $k] = $text;
        }
        $this->tx->execute(function () use ($min, $tiers, $userId, $input, $flag, $info) {
            $this->params->setValue('bonus.min_per_person_pln', (string) $min, $userId);
            $this->params->setValue('bonus.presence_prorata', $flag('prorata', $this->params->get('bonus.presence_prorata', '1')), $userId);
            $this->params->setValue('bonus.show_people', $flag('show_people', $this->params->get('bonus.show_people', '1')), $userId);
            foreach ($info as $code => $text) $this->params->setValue($code, $text, $userId);
            $this->repo->replaceTiers($tiers, $userId);
        });
    }

    public function setVisible(int $groupId, mixed $visible): void
    {
        if ($this->repo->group($groupId) === null) throw new CustomException('Nie ma takiej aktywnej grupy.', 'errors', 404);
        $this->repo->setVisible($groupId, filter_var($visible, FILTER_VALIDATE_BOOLEAN));
    }

    /** Dni robocze (pon.–pt.) po dzisiejszym do końca miesiąca. */
    public static function workingDaysAfter(DateTimeImmutable $today): int
    {
        $n = 0;
        $last = (int) $today->format('t');
        for ($d = (int) $today->format('j') + 1; $d <= $last; $d++) {
            if ((int) $today->setDate((int) $today->format('Y'), (int) $today->format('n'), $d)->format('N') <= 5) $n++;
        }
        return $n;
    }

    /** @return array{0:array, 1:array, 2:float, 3:array} poprzedni miesiąc, bieżący, kurs EUR → zł, ten sam miesiąc rok wcześniej */
    private function months(DateTimeImmutable $today): array
    {
        $rate = $this->profit->eurRate();
        $rate = $rate > 0 ? $rate : 1.0;
        $monthly = $this->profitability->monthly($today, $rate, 13);
        return [$monthly[11], $monthly[12], $rate, $monthly[0]];
    }

    /** Ten sam miesiąc rok wcześniej : sprzedaż na osobę ; bez osób w tamtym miesiącu — osoby bieżącego miesiąca. */
    private function lastYear(array $month, ?int $section, float $rate, array $settings, array $now): array
    {
        $f = $this->figures($month, $section, $rate, $settings) + ['people_estimated' => false];
        if (!$f['available'] && $f['revenue_pln'] !== null && $f['revenue_pln'] > 0 && $now['people'] !== null && $now['people'] > 0) {
            $f['per_person'] = round($f['revenue_pln'] / $now['people']);
            $f['projection'] = $f['per_person'];
            $f['people'] = $now['people'];
            $f['available'] = true;
            $f['people_estimated'] = true;
        }
        return $f;
    }

    /** Sprzedaż grupy, osoby, sprzedaż na osobę (stan i prognoza) i premia progu w danym miesiącu serii. */
    private function figures(array $month, ?int $section, float $rate, array $settings): array
    {
        $g = $section !== null ? ($month['groups'][$section] ?? null) : null;
        $people = $g !== null && $g['people'] !== null ? (float) $g['people'] : 0.0;
        if ($g === null || $people <= 0) {
            return ['available' => false, 'people' => $people > 0 ? round($people, 1) : null, 'revenue_pln' => $g !== null ? round($g['revenue'] * $rate) : null,
                'per_person' => null, 'projection' => null, 'tier' => null, 'tier_projection' => null, 'bonus' => null, 'bonus_projection' => null];
        }
        $revenue = $g['revenue'] * $rate;
        $perPerson = $revenue / $people;
        // Bieżący miesiąc : sprzedaż na osobę w obecnym tempie (jak Rentowność) ; zamknięty : sprzedaż ÷ osoby.
        $projection = $g['revenue_per_person'] !== null ? $g['revenue_per_person'] * $rate : $perPerson;
        if (!$month['current']) $projection = $perPerson;
        $tier = self::tierFor($perPerson, $settings['min'], $settings['tiers']);
        $tierProjection = self::tierFor($projection, $settings['min'], $settings['tiers']);
        return [
            'available' => true, 'people' => round($people, 1), 'revenue_pln' => round($revenue), 'per_person' => round($perPerson), 'projection' => round($projection),
            'tier' => $tier['index'] ?? null, 'tier_projection' => $tierProjection['index'] ?? null,
            'bonus' => $tier['amount'] ?? 0.0, 'bonus_projection' => $tierProjection['amount'] ?? 0.0,
        ];
    }
}
