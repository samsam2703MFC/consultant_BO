<?php
declare(strict_types=1);

namespace App\Services\Payroll;

use App\Domain\Logistics\Holidays;
use App\Domain\Payroll\BankXml;
use App\Domain\Payroll\PayrollCalculator;
use App\Exceptions\CustomException;
use App\Repositories\Payroll\PayrollRepository;
use App\Repositories\Workshop\AuditLogRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\TimeRepository;
use App\Services\Hr\HrCipher;
use App\Services\Workshop\Time\TimeService;

/**
 * [277] Finanse › Płace (makieta 41) : liste de paie du mois en 5 étapes — 1. Czas pracy du mois clôturé, 2. données HR
 * complètes, 3. contrôle et approbation (lignes figées), 4. fichier XML des salaires pour la banque (à payer au plus tard
 * le 10 du mois suivant, Kodeks pracy art. 85), 5. CSV pour le bureau comptable. ZUS (cotisations + zdrowotna) et impôt
 * (PIT-4R, au plus tard le 20) sont affichés avec leur montant et payés à part (décision du 12/11/2026).
 *
 * Brouillon : calcul en direct (fiche HR, Czas pracy, saisies du mois). Approuvé : lignes figées (n_pay_line) qui
 * servent aussi de cumuls de l'année (plafond ZUS, seuil de 32 %, ulga dla młodych, jours de maladie).
 */
final class PayrollService
{
    public const RATES = [
        'min_wage' => ['Płaca minimalna', 'zł'], 'min_hourly' => ['Minimalna stawka godzinowa', 'zł'], 'zus_cap' => ['Limit 30-krotności (emerytalna, rentowa)', 'zł'],
        'emp_pension' => ['Pracownik : emerytalna', '%'], 'emp_disability' => ['Pracownik : rentowa', '%'], 'emp_sickness' => ['Pracownik : chorobowa', '%'],
        'health' => ['Składka zdrowotna', '%'],
        'er_pension' => ['Pracodawca : emerytalna', '%'], 'er_disability' => ['Pracodawca : rentowa', '%'], 'er_accident' => ['Pracodawca : wypadkowa (stopa firmy)', '%'],
        'er_fp' => ['Pracodawca : Fundusz Pracy', '%'], 'er_fgsp' => ['Pracodawca : FGŚP', '%'],
        'kup' => ['Koszty uzyskania przychodu', 'zł'], 'kup_raised' => ['Koszty podwyższone (dojazd)', 'zł'], 'kup_civil' => ['Koszty umowy zlecenia', '%'],
        'pit_low' => ['PIT : pierwszy próg', '%'], 'pit_high' => ['PIT : drugi próg', '%'], 'pit_threshold' => ['PIT : próg roczny', 'zł'],
        'pit_reduction' => ['Kwota zmniejszająca (PIT-2)', 'zł/mies.'], 'young_limit' => ['Ulga dla młodych : limit roczny', 'zł'],
        'sick_pay' => ['Wynagrodzenie chorobowe', '%'], 'sick_days_employer' => ['Chorobowe płacone przez pracodawcę', 'dni/rok'],
    ];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    private const CONTRACTS = ['nieokreslony' => 'umowa o pracę', 'okreslony' => 'umowa o pracę (określony)', 'probny' => 'umowa o pracę (próbna)', 'zlecenie' => 'umowa zlecenie'];

    public function __construct(private PayrollRepository $repo, private HrCipher $cipher, private TimeService $time, private TimeRepository $timeRepo,
        private ParamRepository $params, private AuditLogRepository $audit)
    {
    }

    // --- Barèmes ---------------------------------------------------------------------------------------------

