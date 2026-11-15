<?php
declare(strict_types=1);

namespace App\Services\Workshop\Time;

use App\Core\TransactionManager;
use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Workshop\AuditLogRepository;
use App\Repositories\Workshop\TimeRepository;
use App\Services\Notification\AppNotificationService;

/**
 * [288] Wnioski o urlop z telefonu (makieta 49) i ich zatwierdzanie w kalendarzu Urlopy (makieta 48).
 *
 * Telefon powiązany z osobą (odbicie QR) : saldo roku (przysługuje, zaległy z poprzedniego roku do 01.06, wzięte,
 * zaplanowane, zostało), wniosek od–do (urlop wypoczynkowy albo na żądanie), lista « Moje urlopy » z anulowaniem
 * wniosku do zatwierdzenia. Wniosek = wpis « do zatwierdzenia » (kreskowany w kalendarzu) ; kierownik produkcji
 * zatwierdza (→ wpis n_time_absence, jak z biura) albo odrzuca z powodem. Powiadomienie w dzwonku kierownika
 * produkcji (bez kierownika : administratorzy) ; bez e-maili.
 */
final class LeaveRequestService
{
    public const MAX_DAYS_AHEAD = 400;

    public function __construct(
        private TimeService $time,
        private TimeRepository $repo,
        private HrRepository $hr,
        private AppNotificationService $notifications,
        private TransactionManager $tx,
        private AuditLogRepository $log,
    ) {
    }

    /** Écran du téléphone : saldo, fériés (sélecteur de dates), wnioski. */
    public function phone(int $employeeId, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $year = (int) date('Y', $now);
        $employee = $this->repo->employee($employeeId) ?? throw new CustomException('Nie znaleziono pracownika.', 'errors', 404);
        $balances = [$year => $this->balance($employeeId, $year, $now)];
        // Wniosek na przyszły rok (np. grudzień → styczeń) : saldo przyszłego roku.
        if ((int) date('n', $now) >= 10) $balances[$year + 1] = $this->balance($employeeId, $year + 1, $now);
        $holidays = array_keys(Holidays::between($today, date('Y-m-d', strtotime($today . ' +' . self::MAX_DAYS_AHEAD . ' days')), ['PL']));
        $requests = array_map([TimeService::class, 'requestRow'], $this->repo->employeeLeaveRequests($employeeId, $year . '-01-01'));
        $absences = array_values(array_map(fn($a) => ['type' => $a['type'], 'label' => TimeService::ABSENCE_TYPES[$a['type']], 'from' => $a['date_from'], 'to' => $a['date_to'],
            'days' => TimeService::workdays($a['date_from'], $a['date_to'])],
            array_filter($this->repo->absences($year . '-01-01', ($year + 1) . '-12-31', $employeeId), fn($a) => in_array($a['type'], ['urlop', 'na_zadanie'], true))));
        return ['employee' => ['id' => $employeeId, 'name' => $employee['name'] ?? null], 'today' => $today, 'year' => $year, 'balances' => $balances,
            'holidays' => $holidays, 'requests' => $requests, 'absences' => $absences, 'on_demand_max' => TimeService::ON_DEMAND_DAYS,
            'carry_until' => $this->time->rules()['carry_until'], 'max_date' => date('Y-m-d', strtotime($today . ' +' . self::MAX_DAYS_AHEAD . ' days'))];
    }

    /** Saldo d'une personne pour l'année, demandes en attente déduites dans « po wnioskach ». */
    public function balance(int $employeeId, int $year, ?int $now = null): array
    {
        $b = $this->time->leaveBalances($year, $now)[$employeeId] ?? throw new CustomException('Ta osoba nie jest aktywna.', 'errors', 422);
        $pending = 0;
        $pendingOnDemand = 0;
        foreach ($this->repo->leaveRequests($year . '-01-01', $year . '-12-31', $employeeId) as $r) {
            $pending += (int) $r['days'];
            if ($r['type'] === 'na_zadanie') $pendingOnDemand += (int) $r['days'];
        }
        return $b + ['year' => $year, 'pending' => $pending, 'pending_on_demand' => $pendingOnDemand, 'available' => $b['remaining'] - $pending,
            'on_demand_left' => max(0, TimeService::ON_DEMAND_DAYS - $b['on_demand'] - $pendingOnDemand)];
    }

