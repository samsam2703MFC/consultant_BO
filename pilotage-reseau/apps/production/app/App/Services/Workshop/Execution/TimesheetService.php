<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ClockQuestionRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Godziny pracy z tabletu : miesięczna karta pracownika (wejścia / wyjścia, godziny, godziny nocne [365 : 21:00–05:00, parametry]
 * 22:00–06:00, podpisy, pytania potwierdzone przy wejściu / wyjściu), zestawienie miesiąca dla wszystkich,
 * korekty kierownika (zawsze z powodem, w dzienniku). Karta drukowana jest podpisywana na tablecie (podpis miesiąca) albo na papierze.
 */
final class TimesheetService
{
    public function __construct(private ExecutionRepository $repo, private TransactionManager $tx, private AuditTrail $audit, private SignatureStorage $storage,
        private ClockQuestionRepository $questions, private ParamRepository $params)
    {
    }

    public function month(int $employeeId, string $month, ?int $now = null): array
    {
        $now ??= time();
        $this->checkMonth($month);
        [$nightFrom, $nightTo] = $this->nightWindow();
        $employee = $this->repo->employee($employeeId) ?? throw ApiException::notFound('employee', $employeeId);
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-01 00:00:00', strtotime($from . ' +1 month'));
        $signatures = $this->repo->signatures($employeeId);
        $byClock = [];
        $inByClock = [];
        foreach ($signatures as $s) {
            if ($s['purpose'] === 'clock_out') $byClock[(int) $s['ref']] = $s;
            if ($s['purpose'] === 'clock_in') $inByClock[(int) $s['ref']] = $s;
        }
        $monthly = array_values(array_filter($signatures, fn($s) => $s['purpose'] === 'timesheet_month' && $s['ref'] === $month));
        $days = [];
        $clocks = $this->repo->clocks($from, $to, $employeeId);
        $answers = $this->questions->answersFor(array_column($clocks, 'id'));
        foreach ($clocks as $k) {
            $in = strtotime($k['clock_in']);
            $out = $k['clock_out'] !== null ? strtotime($k['clock_out']) : null;
            $end = $out ?? $now;
            $days[] = [
                'id' => (int) $k['id'], 'date' => substr($k['clock_in'], 0, 10), 'clock_in' => $k['clock_in'], 'clock_out' => $k['clock_out'],
                'hours' => round(max(0, $end - $in) / 3600, 2), 'night_hours' => round(self::nightSeconds($in, $end, $nightFrom, $nightTo) / 3600, 2),
                'open' => $out === null, 'source' => $k['source'], 'note' => $k['note'], 'day_note' => $k['day_note'] ?? null, 'corrected' => $k['corrected_at'] !== null,
                'signature_id' => isset($byClock[(int) $k['id']]) ? (int) $byClock[(int) $k['id']]['id'] : null,
                'signature_in_id' => isset($inByClock[(int) $k['id']]) ? (int) $inByClock[(int) $k['id']]['id'] : null,
                'answers' => self::answers($answers[(int) $k['id']] ?? []),
            ];
        }
        $closed = array_filter($days, fn($d) => !$d['open']);
        // [365] Totaux par semaine (lundi → dimanche, semaine ISO) : heures et heures de nuit des pointages fermés.
        $weeks = [];
        foreach ($days as $i => $d) {
            $mon = date('Y-m-d', strtotime($d['date'] . ' -' . (((int) date('N', strtotime($d['date']))) - 1) . ' days'));
            $days[$i]['week_from'] = $mon;
            $weeks[$mon] ??= ['week' => (int) date('W', strtotime($mon)), 'from' => $mon, 'to' => date('Y-m-d', strtotime($mon . ' +6 days')), 'hours' => 0.0, 'night_hours' => 0.0, 'days' => 0];
            $weeks[$mon]['days']++;
            if ($d['open']) continue;
            $weeks[$mon]['hours'] = round($weeks[$mon]['hours'] + $d['hours'], 2);
            $weeks[$mon]['night_hours'] = round($weeks[$mon]['night_hours'] + $d['night_hours'], 2);
        }
        return [
            'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name']],
            'month' => $month,
            'night_window' => $nightFrom . '–' . $nightTo,
            'days' => $days,
            'weeks' => array_values($weeks),
            'totals' => [
                'hours' => round(array_sum(array_column($closed, 'hours')), 2),
                'night_hours' => round(array_sum(array_column($closed, 'night_hours')), 2),
                'days' => count(array_unique(array_column($days, 'date'))),
                'open' => count($days) - count($closed),
                'signed' => count(array_filter($days, fn($d) => $d['signature_id'] !== null)),
                'signed_in' => count(array_filter($days, fn($d) => $d['signature_in_id'] !== null)),
            ],
            'month_signature' => $monthly ? ['id' => (int) end($monthly)['id'], 'signed_at' => end($monthly)['signed_at'], 'sha256' => end($monthly)['sha256']] : null,
        ];
    }

    /** Pytania przy wejściu / wyjściu : ile potwierdzonych, które nie (tylko nieobowiązkowe mogą zostać bez « tak »). */
    private static function answers(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $d = $r['direction'];
            $out[$d] ??= ['yes' => 0, 'total' => 0, 'no' => []];
            // [263] Pytanie Tak / Nie (np. towar przy wyjściu) : odpowiedź, nie punkt do potwierdzenia — « Tak » z opisem.
            if (($r['kind'] ?? 'confirm') === 'yes_no') {
                if ((int) $r['answer'] === 1) $out[$d]['declared'][] = (string) ($r['note'] ?? '');
                continue;
            }
            $out[$d]['total']++;
            if ((int) $r['answer'] === 1) $out[$d]['yes']++; else $out[$d]['no'][] = $r['question'];
        }
        return $out;
    }

    /** Zestawienie miesiąca : godziny na osobę (grupa opcjonalnie). */
    public function summary(string $month, ?int $groupId, ?int $now = null): array
    {
        $this->checkMonth($month);
        $out = [];
        foreach ($this->repo->people($groupId) as $p) {
            $m = $this->month((int) $p['id'], $month, $now);
            $out[] = ['employee_id' => (int) $p['id'], 'name' => $p['name'], 'group_id' => $p['id_primary_group'] !== null ? (int) $p['id_primary_group'] : null] + $m['totals']
                + ['month_signed' => $m['month_signature'] !== null, 'clocked_in' => $p['clocked_in_at'] !== null,
                    'has_badge' => (bool) $p['has_badge'], 'has_pin' => (bool) $p['has_pin'], 'biometric_consent' => (bool) $p['biometric_consent'],
                    'locked' => $p['locked_until'] !== null && $p['locked_until'] > date('Y-m-d H:i:s'), 'phones' => (int) $p['phones']];
        }
        return $out;
    }

    public function correct(int $clockId, array $body, ApiContext $context): array
    {
        $clock = $this->repo->clock($clockId) ?? throw ApiException::notFound('clock', $clockId);
        $v = (new Validator($body))->only(['clock_in', 'clock_out', 'note'])->datetime('clock_in')->datetime('clock_out')->string('note', 255, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $in = $c['clock_in'] ?? $clock['clock_in'];
        $out = array_key_exists('clock_out', $c) ? $c['clock_out'] : $clock['clock_out'];
        if ($out !== null && $out < $in) throw ApiException::validation([['field' => 'clock_out', 'code' => 'DATE_ORDER', 'params' => []]]);
        $this->tx->execute(function () use ($clockId, $clock, $in, $out, $c, $context) {
            $this->repo->correctClock($clockId, $in, $out, $c['note'], $context->userId);
            $this->audit->record($context, 'correct', 'clock', $clockId, ['clock_in' => $clock['clock_in'], 'clock_out' => $clock['clock_out']], ['clock_in' => $in, 'clock_out' => $out, 'note' => $c['note']]);
        });
        return $this->repo->clock($clockId);
    }

    public function addManual(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['employee_id', 'clock_in', 'clock_out', 'note'])
            ->integer('employee_id', true, 1)->datetime('clock_in', true)->datetime('clock_out', true)->string('note', 255, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        if ($this->repo->employee($c['employee_id']) === null) throw ApiException::validation([['field' => 'employee_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => []]]);
        if ($c['clock_out'] < $c['clock_in']) throw ApiException::validation([['field' => 'clock_out', 'code' => 'DATE_ORDER', 'params' => []]]);
        $id = $this->tx->execute(function () use ($c, $context) {
            $id = $this->repo->clockIn($c['employee_id'], $c['clock_in'], null, null, 'manual', $c['note']);
            $this->repo->clockOut($id, $c['clock_out'], null);
            $this->audit->record($context, 'create', 'clock', $id, null, $c + ['source' => 'manual']);
            return $id;
        });
        return $this->repo->clock($id);
    }

    /** Image d'une signature (PNG) pour l'impression de la carte. */
    public function signatureImage(int $id): ?string
    {
        $row = $this->repo->signatureImage($id);
        return $row ? $this->storage->load($row) : null;
    }

    /**
     * [365] Heures de nuit : de time.night_start à time.night_end (par défaut 21:00–05:00, Ustawienia › Parametry).
     * @return array{0: string, 1: string} HH:MM
     */
    public function nightWindow(): array
    {
        $ok = fn(string $v, string $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $d;
        return [$ok(trim($this->params->get('time.night_start', '21:00')), '21:00'), $ok(trim($this->params->get('time.night_end', '05:00')), '05:00')];
    }

    /** Secondes de nuit dans [from, to] : chaque nuit de $nightFrom (HH:MM) à $nightTo (le lendemain si plus tôt). */
    public static function nightSeconds(int $from, int $to, string $nightFrom = '21:00', string $nightTo = '05:00'): int
    {
        $total = 0;
        $nextDay = $nightTo <= $nightFrom;
        for ($day = strtotime(date('Y-m-d', $from) . ' -1 day'); $day <= $to; $day = strtotime('+1 day', $day)) {
            $start = strtotime(date('Y-m-d', $day) . ' ' . $nightFrom . ':00');
            $end = strtotime(date('Y-m-d', $day) . ' ' . $nightTo . ':00' . ($nextDay ? ' +1 day' : ''));
            $total += max(0, min($to, $end) - max($from, $start));
        }
        return $total;
    }

    private function checkMonth(string $month): void
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw ApiException::validation([['field' => 'month', 'code' => 'INVALID_MONTH', 'params' => []]]);
    }
}