    /** Barèmes de l'année ; sans ligne pour cette année : ceux de la dernière année connue (signalé). */
    public function rates(int $year): array
    {
        $rows = $this->repo->rates($year);
        $from = $year;
        if (!$rows) {
            $from = $this->repo->latestRateYear($year) ?? $year;
            $rows = $this->repo->rates($from);
        }
        $list = [];
        foreach (self::RATES as $code => [$label, $unit]) {
            $list[] = ['code' => $code, 'label' => $label, 'unit' => $unit, 'value' => $rows[$code]['value'] ?? null, 'source' => $rows[$code]['source'] ?? null];
        }
        return ['year' => $year, 'from_year' => $from, 'own' => $from === $year, 'rates' => $list,
            'missing' => array_values(array_filter(array_keys(self::RATES), fn($c) => !isset($rows[$c])))];
    }

    /** @return array<string, float> */
    private function rateValues(int $year): array
    {
        $r = $this->rates($year);
        if ($r['missing']) throw new CustomException('Brak stawek płacowych : ' . implode(', ', $r['missing']) . ' (Ustawienia płac).', 'errors', 422);
        return array_column(array_map(fn($x) => ['c' => $x['code'], 'v' => (float) $x['value']], $r['rates']), 'v', 'c');
    }

    public function saveRates(int $year, array $in, ?int $userId): array
    {
        if ($year < 2024 || $year > 2100) throw new CustomException('Rok poza zakresem.', 'errors', 422);
        $errors = [];
        foreach ($in['rates'] ?? [] as $code => $row) {
            if (!isset(self::RATES[$code])) continue;
            $v = str_replace([' ', ','], ['', '.'], (string) ($row['value'] ?? ''));
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 10_000_000) { $errors[$code] = 'Liczba ≥ 0.'; continue; }
            if (self::RATES[$code][1] === '%' && (float) $v > 100) { $errors[$code] = 'Procent od 0 do 100.'; continue; }
            $this->repo->saveRate($year, $code, (float) $v, mb_substr(trim((string) ($row['source'] ?? '')), 0, 255) ?: null, $userId);
        }
        if ($errors) throw new CustomException('Popraw zaznaczone stawki.', 'errors', 422, ['fields' => $errors]);
        $this->audit->record($userId, 'update', 'pay_rates', $year, null, null, null);
        return $this->rates($year);
    }

    public function copyRates(int $year, ?int $userId): array
    {
        $from = $this->repo->latestRateYear($year - 1);
        if ($from === null) throw new CustomException('Brak stawek z poprzednich lat.', 'errors', 422);
        $this->repo->copyRates($from, $year, $userId);
        return $this->rates($year);
    }

    public function settings(): array
    {
        $c = $this->repo->company();
        return ['debtor_iban' => $this->params->get('pay.debtor_iban', ''), 'company_bank' => $c['bank'] ?? null,
            'city' => $this->params->get('pay.company_city', ''), 'rep' => $this->params->get('pay.company_rep', ''), 'workplace' => $this->params->get('pay.workplace', '')];
    }

    public function saveSettings(array $in, ?int $userId): array
    {
        if (array_key_exists('debtor_iban', $in)) {
            $raw = trim((string) $in['debtor_iban']);
            $iban = $raw === '' ? '' : BankXml::iban($raw);
            if ($iban === null) throw new CustomException('Niepoprawny numer rachunku (26 cyfr).', 'errors', 422, ['fields' => ['debtor_iban' => 'Sprawdź numer rachunku.']]);
            $this->params->setValue('pay.debtor_iban', $iban, $userId);
        }
        foreach (['city' => 'pay.company_city', 'rep' => 'pay.company_rep', 'workplace' => 'pay.workplace'] as $k => $code) {
            if (array_key_exists($k, $in)) $this->params->setValue($code, mb_substr(trim((string) $in[$k]), 0, 200), $userId);
        }
        return $this->settings();
    }

    // --- Mois ------------------------------------------------------------------------------------------------

    public function month(string $month, ?int $viewer, ?int $now = null): array
    {
        self::checkMonth($month);
        $now ??= time();
        $run = $this->repo->run($month);
        $approved = ($run['status'] ?? null) === 'approved';
        $data = $approved ? ['lines' => $this->repo->lines($month), 'incomplete' => []] : $this->compute($month, $now);
        $timeClosed = $this->timeRepo->closedMonth($month);
        $lines = $data['lines'];
        $totals = self::totals($lines);
        $this->repo->log($viewer, null, 'payroll');
        $next = date('Y-m', strtotime($month . '-01 +1 month'));
        return [
            'month' => $month, 'label' => self::monthLabel($month), 'prev' => date('Y-m', strtotime($month . '-01 -1 month')), 'next' => $next,
            'status' => $approved ? 'approved' : 'draft',
            'approved' => $approved ? ['at' => $run['approved_at'], 'by' => $run['approved_name']] : null,
            'xml' => $run && $run['xml_at'] ? ['at' => $run['xml_at'], 'exec_date' => $run['exec_date']] : null,
            'steps' => [
                'time' => $timeClosed !== null, 'hr' => ['complete' => count($lines), 'all' => count($lines) + count($data['incomplete'])],
                'approved' => $approved, 'xml' => (bool) ($run['xml_at'] ?? null),
            ],
            'pay_by' => self::payBy($month), 'tax_by' => $next . '-20', 'zus_by' => $next . '-15',
            'lines' => array_map(fn($l) => self::publicLine($l), $lines), 'incomplete' => $data['incomplete'], 'totals' => $totals,
            'rates_year' => (int) substr($month, 0, 4), 'can_reopen' => $approved && !$this->repo->laterApproved($month),
        ];
    }

    /** Calcul en direct du brouillon. @return array{lines: list<array>, incomplete: list<array>} */
    private function compute(string $month, int $now): array
    {
        $year = (int) substr($month, 0, 4);
        $r = $this->rateValues($year);
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $holidays = Holidays::forYear($year, 'PL');
        $workdays = PayrollCalculator::workdays($from, $to, $holidays);
        $worked = [];
        foreach ($this->time->month($month, $now)['people'] as $p) $worked[(int) $p['employee_id']] = (int) $p['worked'];
        $sick = $this->repo->sickDays($from, $to);
        $unpaid = $this->repo->unpaidDays($from, $to);
        $adj = $this->repo->adjusts($month);
        $ytd = $this->repo->ytd($month);
        $avg = $this->repo->avgGross($month);
        $lines = [];
        $incomplete = [];
        foreach ($this->repo->employees($from, $to) as $e) {
            $id = (int) $e['id'];
            $kind = match ($e['contract_type']) { 'nieokreslony', 'okreslony', 'probny' => 'praca', 'zlecenie' => 'zlecenie', default => null };
            $iban = BankXml::iban($this->cipher->decrypt($e['bank_enc']));
            $birth = $this->cipher->decrypt($e['birth_enc']);
            $missing = [];
            if ($kind === null) $missing[] = 'umowa';
            if ($e['pay_amount'] === null || (float) $e['pay_amount'] <= 0) $missing[] = 'wynagrodzenie';
            if ($iban === null) $missing[] = 'rachunek';
            if (!$birth) $missing[] = 'data urodzenia';
            if ($kind === null || in_array('wynagrodzenie', $missing, true)) {
                if ($e['active']) $incomplete[] = ['employee_id' => $id, 'name' => $e['name'], 'group' => $e['group_name'], 'missing' => $missing];
                continue;
            }
            $age = $birth ? (int) (new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable($from))->y : null;
            $start = max($from, $e['hire_date'] ?: $from);
            $end = min($to, $e['contract_end'] ?: $to);
            $a = $adj[$id] ?? [];
            $calc = PayrollCalculator::compute([
                'kind' => $kind, 'pay_type' => $e['pay_type'] ?: ($kind === 'zlecenie' ? 'hourly' : 'monthly'), 'pay_amount' => (float) $e['pay_amount'],
                'fraction' => PayrollCalculator::fraction($e['work_time']), 'pit2' => (bool) $e['pit2'], 'kup_raised' => (bool) $e['kup_raised'],
                'young' => $age !== null && $age < 26 && (bool) $e['young_relief'], 'student' => (bool) $e['student'], 'sick_insurance' => (bool) $e['sick_insurance'],
                'fp_exempt' => $age !== null && $age >= ($e['gender'] === 'K' ? 55 : 60),
            ], [
                'workdays' => $workdays, 'employed_workdays' => $start <= $end ? PayrollCalculator::workdays($start, $end, $holidays) : 0,
                'hours' => round(($worked[$id] ?? 0) / 60, 2), 'sick_days' => $sick[$id] ?? 0, 'unpaid_days' => $unpaid[$id] ?? 0,
                'sick_base' => $avg[$id] ?? null, 'ytd' => $ytd[$id] ?? [],
            ], [
                'overtime50_h' => (float) ($a['overtime50_h'] ?? 0), 'overtime100_h' => (float) ($a['overtime100_h'] ?? 0),
                'bonus' => (float) ($a['bonus'] ?? 0), 'deduction' => (float) ($a['deduction'] ?? 0),
            ], $r);
            if ($iban === null) $calc['warnings'][] = 'Brak numeru rachunku w karcie HR — przelew niemożliwy.';
            if (!$birth) $calc['warnings'][] = 'Brak daty urodzenia : ulga dla młodych i zwolnienie z FP nie są sprawdzane.';
            $lines[] = [
                'employee_id' => $id, 'name' => $e['name'], 'group' => $e['group_name'], 'position' => $e['position'],
                'contract' => self::CONTRACTS[$e['contract_type']] ?? $e['contract_type'], 'work_time' => $e['work_time'],
                'tags' => array_values(array_filter([$e['pit2'] ? 'PIT-2' : null, $kind === 'praca' ? 'KUP ' . ($e['kup_raised'] ? '300' : '250') : 'KUP 20 %',
                    $calc['student'] ? 'student < 26 : bez ZUS' : null, $age !== null && $age < 26 && $e['young_relief'] ? 'ulga dla młodych' : null])),
                'iban' => $iban, 'hours' => round(($worked[$id] ?? 0) / 60, 2), 'workdays' => $workdays,
                'adjust' => ['overtime50_h' => (float) ($a['overtime50_h'] ?? 0), 'overtime100_h' => (float) ($a['overtime100_h'] ?? 0),
                    'bonus' => (float) ($a['bonus'] ?? 0), 'deduction' => (float) ($a['deduction'] ?? 0), 'note' => $a['note'] ?? null],
                'calc' => $calc,
            ];
        }
        return ['lines' => $lines, 'incomplete' => $incomplete];
    }

    private static function publicLine(array $l): array
    {
        $l['account'] = BankXml::masked($l['iban'] ?? null);
        unset($l['iban']);
        return $l;
    }

    private static function totals(array $lines): array
    {
        $t = ['people' => count($lines), 'gross' => 0.0, 'zus_emp' => 0.0, 'health' => 0.0, 'pit' => 0.0, 'net' => 0.0, 'deduction' => 0.0, 'employer' => 0.0, 'cost' => 0.0, 'transfers' => 0];
        foreach ($lines as $l) {
            $c = $l['calc'];
            $t['gross'] += $c['gross'];
            $t['zus_emp'] += $c['zus_total'];
            $t['health'] += $c['health'];
            $t['pit'] += $c['pit'];
            $t['net'] += $c['net'];
            $t['deduction'] += $c['deduction'];
            $t['employer'] += $c['employer_total'];
            $t['cost'] += $c['cost'];
            if ($c['net'] > 0) $t['transfers']++;
        }
        foreach ($t as $k => $v) if (is_float($v)) $t[$k] = round($v, 2);
        $t['zus_social'] = round($t['zus_emp'] + $t['employer'], 2);
        $t['zus_all'] = round($t['zus_social'] + $t['health'], 2);
        return $t;
    }

    public function saveAdjust(string $month, int $employeeId, array $in, ?int $userId): array
    {
        self::checkMonth($month);
        if (($this->repo->run($month)['status'] ?? null) === 'approved') throw new CustomException('Lista płac jest zatwierdzona — najpierw ją otwórz.', 'errors', 409);
        $num = function (string $k, float $max) use ($in): float {
            $v = str_replace([' ', ','], ['', '.'], (string) ($in[$k] ?? '0'));
            if ($v === '') return 0.0;
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) throw new CustomException('Popraw wartość.', 'errors', 422, ['fields' => [$k => 'Od 0 do ' . $max . '.']]);
            return round((float) $v, 2);
        };
        $a = ['overtime50_h' => $num('overtime50_h', 200), 'overtime100_h' => $num('overtime100_h', 200), 'bonus' => $num('bonus', 100000), 'deduction' => $num('deduction', 100000),
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null];
        $this->repo->saveAdjust($month, $employeeId, $a, $userId);
        return $this->month($month, $userId);
    }

    public function approve(string $month, ?int $userId, ?int $now = null): array
    {
        self::checkMonth($month);
        $now ??= time();
        if (($this->repo->run($month)['status'] ?? null) === 'approved') throw new CustomException('Lista płac jest już zatwierdzona.', 'errors', 409);
        if ($month . '-01' > date('Y-m-d', $now)) throw new CustomException('Ten miesiąc jeszcze się nie zaczął.', 'errors', 422);
        if ($this->timeRepo->closedMonth($month) === null) throw new CustomException('Najpierw zamknij miesiąc w Czas pracy (godziny, nadgodziny, L4).', 'errors', 422);
        $data = $this->compute($month, $now);
        if (!$data['lines']) throw new CustomException('Brak osób z kompletną umową i wynagrodzeniem.', 'errors', 422);
        $this->repo->approve($month, $data['lines'], self::totals($data['lines']), $userId);
        $this->audit->record($userId, 'approve', 'payroll', $month, null, ['people' => count($data['lines'])], null);
        return $this->month($month, $userId, $now);
    }

    public function reopen(string $month, ?int $userId): array
    {
        self::checkMonth($month);
        if ($this->repo->laterApproved($month)) throw new CustomException('Późniejszy miesiąc jest zatwierdzony — najpierw otwórz go.', 'errors', 409);
        $this->repo->reopen($month);
        $this->audit->record($userId, 'reopen', 'payroll', $month, null, null, null);
        return $this->month($month, $userId);
    }

    // --- Sorties ---------------------------------------------------------------------------------------------

    /** Lignes à imprimer (approuvées, sinon brouillon marqué « projekt »). */
    public function printable(string $month, ?array $ids, ?int $viewer): array
    {
        $m = $this->month($month, $viewer);
        $lines = ($this->repo->run($month)['status'] ?? null) === 'approved' ? $this->repo->lines($month) : $this->compute($month, time())['lines'];
        if ($ids) $lines = array_values(array_filter($lines, fn($l) => in_array((int) $l['employee_id'], $ids, true)));
        if (!$lines) throw new CustomException('Brak pasków do wydruku.', 'errors', 404);
        foreach ($lines as $l) $this->repo->log($viewer, (int) $l['employee_id'], 'payslip');
        return ['month' => $month, 'label' => $m['label'], 'draft' => $m['status'] !== 'approved', 'lines' => $lines, 'company' => $this->repo->company(), 'pay_by' => $m['pay_by']];
    }

    /** Fichier XML pain.001 des salaires (liste approuvée). @return array{filename: string, content: string, count: int, sum: float} */
    public function bankFile(string $month, ?string $execDate, ?int $userId, ?int $now = null): array
    {
        self::checkMonth($month);
        $now ??= time();
        if (($this->repo->run($month)['status'] ?? null) !== 'approved') throw new CustomException('Najpierw zatwierdź listę płac.', 'errors', 422);
        $execDate = $execDate ?: max(date('Y-m-d', $now), self::payBy($month));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $execDate) || $execDate < date('Y-m-d', $now)) throw new CustomException('Data przelewu : dziś albo później.', 'errors', 422);
        $c = $this->repo->company();
        $debtor = BankXml::iban($this->params->get('pay.debtor_iban', '') ?: ($c['bank'] ?? ''));
        if ($debtor === null) throw new CustomException('Brak poprawnego rachunku firmy (Ustawienia płac albo dane firmy).', 'errors', 422);
        $transfers = [];
        $missing = [];
        [$y, $mo] = explode('-', $month);
        foreach ($this->repo->lines($month) as $l) {
            $net = (float) $l['calc']['net'];
            if ($net <= 0) continue;
            if (empty($l['iban'])) { $missing[] = $l['name']; continue; }
            $transfers[] = ['id' => 'PL' . $y . $mo . '-' . $l['employee_id'], 'name' => $l['name'], 'iban' => $l['iban'], 'amount' => $net,
                'title' => 'Wynagrodzenie ' . $mo . '/' . $y];
        }
        if ($missing) throw new CustomException('Brak numeru rachunku : ' . implode(', ', $missing) . ' (karta HR), potem otwórz i zatwierdź listę ponownie.', 'errors', 422);
        if (!$transfers) throw new CustomException('Brak przelewów do wykonania.', 'errors', 422);
        $xml = BankXml::build('PLACE-' . $month . '-' . date('His', $now), ['name' => (string) ($c['name'] ?? 'Pracodawca'), 'iban' => $debtor, 'bic' => $c['swift'] ?? null],
            $execDate, $transfers, date('Y-m-d\TH:i:s', $now));
        $this->repo->markXml($month, $execDate, $userId);
        $this->audit->record($userId, 'export', 'payroll_xml', $month, null, ['transfers' => count($transfers)], null);
        return ['filename' => 'przelewy_wynagrodzenia_' . $month . '.xml', 'content' => $xml, 'count' => count($transfers),
            'sum' => round(array_sum(array_column($transfers, 'amount')), 2)];
    }

    /** CSV pour le bureau comptable (séparateur « ; », UTF-8 avec BOM). */
    public function csv(string $month, ?int $viewer): array
    {
        $p = $this->printable($month, null, $viewer);
        $n = fn($v) => number_format((float) $v, 2, ',', '');
        $rows = [['Pracownik', 'Umowa', 'Brutto', 'Podstawa ZUS', 'Emerytalna', 'Rentowa', 'Chorobowa', 'Zdrowotna', 'KUP', 'Podstawa PIT', 'Zaliczka PIT', 'Potrącenia', 'Netto',
            'ZUS pracodawcy', 'Wypadkowa', 'FP', 'FGŚP', 'Koszt pracodawcy']];
        foreach ($p['lines'] as $l) {
            $c = $l['calc'];
            $rows[] = [$l['name'], $l['contract'], $n($c['gross']), $n($c['zus_base']), $n($c['zus']['pension']), $n($c['zus']['disability']), $n($c['zus']['sickness']),
                $n($c['health']), $n($c['kup']), $n($c['tax_base']), $n($c['pit']), $n($c['deduction']), $n($c['net']),
                $n($c['employer']['pension'] + $c['employer']['disability']), $n($c['employer']['accident']), $n($c['employer']['fp']), $n($c['employer']['fgsp']), $n($c['cost'])];
        }
        $csv = "\u{FEFF}" . implode("\r\n", array_map(fn($r) => implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)), $rows)) . "\r\n";
        return ['filename' => 'lista_plac_' . $month . ($p['draft'] ? '_projekt' : '') . '.csv', 'content' => $csv];
    }

    // --- Outils ----------------------------------------------------------------------------------------------

    /** Au plus tard le 10 du mois suivant ; samedi, dimanche ou férié : le jour ouvré précédent (art. 85 § 3 KP). */
    public static function payBy(string $month): string
    {
        $d = date('Y-m', strtotime($month . '-01 +1 month')) . '-10';
        $h = Holidays::forYear((int) substr($d, 0, 4), 'PL');
        while ((int) date('N', strtotime($d)) > 5 || isset($h[$d])) $d = date('Y-m-d', strtotime($d . ' -1 day'));
        return $d;
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    private static function checkMonth(string $month): void
    {
        if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $month)) throw new CustomException('Miesiąc RRRR-MM.', 'errors', 422);
    }
}