    /** Wniosek z telefonu. */
    public function create(int $employeeId, array $in, ?int $deviceId, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $type = (string) ($in['type'] ?? 'urlop');
        if (!in_array($type, ['urlop', 'na_zadanie'], true)) throw new CustomException('Rodzaj : urlop wypoczynkowy albo na żądanie.', 'errors', 422);
        $from = self::date($in['from'] ?? null, 'od');
        $to = self::date($in['to'] ?? $from, 'do');
        if ($to < $from) throw new CustomException('Data « do » jest przed datą « od ».', 'errors', 422);
        if ($from < $today) throw new CustomException('Wniosek tylko od dziś.', 'errors', 422);
        if ($to > date('Y-m-d', strtotime($today . ' +' . self::MAX_DAYS_AHEAD . ' days'))) throw new CustomException('Za daleko w przyszłość.', 'errors', 422);
        if (substr($from, 0, 4) !== substr($to, 0, 4)) throw new CustomException('Jeden wniosek w jednym roku kalendarzowym — złóż dwa wnioski (do 31.12 i od 1.01).', 'errors', 422);
        $days = TimeService::workdays($from, $to);
        if ($days === 0) throw new CustomException('W tym okresie nie ma dni roboczych (weekend albo święto).', 'errors', 422);
        foreach ($this->repo->absences($from, $to, $employeeId) as $a) {
            throw new CustomException(sprintf('Masz już w tym okresie : %s %s – %s.', TimeService::ABSENCE_TYPES[$a['type']], date('d.m', strtotime($a['date_from'])), date('d.m', strtotime($a['date_to']))), 'errors', 409);
        }
        foreach ($this->repo->leaveRequests($from, $to, $employeeId) as $r) {
            throw new CustomException(sprintf('Masz już wniosek na %s – %s (do zatwierdzenia).', date('d.m', strtotime($r['date_from'])), date('d.m', strtotime($r['date_to']))), 'errors', 409);
        }
        $b = $this->balance($employeeId, (int) substr($from, 0, 4), $now);
        if ($type === 'na_zadanie' && $days > $b['on_demand_left']) throw new CustomException('Urlop na żądanie : zostało ' . $b['on_demand_left'] . ' z ' . TimeService::ON_DEMAND_DAYS . ' dni w roku.', 'errors', 422);
        if ($days > $b['available']) throw new CustomException(sprintf('Za mało urlopu : wniosek %d dni, do wykorzystania %d.', $days, max(0, $b['available'])), 'errors', 422);
        $note = trim((string) ($in['note'] ?? ''));
        $note = $note === '' ? null : mb_substr($note, 0, 255);
        $id = $this->repo->insertLeaveRequest(['id_employee' => $employeeId, 'type' => $type, 'date_from' => $from, 'date_to' => $to, 'days' => $days, 'note' => $note, 'id_device' => $deviceId]);
        $this->log->record(null, 'create', 'time_leave_request', $id, null, ['employee_id' => $employeeId, 'type' => $type, 'from' => $from, 'to' => $to, 'days' => $days], null);
        $this->notify($id, $employeeId, $type, $from, $to, $days);
        return ['id' => $id, 'days' => $days, 'message' => 'Wniosek wysłany — kierownik produkcji zatwierdzi go w ERP.'];
    }

