<?php
declare(strict_types=1);

namespace App\Services\Workshop\Time;

use App\Core\TransactionManager;
use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\AuditLogRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\TimeRepository;
use App\Services\Workshop\Execution\ExecutionService;
use App\Services\Workshop\Execution\SignatureStorage;

/**
 * [262] Czas pracy : ce que le kierownik produkcji valide chaque jour à partir des pointages de la tablette de bureau.
 *
 * Règles (décision du 11/11/2026) : plan de 8 h (time.day_plan_min) par jour ouvré — lundi à vendredi hors fériés PL —,
 * 0 le week-end, un férié ou un jour d'absence du calendrier ; un jour « wolne za nadgodziny » partiel garde le reste du plan.
 * Heure supplémentaire = temps pointé au-delà du plan du jour quand le dépassement passe la tolérance (time.tolerance_min,
 * en deçà : bruit de pointage, ignoré) ; en plus, ce qui dépasse 40 h (time.week_plan_min) dans la semaine ISO, compté sur
 * le dernier jour travaillé de la semaine. Un pointage compte pour le jour de son entrée (nuit comprise).
 *
 * Un jour est validé quand tous les pointages sont fermés, chaque heure supplémentaire a un motif et chaque absence un jour
 * ouvré a une entrée de calendrier ou un motif. Seuls les jours validés alimentent la banque d'heures supplémentaires ;
 * elles se récupèrent en jour de congé supplémentaire au plus tard time.recovery_months mois après (FIFO), jour proposé par
 * le kierownik et signé par la personne (tablette de bureau au pointage, ou à l'écran). Congé : time.leave_days jours par an
 * (n_time_leave par personne), dont 4 « na żądanie ».
 */
final class TimeService
{
    public const OVERTIME_REASONS = [
        'pilne' => 'Zamówienie pilne / domówienie',
        'awaria' => 'Awaria maszyny',
        'zastepstwo' => 'Zastępstwo nieobecnego',
        'sprzatanie' => 'Sprzątanie / mycie',
        'inwentaryzacja' => 'Inwentaryzacja',
        'dostawa' => 'Dostawa późno',
        'inne' => 'Inny powód (opis)',
    ];

    /** Nieobecność w dniu pracy : urlop, na żądanie, L4 trafiają do kalendarza ; pozostałe zostają powodem dnia. */
    public const ABSENCE_REASONS = [
        'urlop' => 'Urlop wypoczynkowy',
        'na_zadanie' => 'Urlop na żądanie',
        'l4' => 'Chorobowe (L4)',
        'nieusprawiedliwiona' => 'Nieobecność nieusprawiedliwiona',
        'inna' => 'Inny powód (opis)',
    ];

    public const ABSENCE_TYPES = [
        'urlop' => 'Urlop wypoczynkowy',
        'na_zadanie' => 'Urlop na żądanie',
        'l4' => 'Chorobowe (L4)',
        'wn' => 'Wolne za nadgodziny',
        'inna' => 'Inna nieobecność usprawiedliwiona',
    ];

    public const TYPE_SHORT = ['urlop' => 'U', 'na_zadanie' => 'UŻ', 'l4' => 'L4', 'wn' => 'WN', 'inna' => 'N'];

    /** Urlop na żądanie : dni w roku (Kodeks pracy, art. 167²), w ramach urlopu wypoczynkowego. */
    public const ON_DEMAND_DAYS = 4;

