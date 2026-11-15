<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * [277] Calcul d'un mois de paie selon le droit polonais (barèmes de l'année : n_pay_rate, chacun avec sa source).
 *
 * Umowa o pracę :
 *  - salaire du mois = salaire de base × jours ouvrés sous contrat ÷ jours ouvrés du mois ; L4 : − base ÷ 30 par jour
 *    calendaire de maladie et + wynagrodzenie chorobowe = (moyenne brute 12 mois − 13,71 %) ÷ 30 × 80 % par jour
 *    (Kodeks pracy art. 92 ; au-delà de 33 jours dans l'année c'est le zasiłek ZUS : signalé, pas calculé) ; absence
 *    non justifiée : − taux horaire × 8 h par jour ;
 *  - heures supplémentaires payées : taux horaire (base ÷ heures nominales du mois) × 150 % ou × 200 % (art. 151¹) ;
 *  - ZUS salarié : emerytalna 9,76 % et rentowa 1,5 % jusqu'au plafond annuel (30-krotność), chorobowa 2,45 % ;
 *    pas de ZUS social sur le wynagrodzenie chorobowe ;
 *  - zdrowotna 9 % de (brut − ZUS salarié) ;
 *  - zaliczka PIT : (brut − ZUS − KUP 250 / 300) arrondi au złoty × 12 % (32 % au-delà de 120 000 zł dans l'année)
 *    − 300 zł si PIT-2, arrondie au złoty ; ulga dla młodych (< 26 ans) : revenu exonéré jusqu'à 85 528 zł par an ;
 *  - employeur : emerytalna 9,76 %, rentowa 6,5 % (plafond), wypadkowa (taux de la société), FP 2,45 % et FGŚP 0,10 %
 *    (pas de FP / FGŚP à partir de 55 ans pour une femme et 60 ans pour un homme ; pas de FP sous le salaire minimum).
 * Umowa zlecenie : heures pointées × taux horaire ; ZUS emerytalna + rentowa (chorobowa si choisie), zdrowotna 9 %,
 *  KUP 20 %, PIT 12 % − 300 zł si PIT-2 ; étudiant de moins de 26 ans : ni ZUS ni zdrowotna (et ulga dla młodych).
 *
 * Arrondis : cotisations au grosz, base et avance d'impôt au złoty (Ordynacja podatkowa art. 63). Le résultat est à
 * vérifier par le bureau comptable avant la première paie (affiché sur la page et la fiche).
 */
final class PayrollCalculator
{
    /**
     * @param array $e personne : kind (praca|zlecenie), pay_type (monthly|hourly), pay_amount, fraction (wymiar), pit2,
     *                 kup_raised, young (ulga possible), student, sick_insurance, fp_exempt (âge)
     * @param array $m mois : workdays, employed_workdays, hours (zlecenie), sick_days, unpaid_days, sick_base (moyenne
     *                 brute 12 mois ou null), ytd [pension_base, tax_base, exempt_revenue, sick_days]
     * @param array $a saisies : overtime50_h, overtime100_h, bonus, deduction
     * @param array<string, float> $r barèmes de l'année
     */
    public static function compute(array $e, array $m, array $a, array $r): array
    {
        $kind = $e['kind'] === 'zlecenie' ? 'zlecenie' : 'praca';
        $ytd = ($m['ytd'] ?? []) + ['pension_base' => 0.0, 'tax_base' => 0.0, 'exempt_revenue' => 0.0, 'sick_days' => 0];
        $warn = [];
        $earn = [];   // składniki wynagrodzenia : [label, amount, zus (bool)]
        $hourly = 0.0;
        $sickDays = max(0, (int) ($m['sick_days'] ?? 0));
        $sickPay = 0.0;

        if ($kind === 'praca') {
            $base = (float) $e['pay_amount'];
            if (($e['pay_type'] ?? 'monthly') === 'hourly') {
                $hours = (float) ($m['hours'] ?? 0);
                $earn[] = ['Wynagrodzenie godzinowe (' . self::h($hours) . ' h × ' . self::pl($base) . ')', self::r2($hours * $base), true];
                $hourly = $base;
            } else {
                $workdays = max(1, (int) $m['workdays']);
                $fraction = max(0.05, min(1.0, (float) ($e['fraction'] ?? 1)));
                $nominal = $workdays * 8 * $fraction;
                $hourly = $base / $nominal;
                $employed = max(0, min($workdays, (int) ($m['employed_workdays'] ?? $workdays)));
                $salary = $employed === $workdays ? $base : self::r2($base * $employed / $workdays);
                $earn[] = ['Wynagrodzenie zasadnicze' . ($employed < $workdays ? ' (' . $employed . '/' . $workdays . ' dni rob.)' : ''), $salary, true];
                if ($sickDays > 0) {
                    $cut = self::r2(min($salary, $base / 30 * $sickDays));
                    $earn[] = ['Potrącenie za czas choroby (' . $sickDays . ' dni × 1/30)', -$cut, true];
                    $avg = (float) ($m['sick_base'] ?? 0) > 0 ? (float) $m['sick_base'] : $base;
                    if ((float) ($m['sick_base'] ?? 0) <= 0) $warn[] = 'Podstawa chorobowego : wynagrodzenie zasadnicze (brak 12 miesięcy list płac w ERP) — sprawdzić.';
                    $daily = $avg * (1 - ($r['emp_pension'] + $r['emp_disability'] + $r['emp_sickness']) / 100) / 30;
                    $paidDays = max(0, min($sickDays, (int) $r['sick_days_employer'] - (int) $ytd['sick_days']));
                    $sickPay = self::r2($daily * $r['sick_pay'] / 100 * $paidDays);
                    $earn[] = ['Wynagrodzenie chorobowe (' . $paidDays . ' dni × 80 %)', $sickPay, false];
                    if ($paidDays < $sickDays) $warn[] = 'Choroba ponad ' . (int) $r['sick_days_employer'] . ' dni w roku : zasiłek chorobowy z ZUS — rozliczyć w biurze rachunkowym.';
                }
                $unpaid = max(0, (int) ($m['unpaid_days'] ?? 0));
                if ($unpaid > 0) $earn[] = ['Nieobecność nieusprawiedliwiona (' . $unpaid . ' dni)', -self::r2(min($salary, $hourly * 8 * $unpaid)), true];
                if ($fraction >= 1 && $employed === $workdays && $base < $r['min_wage']) $warn[] = 'Wynagrodzenie niższe niż płaca minimalna (' . self::pl($r['min_wage']) . ' zł).';
            }
            $o50 = max(0.0, (float) ($a['overtime50_h'] ?? 0));
            $o100 = max(0.0, (float) ($a['overtime100_h'] ?? 0));
            if ($o50 > 0) $earn[] = ['Nadgodziny ' . self::h($o50) . ' h × 150 %', self::r2($o50 * $hourly * 1.5), true];
            if ($o100 > 0) $earn[] = ['Nadgodziny ' . self::h($o100) . ' h × 200 %', self::r2($o100 * $hourly * 2), true];
        } else {
            $rate = (float) $e['pay_amount'];
            if (($e['pay_type'] ?? 'hourly') === 'monthly') {
                $earn[] = ['Wynagrodzenie z umowy zlecenia', self::r2($rate), true];
            } else {
                $hours = (float) ($m['hours'] ?? 0);
                $earn[] = ['Umowa zlecenie : ' . self::h($hours) . ' h × ' . self::pl($rate), self::r2($hours * $rate), true];
                if ($hours <= 0) $warn[] = 'Brak godzin w Czasie pracy w tym miesiącu — sprawdź odbicia.';
                if ($rate < $r['min_hourly']) $warn[] = 'Stawka niższa niż minimalna stawka godzinowa (' . self::pl($r['min_hourly']) . ' zł).';
            }
            $hourly = $rate;
        }
        $bonus = max(0.0, (float) ($a['bonus'] ?? 0));
        if ($bonus > 0) $earn[] = ['Premia', self::r2($bonus), true];

        $gross = self::r2(array_sum(array_column($earn, 1)));
        $zusBase = max(0.0, self::r2($gross - $sickPay));
        $student = $kind === 'zlecenie' && !empty($e['student']) && !empty($e['young']);

        // --- ZUS salarié (plafond annuel pour emerytalna / rentowa)
        $capLeft = max(0.0, $r['zus_cap'] - (float) $ytd['pension_base']);
        $pensionBase = $student ? 0.0 : min($zusBase, $capLeft);
        if (!$student && $pensionBase < $zusBase) $warn[] = 'Przekroczony roczny limit podstawy składek emerytalnej i rentowej (' . self::pl($r['zus_cap']) . ' zł).';
        $sickOn = !$student && ($kind === 'praca' || !empty($e['sick_insurance']));
        $zus = [
            'pension' => self::r2($pensionBase * $r['emp_pension'] / 100),
            'disability' => self::r2($pensionBase * $r['emp_disability'] / 100),
            'sickness' => $sickOn ? self::r2($zusBase * $r['emp_sickness'] / 100) : 0.0,
        ];
        $zusEmp = self::r2(array_sum($zus));

        // --- Zdrowotna
        $healthBase = $student ? 0.0 : max(0.0, self::r2($gross - $zusEmp));
        $health = self::r2($healthBase * $r['health'] / 100);

        // --- PIT (ulga dla młodych, KUP, progi)
        $exempt = 0.0;
        if (!empty($e['young'])) $exempt = min($gross, max(0.0, $r['young_limit'] - (float) $ytd['exempt_revenue']));
        $taxableShare = $gross > 0 ? ($gross - $exempt) / $gross : 0.0;
        $income = max(0.0, ($gross - $exempt) - $zusEmp * $taxableShare);
        if ($kind === 'praca') $kup = $income > 0 ? min($income, $e['kup_raised'] ? $r['kup_raised'] : $r['kup']) : 0.0;
        else $kup = self::r2($income * $r['kup_civil'] / 100);
        $taxBase = $income > 0 ? (float) max(0, round($income - $kup)) : 0.0;
        $low = max(0.0, min($taxBase, $r['pit_threshold'] - (float) $ytd['tax_base']));
        $tax = $low * $r['pit_low'] / 100 + ($taxBase - $low) * $r['pit_high'] / 100;
        $reduction = $taxBase > 0 && !empty($e['pit2']) ? $r['pit_reduction'] : 0.0;
        $pit = (float) max(0, round($tax - $reduction));
        if ($taxBase - $low > 0) $warn[] = 'Przekroczony próg ' . self::pl($r['pit_threshold']) . ' zł : 32 % od nadwyżki.';

        $deduction = max(0.0, (float) ($a['deduction'] ?? 0));
        $net = self::r2($gross - $zusEmp - $health - $pit - $deduction);
        if ($net < 0) $warn[] = 'Potrącenia większe niż wynagrodzenie netto.';

        // --- Pracodawca
        $fp = !$student && empty($e['fp_exempt']) && $zusBase >= $r['min_wage'] * ($kind === 'praca' ? max(0.05, min(1.0, (float) ($e['fraction'] ?? 1))) : 1.0);
        $fgsp = !$student && empty($e['fp_exempt']);
        $er = [
            'pension' => self::r2($pensionBase * $r['er_pension'] / 100),
            'disability' => self::r2($pensionBase * $r['er_disability'] / 100),
            'accident' => $student ? 0.0 : self::r2($zusBase * $r['er_accident'] / 100),
            'fp' => $fp ? self::r2($zusBase * $r['er_fp'] / 100) : 0.0,
            'fgsp' => $fgsp ? self::r2($zusBase * $r['er_fgsp'] / 100) : 0.0,
        ];
        $erTotal = self::r2(array_sum($er));

        return [
            'kind' => $kind, 'earnings' => array_map(fn($x) => ['label' => $x[0], 'amount' => $x[1]], $earn),
            'hourly' => round($hourly, 4), 'gross' => $gross, 'zus_base' => $zusBase, 'pension_base' => self::r2($pensionBase), 'sick_pay' => $sickPay,
            'zus' => $zus, 'zus_total' => $zusEmp, 'health_base' => $healthBase, 'health' => $health,
            'exempt_revenue' => self::r2($exempt), 'income' => self::r2($income), 'kup' => self::r2($kup), 'tax_base' => $taxBase,
            'tax_before' => self::r2($tax), 'reduction' => $reduction, 'pit' => $pit, 'deduction' => self::r2($deduction), 'net' => $net,
            'employer' => $er, 'employer_total' => $erTotal, 'cost' => self::r2($gross + $erTotal),
            'sick_days' => $sickDays, 'student' => $student, 'warnings' => $warn,
        ];
    }

    /** Jours ouvrés (lun.–ven. hors fériés) entre deux dates incluses. @param array<string, mixed> $holidays date => nom */
    public static function workdays(string $from, string $to, array $holidays): int
    {
        $n = 0;
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ((int) date('N', strtotime($d)) <= 5 && !isset($holidays[$d])) $n++;
        }
        return $n;
    }

    /** « pełny etat », « 1/2 », « 3/4 etatu », « 0,5 » → fraction (1 par défaut). */
    public static function fraction(?string $workTime): float
    {
        $s = strtolower(trim((string) $workTime));
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $s, $m) && (int) $m[2] > 0) return max(0.05, min(1.0, (int) $m[1] / (int) $m[2]));
        if (preg_match('/^0?[.,]\d+$/', $s)) return max(0.05, min(1.0, (float) str_replace(',', '.', $s)));
        return 1.0;
    }

    private static function r2(float $v): float
    {
        return round($v + 0.0, 2);
    }

    private static function pl(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    private static function h(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