    /** La personne annule son wniosek tant qu'il n'est pas décidé. */
    public function cancel(int $requestId, int $employeeId): void
    {
        $r = $this->repo->leaveRequest($requestId);
        if (!$r || (int) $r['id_employee'] !== $employeeId) throw new CustomException('Nie znaleziono wniosku.', 'errors', 404);
        if ($r['status'] !== 'pending') throw new CustomException('Wniosek jest już rozpatrzony — anulowanie u kierownika.', 'errors', 409);
        $this->repo->decideLeaveRequest($requestId, 'cancelled', null, null, null);
        $this->log->record(null, 'update', 'time_leave_request', $requestId, ['status' => 'pending'], ['status' => 'cancelled'], null);
    }

    /** Kierownik produkcji : zatwierdza → wpis w kalendarzu (contrôles de TimeService::addAbsence). */
    public function approve(int $requestId, ?int $userId, ?int $now = null): array
    {
        $r = $this->pending($requestId);
        return $this->tx->execute(function () use ($r, $requestId, $userId, $now) {
            $note = trim('Wniosek z telefonu' . ($r['note'] ? ' : ' . $r['note'] : ''));
            $absenceId = $this->time->addAbsence((int) $r['id_employee'], $r['type'], $r['date_from'], $r['date_to'], $note, $userId, $now);
            if (!$this->repo->decideLeaveRequest($requestId, 'approved', null, $absenceId, $userId)) throw new CustomException('Wniosek został już rozpatrzony.', 'errors', 409);
            $this->log->record($userId, 'update', 'time_leave_request', $requestId, ['status' => 'pending'], ['status' => 'approved', 'absence_id' => $absenceId], null);
            return ['absence_id' => $absenceId];
        });
    }

    public function reject(int $requestId, string $reason, ?int $userId): void
    {
        $this->pending($requestId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new CustomException('Podaj powód odrzucenia (pracownik zobaczy go w telefonie).', 'errors', 422);
        if (!$this->repo->decideLeaveRequest($requestId, 'rejected', mb_substr($reason, 0, 255), null, $userId)) throw new CustomException('Wniosek został już rozpatrzony.', 'errors', 409);
        $this->log->record($userId, 'update', 'time_leave_request', $requestId, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason], null);
    }

    private function pending(int $requestId): array
    {
        $r = $this->repo->leaveRequest($requestId) ?? throw new CustomException('Nie znaleziono wniosku.', 'errors', 404);
        if ($r['status'] !== 'pending') throw new CustomException('Wniosek został już rozpatrzony.', 'errors', 409);
        return $r;
    }

    /** Dzwonek kierownika produkcji (bez kierownika : administratorzy). */
    private function notify(int $id, int $employeeId, string $type, string $from, string $to, int $days): void
    {
        $users = $this->hr->recipients(['KIEROWNIK_PRODUKCJI']) ?: $this->hr->recipients(['admin']);
        $who = $this->repo->employee($employeeId)['name'] ?? 'Pracownik';
        $span = date('d.m', strtotime($from)) . ($to !== $from ? ' – ' . date('d.m.Y', strtotime($to)) : '.' . substr($from, 0, 4));
        $monday = date('Y-m-d', strtotime($from . ' -' . ((int) date('N', strtotime($from)) - 1) . ' days'));
        foreach (array_keys($users) as $u) {
            $this->notifications->publishForUser((int) $u, 'leave_request', 'info', 'Nowy wniosek o urlop — ' . $who,
                TimeService::ABSENCE_TYPES[$type] . ' ' . $span . ' (' . $days . ' ' . ($days === 1 ? 'dzień roboczy' : 'dni roboczych') . ') — zatwierdź w Urlopy i dni wolne.',
                '/time/leave?from=' . $monday . '&request=' . $id, ['request_id' => $id, 'employee_id' => $employeeId], 'leave_request:' . $id . ':' . (int) $u);
        }
    }

    private static function date(mixed $v, string $what): string
    {
        $v = (string) $v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) throw new CustomException("Nieprawidłowa data « {$what} ».", 'errors', 422);
        return $v;
    }
}