    private const CALENDAR_REASONS = ['urlop', 'na_zadanie', 'l4'];
    private const LEAVE_TYPES = ['urlop', 'na_zadanie'];
    private const DOW = [1 => 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.', 'niedz.'];
    private const DOW_LONG = [1 => 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'];

    private ?array $rules = null;

    public function __construct(
        private TimeRepository $repo,
        private ExecutionRepository $clocks,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditLogRepository $log,
        private SignatureStorage $storage,
    ) {
    }

    /** Paramètres (minutes, mois, jours). */
    public function rules(): array
    {
        return $this->rules ??= [
            'day_plan' => max(0, (int) $this->params->get('time.day_plan_min', '480')),
            'week_plan' => max(0, (int) $this->params->get('time.week_plan_min', '2400')),
            'tolerance' => max(0, (int) $this->params->get('time.tolerance_min', '15')),
            'recovery_months' => max(1, (int) $this->params->get('time.recovery_months', '3')),
            'leave_days' => max(0, (int) $this->params->get('time.leave_days', '26')),
            'company' => trim($this->params->get('time.company_name', 'SL Food')) ?: 'SL Food',
        ];
    }

    public static function hm(?int $minutes, bool $sign = false): string
    {
        if ($minutes === null) return '';
        $s = $minutes < 0 ? '−' : ($sign && $minutes > 0 ? '+' : '');
        $m = abs($minutes);
        return $s . intdiv($m, 60) . ':' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    // =====================================================================================================
    // Calcul : une grille personne × jour
    // =====================================================================================================

    /**
     * Grille [from, to] (inclus) : personne => jour => cellule. Semaines ISO complètes calculées (règle des 40 h),
     * seuls les jours demandés sont rendus.
     *
     * Cellule : clocks, worked (min, pointages fermés), open (pointage sans sortie), plan, overtime, week_extra,
     * absence (ligne du calendrier), holiday, workday.
     *
     * @param list<array> $staff personnes (id, …)
     * @return array{cells: array<int, array<string, array>>, holidays: array<string, string>}
     */
    private function grid(string $from, string $to, array $staff, ?int $now = null): array
    {
        $now ??= time();
        $r = $this->rules();
        $start = date('Y-m-d', strtotime($from . ' -' . ((int) date('N', strtotime($from)) - 1) . ' days'));
        $end = date('Y-m-d', strtotime($to . ' +' . (7 - (int) date('N', strtotime($to))) . ' days'));
        $holidays = array_map(fn($byCountry) => (string) reset($byCountry), Holidays::between($start, $end, ['PL']));
        $ids = array_map(fn($p) => (int) $p['id'], $staff);
        $idSet = array_fill_keys($ids, true);

        $clocks = [];
        foreach ($this->repo->clocks($start, date('Y-m-d', strtotime($end . ' +1 day'))) as $k) {
            if (isset($idSet[(int) $k['id_employee']])) $clocks[(int) $k['id_employee']][substr($k['clock_in'], 0, 10)][] = $k;
        }
        $absences = [];
        foreach ($this->repo->absences($start, $end) as $a) {
            if (!isset($idSet[(int) $a['id_employee']])) continue;
            for ($d = max($a['date_from'], $start); $d <= min($a['date_to'], $end); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $absences[(int) $a['id_employee']][$d] ??= $a;
            }
        }

        $cells = [];
        foreach ($ids as $id) {
            $week = [];
            for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $dow = (int) date('N', strtotime($d));
                $workday = $dow <= 5 && !isset($holidays[$d]);
                $absence = $absences[$id][$d] ?? null;
                $plan = $workday ? $r['day_plan'] : 0;
                if ($absence !== null) $plan = $absence['type'] === 'wn' ? max(0, $plan - (int) $absence['minutes']) : 0;
                $worked = 0;
                $open = false;
                $list = [];
                foreach ($clocks[$id][$d] ?? [] as $k) {
                    $in = strtotime($k['clock_in']);
                    $out = $k['clock_out'] !== null ? strtotime($k['clock_out']) : null;
                    if ($out === null) $open = true;
                    else $worked += intdiv(max(0, $out - $in), 60);
                    $list[] = ['id' => (int) $k['id'], 'in' => $k['clock_in'], 'out' => $k['clock_out'], 'source' => $k['source'], 'note' => $k['note'],
                        'corrected' => $k['corrected_at'] !== null, 'running' => $out === null ? intdiv(max(0, $now - $in), 60) : null,
                        'took' => isset($k['took']) && $k['took'] !== null ? (string) $k['took'] : null];
                }
                $excess = $worked - $plan;
                $overtime = $excess > $r['tolerance'] ? $excess : 0;
                $counted = ($excess > 0 && $excess <= $r['tolerance']) ? $plan : $worked;
                $cell = ['date' => $d, 'workday' => $workday, 'holiday' => $holidays[$d] ?? null, 'clocks' => $list, 'worked' => $worked, 'open' => $open,
                    'plan' => $plan, 'overtime' => $overtime, 'week_extra' => 0, 'absence' => $absence, 'counted' => $counted];
                $week[$d] = $cell;
                if ($dow === 7) {
                    // Semaine ISO : au-delà de 40 h, hors heures déjà comptées jour par jour.
                    $extra = array_sum(array_column($week, 'counted')) - array_sum(array_column($week, 'overtime')) - $r['week_plan'];
                    if ($r['week_plan'] > 0 && $extra > 0) {
                        $last = null;
                        foreach ($week as $wd => $c) if ($c['worked'] > 0) $last = $wd;
                        if ($last !== null) {
                            $week[$last]['week_extra'] = $extra;
                            $week[$last]['overtime'] += $extra;
                        }
                    }
                    foreach ($week as $wd => $c) if ($wd >= $from && $wd <= $to) $cells[$id][$wd] = $c;
                    $week = [];
                }
            }
        }
        return ['cells' => $cells, 'holidays' => $holidays];
    }

    /** État d'une cellule pour la validation : ok, open, need_overtime, need_absence. */
    private static function status(array $c, ?array $otNote, ?array $absNote, string $today): string
    {
        if ($c['open']) return 'open';
        if ($c['overtime'] > 0 && $otNote === null) return 'need_overtime';
        if ($c['plan'] > 0 && $c['worked'] === 0 && $c['absence'] === null && $absNote === null && $c['date'] <= $today) return 'need_absence';
        return 'ok';
    }

    /** Personnes du calcul : actives + celles qui ont pointé dans la période (sortie récente). */
    private function staffFor(string $from, string $to): array
    {
        $staff = $this->repo->staff();
        $known = array_fill_keys(array_map(fn($p) => (int) $p['id'], $staff), true);
        foreach ($this->repo->clocks($from, date('Y-m-d', strtotime($to . ' +1 day'))) as $k) {
            $id = (int) $k['id_employee'];
            if (isset($known[$id])) continue;
            $known[$id] = true;
            if ($e = $this->repo->employee($id)) $staff[] = ['id' => $id, 'name' => $e['name'], 'group_id' => null, 'group_name' => $e['group_name'], 'group_color' => null];
        }
        return $staff;
    }

    private function notesBy(string $from, string $to): array
    {
        $out = [];
        foreach ($this->repo->notes($from, date('Y-m-d', strtotime($to . ' +1 day'))) as $n) {
            $out[(int) $n['id_employee']][$n['day']][$n['kind']] = $n;
        }
        return $out;
    }

    // =====================================================================================================
    // Dzień : validation du jour (maquette 1)
    // =====================================================================================================

    public function day(string $day, ?int $now = null): array
    {
        $this->checkDate($day);
        $now ??= time();
        $today = date('Y-m-d', $now);
        $staff = $this->staffFor($day, $day);
        $g = $this->grid($day, $day, $staff, $now);
        $notes = $this->notesBy($day, $day);
        $validated = $this->repo->validatedDays($day, date('Y-m-d', strtotime($day . ' +1 day')))[$day] ?? null;
        $proposed = [];
        foreach ($this->repo->comps(null, 'proposed') as $c) $proposed[(int) $c['id_employee']][] = $c;

        $people = [];
        $counts = ['total' => 0, 'present' => 0, 'open' => 0, 'need_overtime' => 0, 'overtime' => 0, 'absent' => 0, 'need_absence' => 0, 'leave' => 0, 'worked' => 0];
        foreach ($staff as $p) {
            $id = (int) $p['id'];
            $c = $g['cells'][$id][$day];
            $ot = $notes[$id][$day]['overtime'] ?? null;
            $ab = $notes[$id][$day]['absence'] ?? null;
            $status = self::status($c, $ot, $ab, $today);
            $hasClock = $c['clocks'] !== [];
            // Sans pointage ni plan (week-end, férié) : pas de ligne ; un congé du calendrier un jour ouvré reste visible.
            if (!$hasClock && $c['plan'] === 0 && !($c['absence'] !== null && $c['workday'])) continue;
            $counts['total']++;
            if ($hasClock) $counts['present']++;
            if ($status === 'open') $counts['open']++;
            if ($status === 'need_overtime') $counts['need_overtime']++;
            if ($status === 'need_absence') $counts['need_absence']++;
            if (!$hasClock && ($c['plan'] > 0 || $c['absence'] !== null)) $counts['absent']++;
            if ($c['absence'] !== null) $counts['leave']++;
            $counts['overtime'] += $c['overtime'];
            $counts['worked'] += $c['worked'];
            $first = $c['clocks'][0]['in'] ?? null;
            $lastOut = null;
            foreach ($c['clocks'] as $k) if ($k['out'] !== null && ($lastOut === null || $k['out'] > $lastOut)) $lastOut = $k['out'];
            $people[] = [
                'employee_id' => $id, 'name' => $p['name'], 'group' => $p['group_name'], 'group_color' => $p['group_color'],
                'clocks' => $c['clocks'], 'first_in' => $first, 'last_out' => $c['open'] ? null : $lastOut,
                'worked' => $c['worked'], 'running' => $c['open'] ? array_sum(array_map(fn($k) => (int) ($k['running'] ?? 0), $c['clocks'])) : null,
                'plan' => $c['plan'], 'diff' => $hasClock && !$c['open'] ? $c['worked'] - $c['plan'] : null,
                'overtime' => $c['overtime'], 'week_extra' => $c['week_extra'], 'open' => $c['open'],
                'absence' => $c['absence'] ? ['id' => (int) $c['absence']['id'], 'type' => $c['absence']['type'], 'label' => self::ABSENCE_TYPES[$c['absence']['type']] ?? $c['absence']['type'],
                    'from' => $c['absence']['date_from'], 'to' => $c['absence']['date_to'], 'minutes' => $c['absence']['minutes'] !== null ? (int) $c['absence']['minutes'] : null] : null,
                'overtime_reason' => $ot ? ['code' => $ot['reason'], 'label' => self::OVERTIME_REASONS[$ot['reason']] ?? $ot['reason'], 'note' => $ot['note']] : null,
                'absence_reason' => $ab ? ['code' => $ab['reason'], 'label' => self::ABSENCE_REASONS[$ab['reason']] ?? $ab['reason'], 'note' => $ab['note']] : null,
                'status' => $status,
                // [263] Towar zabrany przy wyjściu (odpowiedź « Tak » na tablecie / telefonie) : co.
                'took' => implode(' · ', array_filter(array_map(fn($k) => $k['took'] === null ? null : ($k['took'] !== '' ? $k['took'] : 'tak'), $c['clocks']))) ?: null,
                'comp_proposed' => array_map(fn($x) => ['id' => (int) $x['id'], 'day' => $x['day'], 'minutes' => (int) $x['minutes']], $proposed[$id] ?? []),
            ];
        }
        $rank = ['open' => 0, 'need_overtime' => 1, 'need_absence' => 2, 'ok' => 3];
        usort($people, fn($a, $b) => [$rank[$a['status']], $a['overtime'] === 0, $a['group'] ?? 'zzz', $a['name']] <=> [$rank[$b['status']], $b['overtime'] === 0, $b['group'] ?? 'zzz', $b['name']]);
        $blocking = $counts['open'] + $counts['need_overtime'] + $counts['need_absence'];
        $dow = (int) date('N', strtotime($day));
        $prev = $this->previousWorkday($day);
        $prevValidated = $this->repo->validatedDays($prev, date('Y-m-d', strtotime($prev . ' +1 day')))[$prev] ?? null;
        $closed = $this->repo->closedMonth(substr($day, 0, 7));
        return [
            'day' => $day, 'weekday' => self::DOW_LONG[$dow], 'label' => self::DOW_LONG[$dow] . ' ' . date('d.m.Y', strtotime($day)),
            'holiday' => $g['holidays'][$day] ?? null, 'workday' => $dow <= 5 && !isset($g['holidays'][$day]),
            'today' => $today, 'future' => $day > $today,
            'validated' => $validated ? ['at' => $validated['validated_at'], 'by' => $validated['by_name'], 'summary' => $validated['summary']] : null,
            'previous' => ['day' => $prev, 'label' => self::DOW_LONG[(int) date('N', strtotime($prev))] . ' ' . date('d.m', strtotime($prev)),
                'validated' => $prevValidated ? ['at' => $prevValidated['validated_at'], 'by' => $prevValidated['by_name']] : null],
            'month_closed' => $closed !== null,
            'people' => $people, 'counts' => $counts, 'blocking' => $blocking,
            'can_validate' => $blocking === 0 && $day <= $today && $closed === null,
            'reasons' => ['overtime' => self::OVERTIME_REASONS, 'absence' => self::ABSENCE_REASONS],
            'rules' => $this->rules(),
        ];
    }

    private function previousWorkday(string $day): string
    {
        $d = $day;
        for ($i = 0; $i < 10; $i++) {
            $d = date('Y-m-d', strtotime($d . ' -1 day'));
            $n = (int) date('N', strtotime($d));
            if ($n <= 5 && !Holidays::between($d, $d, ['PL'])) return $d;
        }
        return date('Y-m-d', strtotime($day . ' -1 day'));
    }

    public function validateDay(string $day, ?int $userId, ?int $now = null): array
    {
        $now ??= time();
        $this->checkDate($day);
        $this->checkMonthOpen($day);
        if ($day > date('Y-m-d', $now)) throw new CustomException('Nie można zatwierdzić dnia, który jeszcze nie minął.', 'errors', 422);
        $d = $this->day($day, $now);
        if ($d['blocking'] > 0) {
            $parts = [];
            if ($d['counts']['open']) $parts[] = $d['counts']['open'] . ' ' . self::pl($d['counts']['open'], 'wyjście do uzupełnienia', 'wyjścia do uzupełnienia', 'wyjść do uzupełnienia');
            if ($d['counts']['need_overtime']) $parts[] = $d['counts']['need_overtime'] . ' ' . self::pl($d['counts']['need_overtime'], 'powód nadgodzin', 'powody nadgodzin', 'powodów nadgodzin');
            if ($d['counts']['need_absence']) $parts[] = $d['counts']['need_absence'] . ' ' . self::pl($d['counts']['need_absence'], 'powód nieobecności', 'powody nieobecności', 'powodów nieobecności');
            throw new CustomException('Zostało : ' . implode(', ', $parts) . '.', 'errors', 422);
        }
        $c = $d['counts'];
        $summary = sprintf('%d %s · %s h · nadgodziny %s%s', $c['present'], self::pl($c['present'], 'obecny', 'obecnych', 'obecnych'), self::hm($c['worked']), self::hm($c['overtime']),
            $c['absent'] ? ' · nieobecni ' . $c['absent'] : '');
        $this->repo->validateDay($day, $userId, $summary);
        $this->log->record($userId, 'validate', 'time_day', $day, null, ['summary' => $summary], null);
        return $this->day($day, $now);
    }

    public function reopenDay(string $day, ?int $userId): void
    {
        $this->checkDate($day);
        $this->checkMonthOpen($day);
        if ($this->repo->reopenDays($day, $day) > 0) $this->log->record($userId, 'reopen', 'time_day', $day, null, null, null);
    }

    /** Motif d'un surplus ; code vide = retirer le motif. */
    public function setOvertimeReason(int $employeeId, string $day, string $reason, ?string $note, ?int $userId): void
    {
        $this->checkDate($day);
        $this->checkMonthOpen($day);
        $this->employeeOrFail($employeeId);
        $note = self::cleanNote($note);
        if ($reason === '') {
            $this->repo->deleteNote($employeeId, $day, 'overtime');
            $this->repo->reopenDays($day, $day);
            return;
        }
        if (!isset(self::OVERTIME_REASONS[$reason])) throw new CustomException('Nieznany powód nadgodzin.', 'errors', 422);
        if ($reason === 'inne' && $note === null) throw new CustomException('« Inny powód » : wpisz krótki opis.', 'errors', 422);
        $this->repo->setNote($employeeId, $day, 'overtime', $reason, $note, $userId);
    }

    /**
     * Motif d'une absence un jour ouvré. Urlop, na żądanie et L4 deviennent une entrée du calendrier (le congé compte
     * dans le solde) ; les autres motifs restent sur le jour. Code vide = retirer le motif.
     */
    public function setAbsenceReason(int $employeeId, string $day, string $reason, ?string $note, ?int $userId): void
    {
        $this->checkDate($day);
        $this->checkMonthOpen($day);
        $this->employeeOrFail($employeeId);
        $note = self::cleanNote($note);
        if ($reason === '') {
            $this->repo->deleteNote($employeeId, $day, 'absence');
            $this->repo->reopenDays($day, $day);
            return;
        }
        if (!isset(self::ABSENCE_REASONS[$reason])) throw new CustomException('Nieznany powód nieobecności.', 'errors', 422);
        if ($reason === 'inna' && $note === null) throw new CustomException('« Inny powód » : wpisz krótki opis.', 'errors', 422);
        if (in_array($reason, self::CALENDAR_REASONS, true)) {
            if ($this->repo->absences($day, $day, $employeeId)) throw new CustomException('Ta osoba ma już tego dnia wpis w kalendarzu.', 'errors', 409);
            $this->tx->execute(function () use ($employeeId, $day, $reason, $note, $userId) {
                $this->repo->deleteNote($employeeId, $day, 'absence');
                $this->insertAbsence($employeeId, $reason, $day, $day, $note, $userId);
            });
            return;
        }
        $this->repo->setNote($employeeId, $day, 'absence', $reason, $note, $userId);
    }

    // --- Corrections de pointage (toujours avec un motif, au journal ; le jour est à revalider) ---------------

    public function correctClock(int $clockId, ?string $in, ?string $out, string $note, ?int $userId): array
    {
        $clock = $this->repo->clock($clockId) ?? throw new CustomException('Nie znaleziono odbicia.', 'errors', 404);
        $note = self::cleanNote($note) ?? throw new CustomException('Korekta odbicia : wpisz powód.', 'errors', 422);
        $in = $in !== null && $in !== '' ? self::dateTime($in, 'wejścia') : $clock['clock_in'];
        $out = $out !== null && $out !== '' ? self::dateTime($out, 'wyjścia') : $clock['clock_out'];
        if ($out !== null && $out <= $in) throw new CustomException('Wyjście musi być po wejściu.', 'errors', 422);
        if ($out !== null && strtotime($out) - strtotime($in) > 20 * 3600) throw new CustomException('Odbicie dłuższe niż 20 h — sprawdź datę wyjścia.', 'errors', 422);
        foreach ([substr($clock['clock_in'], 0, 10), substr($in, 0, 10)] as $d) $this->checkMonthOpen($d);
        $this->tx->execute(function () use ($clockId, $clock, $in, $out, $note, $userId) {
            $this->clocks->correctClock($clockId, $in, $out, $note, $userId);
            $this->log->record($userId, 'correct', 'clock', $clockId, ['clock_in' => $clock['clock_in'], 'clock_out' => $clock['clock_out']], ['clock_in' => $in, 'clock_out' => $out, 'note' => $note], null);
            foreach (array_unique([substr($clock['clock_in'], 0, 10), substr($in, 0, 10)]) as $d) $this->repo->reopenDays($d, $d);
        });
        return $this->repo->clock($clockId);
    }

    public function addClock(int $employeeId, string $in, string $out, string $note, ?int $userId): array
    {
        $this->employeeOrFail($employeeId);
        $note = self::cleanNote($note) ?? throw new CustomException('Odbicie ręczne : wpisz powód (np. zapomniane odbicie).', 'errors', 422);
        $in = self::dateTime($in, 'wejścia');
        $out = self::dateTime($out, 'wyjścia');
        if ($out <= $in) throw new CustomException('Wyjście musi być po wejściu.', 'errors', 422);
        if (strtotime($out) - strtotime($in) > 20 * 3600) throw new CustomException('Odbicie dłuższe niż 20 h — sprawdź datę wyjścia.', 'errors', 422);
        if ($in > date('Y-m-d H:i:s')) throw new CustomException('Odbicie ręczne nie może być w przyszłości.', 'errors', 422);
        $this->checkMonthOpen(substr($in, 0, 10));
        $id = $this->tx->execute(function () use ($employeeId, $in, $out, $note, $userId) {
            $id = $this->clocks->clockIn($employeeId, $in, null, null, 'manual', $note);
            $this->clocks->clockOut($id, $out, null);
            $this->log->record($userId, 'create', 'clock', $id, null, ['employee_id' => $employeeId, 'clock_in' => $in, 'clock_out' => $out, 'note' => $note, 'source' => 'manual'], null);
            $this->repo->reopenDays(substr($in, 0, 10), substr($in, 0, 10));
            return $id;
        });
        return $this->repo->clock((int) $id);
    }

    // =====================================================================================================
    // Miesiąc (maquette 2)
    // =====================================================================================================

    /**
     * [269] Co osoba zabrała przy wyjściu danego dnia (okno z ikony koszyka) : każde wyjście z odpowiedzią « Tak » —
     * godzina, źródło (tablet / telefon), pozycje (produkt, sztuki, z braków) i « inne » ; odpowiedź tekstowa sprzed [264].
     */
    public function goods(int $employeeId, string $day): array
    {
        $this->checkDate($day);
        $e = $this->employeeOrFail($employeeId);
        $exits = [];
        foreach ($this->repo->goods($employeeId, $day) as $r) {
            $id = (int) $r['id_clock'];
            $exits[$id] ??= ['clock_out' => $r['clock_out'], 'source' => $r['source'], 'note' => $r['note'], 'lines' => []];
            if ($r['product'] !== null) {
                $exits[$id]['lines'][] = ['product' => (string) $r['product'], 'quantity' => (float) $r['quantity'], 'waste' => (bool) $r['from_waste'],
                    'other' => $r['id_final_product'] === null];
            }
        }
        $pieces = 0.0;
        foreach ($exits as $x) foreach ($x['lines'] as $l) if (!$l['other']) $pieces += $l['quantity'];
        return ['employee' => ['id' => $employeeId, 'name' => $e['name'], 'group' => $e['group_name']], 'day' => $day,
            'exits' => array_values($exits), 'pieces' => $pieces];
    }

    public function month(string $month, ?int $now = null): array
    {
        $this->checkMonth($month);
        $now ??= time();
        $today = date('Y-m-d', $now);
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $staff = $this->staffFor($from, $to);
        $g = $this->grid($from, $to, $staff, $now);
        $notes = $this->notesBy($from, $to);
        $validated = $this->repo->validatedDays($from, date('Y-m-d', strtotime($to . ' +1 day')));
        $bank = $this->bankAll($now);

        $days = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $dow = (int) date('N', strtotime($d));
            $days[$d] = ['date' => $d, 'n' => (int) substr($d, 8, 2), 'dow' => self::DOW[$dow], 'workday' => $dow <= 5 && !isset($g['holidays'][$d]),
                'holiday' => $g['holidays'][$d] ?? null, 'validated' => isset($validated[$d]), 'past' => $d <= $today, 'activity' => false];
        }
        $people = [];
        $totals = ['worked' => 0, 'overtime' => 0, 'leave' => 0, 'l4' => 0, 'wn' => 0, 'bank' => 0];
        foreach ($staff as $p) {
            $id = (int) $p['id'];
            $cells = [];
            $row = ['worked' => 0, 'overtime' => 0, 'leave' => 0, 'l4' => 0, 'wn' => 0];
            foreach ($g['cells'][$id] ?? [] as $d => $c) {
                $status = self::status($c, $notes[$id][$d]['overtime'] ?? null, $notes[$id][$d]['absence'] ?? null, $today);
                if ($c['clocks'] !== []) $days[$d]['activity'] = true;
                $a = $c['absence']['type'] ?? null;
                if ($a !== null && $c['workday']) {
                    if (in_array($a, self::LEAVE_TYPES, true)) $row['leave']++;
                    elseif ($a === 'l4') $row['l4']++;
                    elseif ($a === 'wn') $row['wn']++;
                }
                $row['worked'] += $c['worked'];
                if (isset($validated[$d])) $row['overtime'] += $c['overtime'];
                $absNote = $notes[$id][$d]['absence']['reason'] ?? null;
                $cells[$d] = ['w' => $c['worked'], 'ot' => $c['overtime'], 'a' => $a !== null ? self::TYPE_SHORT[$a] : ($absNote === 'nieusprawiedliwiona' ? 'NN' : null),
                    // [269] towar zabrany przy wyjściu tego dnia (ikona koszyka w tabeli)
                    'g' => (bool) array_filter($c['clocks'], fn($k) => $k['took'] !== null),
                    'st' => $c['clocks'] === [] && $status === 'ok' ? 'none' : $status, 'open' => $c['open']];
            }
            if ($row['worked'] === 0 && !array_filter($cells, fn($c) => $c['a'] !== null || $c['st'] !== 'none')) continue;
            $b = $bank[$id] ?? null;
            $people[] = ['employee_id' => $id, 'name' => $p['name'], 'group' => $p['group_name'], 'cells' => $cells] + $row
                + ['bank' => $b['bank'] ?? 0, 'deadline' => $b['deadline'] ?? null];
            foreach ($row as $k => $v) $totals[$k] += $v;
            $totals['bank'] += $b['bank'] ?? 0;
        }
        $mustValidate = array_filter($days, fn($d) => $d['past'] && ($d['workday'] || $d['activity']));
        $pending = array_values(array_map(fn($d) => $d['date'], array_filter($mustValidate, fn($d) => !$d['validated'])));
        $closed = $this->repo->closedMonth($month);
        return [
            'month' => $month, 'label' => self::monthLabel($month), 'days' => array_values($days), 'people' => $people, 'totals' => $totals,
            'validated' => count(array_filter($mustValidate, fn($d) => $d['validated'])), 'to_validate' => count($mustValidate), 'pending' => $pending,
            'closed' => $closed ? ['at' => $closed['closed_at'], 'by' => $closed['by_name']] : null,
            'can_close' => $closed === null && $pending === [] && $to <= $today,
            'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => date('Y-m', strtotime($from . ' +1 month')),
            'rules' => $this->rules(),
        ];
    }

    public function closeMonth(string $month, ?int $userId, ?int $now = null): array
    {
        $m = $this->month($month, $now);
        if ($m['closed'] !== null) throw new CustomException('Miesiąc jest już zamknięty.', 'errors', 409);
        if ($m['month'] . '-' . date('t', strtotime($month . '-01')) > date('Y-m-d', $now ?? time())) throw new CustomException('Miesiąc jeszcze trwa — zamknięcie po ostatnim dniu.', 'errors', 422);
        if ($m['pending']) throw new CustomException('Najpierw zatwierdź dni : ' . implode(', ', array_map(fn($d) => date('d.m', strtotime($d)), $m['pending'])) . '.', 'errors', 422);
        $this->repo->closeMonth($month, $userId);
        $this->log->record($userId, 'close', 'time_month', $month, null, ['people' => count($m['people'])], null);
        return $this->month($month, $now);
    }

    public function reopenMonth(string $month, ?int $userId): void
    {
        $this->checkMonth($month);
        $this->repo->reopenMonth($month);
        $this->log->record($userId, 'reopen', 'time_month', $month, null, null, null);
    }

    /**
     * Karta czasu pracy d'une personne pour le mois (fiche A4) : jours, entrées / sorties, heures, heures sup., motif,
     * validation ; totaux, absences, banque à la fin du mois.
     */
    public function sheet(int $employeeId, string $month, ?int $now = null): array
    {
        $this->checkMonth($month);
        $now ??= time();
        $e = $this->employeeOrFail($employeeId);
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $g = $this->grid($from, $to, [['id' => $employeeId]], $now);
        $notes = $this->notesBy($from, $to);
        $validated = $this->repo->validatedDays($from, date('Y-m-d', strtotime($to . ' +1 day')));
        $rows = [];
        $tot = ['worked' => 0, 'overtime' => 0, 'leave' => 0, 'l4' => 0, 'wn' => 0, 'wn_minutes' => 0, 'other' => 0, 'days' => 0];
        foreach ($g['cells'][$employeeId] ?? [] as $d => $c) {
            $ot = $notes[$employeeId][$d]['overtime'] ?? null;
            $ab = $notes[$employeeId][$d]['absence'] ?? null;
            $a = $c['absence']['type'] ?? null;
            if ($a !== null && $c['workday']) {
                if (in_array($a, self::LEAVE_TYPES, true)) $tot['leave']++;
                elseif ($a === 'l4') $tot['l4']++;
                elseif ($a === 'wn') { $tot['wn']++; $tot['wn_minutes'] += (int) $c['absence']['minutes']; }
                else $tot['other']++;
            }
            $tot['worked'] += $c['worked'];
            if ($c['clocks'] !== []) $tot['days']++;
            $v = $validated[$d] ?? null;
            if ($v !== null) $tot['overtime'] += $c['overtime'];
            $reason = [];
            if ($c['overtime'] > 0 && $ot) $reason[] = (self::OVERTIME_REASONS[$ot['reason']] ?? $ot['reason']) . ($ot['note'] ? ' — ' . $ot['note'] : '');
            if ($a !== null) $reason[] = self::ABSENCE_TYPES[$a] . ($a === 'wn' ? ' (' . self::hm((int) $c['absence']['minutes']) . ')' : '');
            elseif ($ab) $reason[] = (self::ABSENCE_REASONS[$ab['reason']] ?? $ab['reason']) . ($ab['note'] ? ' — ' . $ab['note'] : '');
            if ($c['holiday'] && $c['clocks'] === []) $reason[] = $c['holiday'];
            $rows[] = [
                'date' => $d, 'dow' => self::DOW[(int) date('N', strtotime($d))], 'workday' => $c['workday'], 'holiday' => $c['holiday'],
                'in' => $c['clocks'] ? substr($c['clocks'][0]['in'], 11, 5) : '', 'out' => $c['open'] ? 'brak' : ($c['clocks'] ? substr((string) end($c['clocks'])['out'], 11, 5) : ''),
                'worked' => $c['worked'], 'overtime' => $c['overtime'], 'reason' => implode(' · ', $reason),
                'validated' => $v ? ['at' => $v['validated_at'], 'by' => $v['by_name']] : null, 'open' => $c['open'],
            ];
        }
        $bank = $this->bankFor($employeeId, min($to, date('Y-m-d', $now)), $now);
        return ['employee' => ['id' => $employeeId, 'name' => $e['name'], 'group' => $e['group_name']], 'month' => $month, 'label' => self::monthLabel($month),
            'rows' => $rows, 'totals' => $tot, 'bank' => $bank, 'rules' => $this->rules(), 'closed' => $this->repo->closedMonth($month) !== null];
    }

    // =====================================================================================================
    // Nadgodziny : banque (FIFO, 3 mois) et jours de récupération (maquette 4)
    // =====================================================================================================

    /**
     * Banque de toutes les personnes au jour $asOf : heures sup. des jours validés moins les jours de récupération
     * signés (les plus anciennes d'abord). @return array<int, array> personne => banque
     */
    private function bankAll(?int $now = null, ?string $asOf = null): array
    {
        $now ??= time();
        $asOf ??= date('Y-m-d', $now);
        $r = $this->rules();
        $earliest = date('Y-m-d', strtotime($asOf . ' -24 months'));
        $validated = $this->repo->validatedDays($earliest, date('Y-m-d', strtotime($asOf . ' +1 day')));
        $staff = $this->repo->staff();
        $out = [];
        $comps = [];
        foreach ($this->repo->comps() as $c) $comps[(int) $c['id_employee']][] = $c;
        if ($validated) {
            $from = (string) array_key_first($validated);
            $g = $this->grid($from, $asOf, $staff, $now);
            $notes = $this->notesBy($from, $asOf);
        }
        foreach ($staff as $p) {
            $id = (int) $p['id'];
            $entries = [];
            foreach ($validated ? ($g['cells'][$id] ?? []) : [] as $d => $c) {
                if (!isset($validated[$d]) || $c['overtime'] <= 0) continue;
                $entries[] = ['day' => $d, 'minutes' => $c['overtime'], 'left' => $c['overtime'], 'reason' => $notes[$id][$d]['overtime']['reason'] ?? null,
                    'deadline' => date('Y-m-d', strtotime($d . ' +' . $r['recovery_months'] . ' months'))];
            }
            $signed = 0;
            $reserved = 0;
            $recovered = [];
            foreach ($comps[$id] ?? [] as $c) {
                // Jour signé : la banque baisse dès la signature (même pour un jour de congé encore à venir).
                if ($c['status'] === 'signed' && substr((string) $c['signed_at'], 0, 10) <= $asOf) { $signed += (int) $c['minutes']; $recovered[] = $c; }
                elseif ($c['status'] === 'proposed') $reserved += (int) $c['minutes'];
            }
            $use = $signed;
            foreach ($entries as &$en) {
                $take = min($use, $en['left']);
                $en['left'] -= $take;
                $use -= $take;
            }
            unset($en);
            $left = array_values(array_filter($entries, fn($en) => $en['left'] > 0));
            $bank = array_sum(array_column($left, 'left'));
            $reasons = [];
            $since = date('Y-m-d', strtotime($asOf . ' -' . $r['recovery_months'] . ' months'));
            foreach ($entries as $en) if ($en['day'] >= $since && $en['reason']) $reasons[$en['reason']] = ($reasons[$en['reason']] ?? 0) + 1;
            arsort($reasons);
            $oldest = $left[0] ?? null;
            $out[$id] = [
                'employee_id' => $id, 'name' => $p['name'], 'group' => $p['group_name'],
                'earned' => array_sum(array_column($entries, 'minutes')), 'recovered' => $signed, 'bank' => $bank, 'reserved' => min($reserved, $bank),
                'available' => max(0, $bank - $reserved), 'overrun' => max(0, $signed - array_sum(array_column($entries, 'minutes'))),
                'oldest' => $oldest ? ['day' => $oldest['day'], 'minutes' => $oldest['left']] : null,
                'deadline' => $oldest['deadline'] ?? null,
                'days_left' => $oldest ? (int) round((strtotime($oldest['deadline']) - strtotime($asOf)) / 86400) : null,
                'overdue' => array_sum(array_map(fn($en) => $en['deadline'] < $asOf ? $en['left'] : 0, $left)),
                'expiring' => array_sum(array_map(fn($en) => $en['deadline'] <= date('Y-m-d', strtotime($asOf . ' +30 days')) ? $en['left'] : 0, $left)),
                'entries' => $left,
                'reasons' => array_map(fn($code, $n) => ['code' => $code, 'label' => self::OVERTIME_REASONS[$code] ?? $code, 'count' => $n], array_keys($reasons), $reasons),
            ];
        }
        return $out;
    }

    /** Banque d'une personne (fiche A4, tablette). */
    public function bankFor(int $employeeId, ?string $asOf = null, ?int $now = null): array
    {
        $all = $this->bankAll($now, $asOf);
        return $all[$employeeId] ?? ['employee_id' => $employeeId, 'earned' => 0, 'recovered' => 0, 'bank' => 0, 'reserved' => 0, 'available' => 0, 'overrun' => 0,
            'oldest' => null, 'deadline' => null, 'days_left' => null, 'overdue' => 0, 'expiring' => 0, 'entries' => [], 'reasons' => []];
    }

    public function overtime(?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $bank = $this->bankAll($now);
        $people = array_values(array_filter($bank, fn($b) => $b['bank'] > 0 || $b['reserved'] > 0));
        usort($people, fn($a, $b) => [$a['deadline'] ?? '9999', -$a['bank']] <=> [$b['deadline'] ?? '9999', -$b['bank']]);
        $names = array_column($bank, 'name', 'employee_id');
        $comps = [];
        $recoveredMonth = 0;
        $recoveredDays = 0;
        foreach ($this->repo->comps() as $c) {
            if ($c['status'] === 'signed' && substr($c['day'], 0, 7) === substr($today, 0, 7)) { $recoveredMonth += (int) $c['minutes']; $recoveredDays++; }
            if ($c['status'] === 'proposed' || $c['day'] >= date('Y-m-d', strtotime($today . ' -31 days'))) {
                $comps[] = ['id' => (int) $c['id'], 'employee_id' => (int) $c['id_employee'], 'name' => $names[(int) $c['id_employee']] ?? '—', 'day' => $c['day'],
                    'minutes' => (int) $c['minutes'], 'status' => $c['status'], 'signed_at' => $c['signed_at'], 'proposed_by' => $c['proposed_by_name']];
            }
        }
        $expiring = array_filter($people, fn($b) => $b['expiring'] > 0);
        return [
            'kpis' => [
                'bank' => array_sum(array_column($people, 'bank')), 'people' => count(array_filter($people, fn($b) => $b['bank'] > 0)),
                'expiring' => array_sum(array_column($people, 'expiring')), 'expiring_people' => count($expiring),
                'overdue' => array_sum(array_column($people, 'overdue')),
                'recovered_month' => $recoveredMonth, 'recovered_days' => $recoveredDays,
            ],
            'people' => array_map(function ($b) { unset($b['entries']); return $b; }, $people),
            'comps' => $comps, 'rules' => $this->rules(), 'today' => $today,
        ];
    }

    /** Jour de récupération proposé par le kierownik (à faire signer par la personne). */
    public function proposeComp(int $employeeId, string $day, int $minutes, ?int $userId, ?int $now = null): array
    {
        $now ??= time();
        $this->checkDate($day);
        $this->employeeOrFail($employeeId);
        $r = $this->rules();
        if ($day < date('Y-m-d', $now)) throw new CustomException('Dzień wolny : wybierz dzisiaj albo dzień w przyszłości.', 'errors', 422);
        $this->checkMonthOpen($day);
        $g = $this->grid($day, $day, [['id' => $employeeId]], $now);
        $c = $g['cells'][$employeeId][$day];
        if (!$c['workday']) throw new CustomException('To nie jest dzień pracy (weekend albo święto).', 'errors', 422);
        if ($c['absence'] !== null) throw new CustomException('Ta osoba ma już tego dnia nieobecność w kalendarzu.', 'errors', 422);
        if ($minutes < 15 || $minutes > $r['day_plan']) throw new CustomException('Dzień wolny : od 0:15 do ' . self::hm($r['day_plan']) . ' godzin.', 'errors', 422);
        foreach ($this->repo->comps($employeeId) as $x) {
            if ($x['day'] === $day) throw new CustomException('Na ten dzień jest już dzień wolny za nadgodziny.', 'errors', 409);
        }
        $b = $this->bankFor($employeeId, null, $now);
        if ($minutes > $b['available']) throw new CustomException('Bank nadgodzin do odbioru : ' . self::hm($b['available']) . ' (z ' . self::hm($b['bank']) . ', zarezerwowane ' . self::hm($b['reserved']) . ').', 'errors', 422);
        $id = $this->repo->insertComp($employeeId, $day, $minutes, $userId);
        $this->log->record($userId, 'propose', 'time_comp', $id, null, ['employee_id' => $employeeId, 'day' => $day, 'minutes' => $minutes], null);
        return $this->compRow($id);
    }

    /**
     * Signature d'un jour de récupération (PNG data URL) : à l'écran du kierownik ($employeeId null) ou à la tablette
     * de bureau après le PIN ($employeeId = personne identifiée, qui doit être celle du jour). Le jour devient une
     * absence « wolne za nadgodziny » du calendrier et réduit la banque.
     */
    public function signComp(int $compId, mixed $image, ?int $employeeId, ?int $deviceId, ?int $userId): array
    {
        $comp = $this->repo->comp($compId) ?? throw new CustomException('Nie znaleziono dnia wolnego.', 'errors', 404);
        if ($employeeId !== null && (int) $comp['id_employee'] !== $employeeId) throw new CustomException('Ten dzień wolny dotyczy innej osoby.', 'errors', 403);
        if ($comp['status'] !== 'proposed') throw new CustomException($comp['status'] === 'signed' ? 'Ten dzień wolny jest już podpisany.' : 'Ten dzień wolny został anulowany.', 'errors', 409);
        $png = ExecutionService::decodePng($image);
        $owner = (int) $comp['id_employee'];
        return $this->tx->execute(function () use ($comp, $compId, $png, $owner, $deviceId, $userId) {
            $hash = hash('sha256', $png);
            $stored = $this->storage->store($owner, $png, $hash);
            $sig = $this->clocks->insertSignature(['id_employee' => $owner, 'purpose' => 'comp_day', 'ref' => (string) $compId, 'id_device' => $deviceId, 'sha256' => $hash] + $stored);
            if (!$this->repo->markCompSigned($compId, $sig)) throw new CustomException('Ten dzień wolny jest już podpisany.', 'errors', 409);
            $this->insertAbsence($owner, 'wn', $comp['day'], $comp['day'], 'Odbiór nadgodzin (podpis ' . date('d.m.Y H:i') . ')', $userId, (int) $comp['minutes'], $compId);
            $this->log->record($userId, 'sign', 'time_comp', $compId, null, ['employee_id' => $owner, 'day' => $comp['day'], 'minutes' => (int) $comp['minutes'], 'signature_id' => $sig, 'sha256' => $hash, 'device_id' => $deviceId], null);
            return $this->compRow($compId);
        });
    }

    public function cancelComp(int $compId, ?int $userId, ?int $now = null): void
    {
        $comp = $this->repo->comp($compId) ?? throw new CustomException('Nie znaleziono dnia wolnego.', 'errors', 404);
        if ($comp['status'] === 'cancelled') return;
        if ($comp['status'] === 'signed' && $comp['day'] < date('Y-m-d', $now ?? time())) throw new CustomException('Dzień wolny już minął — nie można go anulować.', 'errors', 409);
        $this->checkMonthOpen($comp['day']);
        $this->tx->execute(function () use ($comp, $compId, $userId) {
            $this->repo->cancelComp($compId);
            if ($comp['status'] === 'signed') $this->repo->deleteAbsenceOfComp($compId);
            $this->log->record($userId, 'cancel', 'time_comp', $compId, ['status' => $comp['status']], ['status' => 'cancelled'], null);
        });
    }

    /** Jours à signer par la personne (tablette de bureau). */
    public function pendingComps(int $employeeId): array
    {
        $out = [];
        foreach ($this->repo->comps($employeeId, 'proposed') as $c) {
            $out[] = ['id' => (int) $c['id'], 'day' => $c['day'], 'day_label' => self::DOW_LONG[(int) date('N', strtotime($c['day']))] . ' ' . date('d.m.Y', strtotime($c['day'])),
                'minutes' => (int) $c['minutes'], 'hours' => self::hm((int) $c['minutes'])];
        }
        return $out;
    }

    private function compRow(int $id): array
    {
        $c = $this->repo->comp($id);
        return ['id' => (int) $c['id'], 'employee_id' => (int) $c['id_employee'], 'day' => $c['day'], 'minutes' => (int) $c['minutes'], 'status' => $c['status'],
            'signed_at' => $c['signed_at']];
    }

    // =====================================================================================================
    // Urlopy i dni wolne : calendrier (maquette 3)
    // =====================================================================================================

    public function calendar(string $from, int $days = 28, ?int $now = null): array
    {
        $this->checkDate($from);
        $now ??= time();
        $today = date('Y-m-d', $now);
        $days = max(7, min(62, $days));
        $to = date('Y-m-d', strtotime($from . ' +' . ($days - 1) . ' days'));
        $holidays = array_map(fn($byCountry) => (string) reset($byCountry), Holidays::between($from, $to, ['PL']));
        $staff = $this->repo->staff();
        $absences = [];
        foreach ($this->repo->absences($from, $to) as $a) $absences[(int) $a['id_employee']][] = $a;
        $leave = $this->leaveBalances((int) substr($from, 0, 4), $now);
        $bank = $this->bankAll($now);
        $grid = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $dow = (int) date('N', strtotime($d));
            $grid[] = ['date' => $d, 'n' => (int) substr($d, 8, 2), 'dow' => self::DOW[$dow], 'workday' => $dow <= 5 && !isset($holidays[$d]), 'holiday' => $holidays[$d] ?? null,
                'today' => $d === $today, 'month_start' => substr($d, 8, 2) === '01'];
        }
        $perDay = [];
        $perGroupDay = [];
        $people = [];
        foreach ($staff as $p) {
            $id = (int) $p['id'];
            $rows = [];
            foreach ($absences[$id] ?? [] as $a) {
                $rows[] = ['id' => (int) $a['id'], 'type' => $a['type'], 'short' => self::TYPE_SHORT[$a['type']], 'label' => self::ABSENCE_TYPES[$a['type']],
                    'from' => $a['date_from'], 'to' => $a['date_to'], 'minutes' => $a['minutes'] !== null ? (int) $a['minutes'] : null, 'comp' => $a['id_comp'] !== null, 'note' => $a['note']];
                for ($d = max($a['date_from'], $from); $d <= min($a['date_to'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $dow = (int) date('N', strtotime($d));
                    if ($dow > 5 || isset($holidays[$d])) continue;
                    $perDay[$d] = ($perDay[$d] ?? 0) + 1;
                    if ($p['group_id'] !== null) $perGroupDay[$p['group_name']][$d] = ($perGroupDay[$p['group_name']][$d] ?? 0) + 1;
                }
            }
            $people[] = ['employee_id' => $id, 'name' => $p['name'], 'group' => $p['group_name'], 'absences' => $rows, 'bank' => $bank[$id]['bank'] ?? 0] + ($leave[$id] ?? []);
        }
        $warnings = [];
        foreach ($perGroupDay as $group => $byDay) {
            foreach ($byDay as $d => $n) if ($n > 1) $warnings[] = ['date' => $d, 'group' => $group, 'count' => $n];
        }
        usort($warnings, fn($a, $b) => [$a['date'], $a['group']] <=> [$b['date'], $b['group']]);
        return ['from' => $from, 'to' => $to, 'days' => $grid, 'people' => $people, 'absent' => $perDay, 'warnings' => $warnings,
            'types' => array_diff_key(self::ABSENCE_TYPES, ['wn' => true]), 'rules' => $this->rules(), 'year' => (int) substr($from, 0, 4),
            'prev' => date('Y-m-d', strtotime($from . ' -' . $days . ' days')), 'next' => date('Y-m-d', strtotime($from . ' +' . $days . ' days'))];
    }

    /**
     * Soldes de congé de l'année : droit (n_time_leave ou time.leave_days), pris (jours ouvrés passés), prévus, reste ;
     * « na żądanie » sur 4. @return array<int, array>
     */
    public function leaveBalances(int $year, ?int $now = null): array
    {
        $today = date('Y-m-d', $now ?? time());
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $holidays = Holidays::between($from, $to, ['PL']);
        $rights = $this->repo->leaveRights();
        $default = $this->rules()['leave_days'];
        $out = [];
        foreach ($this->repo->staff() as $p) {
            $id = (int) $p['id'];
            $out[$id] = ['right' => $rights[$id] ?? $default, 'custom_right' => isset($rights[$id]), 'used' => 0, 'planned' => 0, 'on_demand' => 0, 'l4' => 0];
        }
        foreach ($this->repo->absences($from, $to) as $a) {
            $id = (int) $a['id_employee'];
            if (!isset($out[$id])) continue;
            for ($d = max($a['date_from'], $from); $d <= min($a['date_to'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if ((int) date('N', strtotime($d)) > 5 || isset($holidays[$d])) continue;
                if (in_array($a['type'], self::LEAVE_TYPES, true)) {
                    $out[$id][$d <= $today ? 'used' : 'planned']++;
                    if ($a['type'] === 'na_zadanie') $out[$id]['on_demand']++;
                } elseif ($a['type'] === 'l4') {
                    $out[$id]['l4']++;
                }
            }
        }
        foreach ($out as &$o) $o['remaining'] = $o['right'] - $o['used'] - $o['planned'];
        return $out;
    }

    public function addAbsence(int $employeeId, string $type, string $from, string $to, ?string $note, ?int $userId, ?int $now = null): int
    {
        $this->employeeOrFail($employeeId);
        if (!isset(self::ABSENCE_TYPES[$type]) || $type === 'wn') throw new CustomException('Wolne za nadgodziny dodaje się w zakładce Nadgodziny (z podpisem pracownika).', 'errors', 422);
        $this->checkDate($from);
        $this->checkDate($to);
        if ($to < $from) throw new CustomException('Data « do » jest przed datą « od ».', 'errors', 422);
        if ((strtotime($to) - strtotime($from)) / 86400 > 366) throw new CustomException('Najwyżej rok jednym wpisem.', 'errors', 422);
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) $this->checkMonthOpen($m . '-01');
        foreach ($this->repo->absences($from, $to, $employeeId) as $a) {
            throw new CustomException(sprintf('Ta osoba ma już w tym okresie : %s %s – %s.', self::ABSENCE_TYPES[$a['type']], date('d.m', strtotime($a['date_from'])), date('d.m', strtotime($a['date_to']))), 'errors', 409);
        }
        if ($type === 'na_zadanie') {
            $year = (int) substr($from, 0, 4);
            $bal = $this->leaveBalances($year, $now)[$employeeId] ?? ['on_demand' => 0];
            $holidays = Holidays::between($from, $to, ['PL']);
            $n = 0;
            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) if ((int) date('N', strtotime($d)) <= 5 && !isset($holidays[$d])) $n++;
            if ($bal['on_demand'] + $n > self::ON_DEMAND_DAYS) throw new CustomException('Urlop na żądanie : najwyżej ' . self::ON_DEMAND_DAYS . ' dni w roku (wykorzystane ' . $bal['on_demand'] . ').', 'errors', 422);
        }
        return (int) $this->tx->execute(fn() => $this->insertAbsence($employeeId, $type, $from, $to, self::cleanNote($note), $userId));
    }

    public function deleteAbsence(int $id, ?int $userId): void
    {
        $a = $this->repo->absence($id) ?? throw new CustomException('Nie znaleziono wpisu.', 'errors', 404);
        if ($a['id_comp'] !== null) throw new CustomException('To dzień wolny za nadgodziny : anuluj go w zakładce Nadgodziny.', 'errors', 409);
        for ($m = substr($a['date_from'], 0, 7); $m <= substr($a['date_to'], 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) $this->checkMonthOpen($m . '-01');
        $this->tx->execute(function () use ($a, $id, $userId) {
            $this->repo->deleteAbsence($id);
            $this->repo->reopenDays($a['date_from'], $a['date_to']);
            $this->log->record($userId, 'delete', 'time_absence', $id, $a, null, null);
        });
    }

    public function setLeaveRight(int $employeeId, int $days, ?int $userId): void
    {
        $this->employeeOrFail($employeeId);
        if ($days < 0 || $days > 40) throw new CustomException('Urlop : od 0 do 40 dni w roku.', 'errors', 422);
        $this->repo->setLeaveRight($employeeId, $days, $userId);
        $this->log->record($userId, 'update', 'time_leave', $employeeId, null, ['days_per_year' => $days], null);
    }

    /** Entrée du calendrier ; les jours passés concernés sont à revalider. */
    private function insertAbsence(int $employeeId, string $type, string $from, string $to, ?string $note, ?int $userId, ?int $minutes = null, ?int $compId = null): int
    {
        $id = $this->repo->insertAbsence(['id_employee' => $employeeId, 'date_from' => $from, 'date_to' => $to, 'type' => $type, 'minutes' => $minutes,
            'id_comp' => $compId, 'note' => $note, 'created_by' => $userId]);
        $this->repo->reopenDays($from, $to);
        $this->log->record($userId, 'create', 'time_absence', $id, null, ['employee_id' => $employeeId, 'type' => $type, 'from' => $from, 'to' => $to, 'minutes' => $minutes], null);
        return $id;
    }

    // =====================================================================================================
    // Outils
    // =====================================================================================================

    private function employeeOrFail(int $id): array
    {
        return $this->repo->employee($id) ?? throw new CustomException('Nie znaleziono pracownika.', 'errors', 404);
    }

    private function checkMonthOpen(string $day): void
    {
        if ($this->repo->closedMonth(substr($day, 0, 7)) !== null) {
            throw new CustomException('Miesiąc ' . self::monthLabel(substr($day, 0, 7)) . ' jest zamknięty — zmiany niemożliwe.', 'errors', 409);
        }
    }

    private function checkDate(string $day): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        }
    }

    private function checkMonth(string $month): void
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new CustomException('Nieprawidłowy miesiąc.', 'errors', 422);
    }

    private static function dateTime(string $v, string $what): string
    {
        $v = str_replace('T', ' ', trim($v));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
        $t = \DateTime::createFromFormat('Y-m-d H:i:s', $v);
        if (!$t || $t->format('Y-m-d H:i:s') !== $v) throw new CustomException("Nieprawidłowa godzina {$what}.", 'errors', 422);
        return $v;
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);
        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    public static function monthLabel(string $month): string
    {
        $names = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        return $names[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) return $one;
        $d = $n % 10;
        $dd = $n % 100;
        return $d >= 2 && $d <= 4 && ($dd < 12 || $dd > 14) ? $few : $many;
    }
}
