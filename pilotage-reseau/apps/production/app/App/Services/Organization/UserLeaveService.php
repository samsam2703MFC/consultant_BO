<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Organization\UserLeaveRepository;
use App\Repositories\Workshop\AuditLogRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Notification\AppNotificationService;
use App\Services\Workshop\Time\TimeService;

/**
 * [289] « Mój urlop » (makieta 50) : urlop osoby z kontem ERP (Administrator / IT, CEO, Finanse, Logistyka, Produkcja, Jakość…)
 * z zastępcą — kto przejmuje obowiązki.
 *
 * Urlop zatwierdza CEO (albo administrator) ; dzwonek dla CEO, potem dla osoby i zastępcy. Przekazanie zadań nie wymaga
 * zatwierdzenia (décision du 12/11/2026) : w dni zatwierdzonego urlopu zadania stanowiska osoby trafiają do zastępcy
 * (Dzisiaj i zadania z oznaczeniem « za … », zadania obowiązkowe blokują zastępcę, nie osobę na urlopie) ; zastępcę może
 * zmienić osoba albo CEO / administrator w każdej chwili. Saldo : time.leave_days (26) minus urlopy roku (wnioski + zatwierdzone).
 */
final class UserLeaveService
{
    public const TYPES = ['urlop' => 'Urlop wypoczynkowy', 'na_zadanie' => 'Urlop na żądanie', 'inna' => 'Inna nieobecność'];
    public const STATUS = ['pending' => 'do zatwierdzenia', 'approved' => 'zatwierdzony', 'rejected' => 'odrzucony', 'cancelled' => 'anulowany'];
    private const MAX_AHEAD_DAYS = 400;

    public function __construct(
        private UserLeaveRepository $repo,
        private PageAccessService $access,
        private AppNotificationService $notifications,
        private ParamRepository $params,
        private AuditLogRepository $log,
    ) {
    }

    /** Page « Mój urlop » : solde, remplaçants possibles (occupés grisés), mes congés ; CEO / admin : demandes et tableau. */
    public function page(int $userId, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $year = (int) date('Y', $now);
        $until = date('Y-m-d', strtotime($today . ' +' . self::MAX_AHEAD_DAYS . ' days'));
        $busy = [];
        foreach ($this->repo->between($today, $until) as $l) $busy[(int) $l['id_user']][] = ['from' => $l['date_from'], 'to' => $l['date_to']];
        $candidates = [];
        foreach ($this->repo->candidates() as $c) {
            if ((int) $c['id'] === $userId) continue;
            $label = $c['position_name'] ?: ($this->access->isCeo((int) $c['id']) ? 'CEO' : 'Administrator / IT');
            $candidates[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'position' => $label, 'busy' => $busy[(int) $c['id']] ?? []];
        }
        usort($candidates, fn($a, $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);
        $decider = $this->canDecide($userId);
        return [
            'today' => $today, 'year' => $year, 'types' => self::TYPES, 'balance' => $this->balance($userId, $year),
            'next_balance' => (int) date('n', $now) >= 10 ? $this->balance($userId, $year + 1) : null,
            'holidays' => array_keys(Holidays::between($year . '-01-01', ($year + 1) . '-12-31', ['PL'])),
            'candidates' => $candidates, 'mine' => array_map([$this, 'row'], $this->repo->forUser($userId, $year . '-01-01', $until, ['pending', 'approved', 'rejected', 'cancelled'])),
            'can_decide' => $decider,
            'pending' => $decider ? array_map([$this, 'row'], $this->repo->pending()) : [],
            'board' => $decider ? array_map([$this, 'row'], $this->repo->between($today, $until, ['approved'])) : [],
            'delegations' => array_map([$this, 'row'], $this->repo->delegations($userId, $today)),
        ];
    }

    /** Solde de l'année : droit (time.leave_days), pris ou demandé (urlop + na żądanie, jours ouvrés), reste. */
    public function balance(int $userId, int $year): array
    {
        $right = max(0, (int) $this->params->get('time.leave_days', '26'));
        $used = 0;
        foreach ($this->repo->yearLeaves($userId, $year) as $l) {
            $used += TimeService::workdays(max($l['date_from'], $year . '-01-01'), min($l['date_to'], $year . '-12-31'));
        }
        return ['year' => $year, 'right' => $right, 'used' => $used, 'remaining' => $right - $used];
    }

    public function create(int $userId, array $in, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $type = (string) ($in['type'] ?? 'urlop');
        if (!isset(self::TYPES[$type])) throw new CustomException('Wybierz rodzaj urlopu.', 'errors', 422);
        $from = self::date($in['from'] ?? null, 'od');
        $to = self::date($in['to'] ?? $from, 'do');
        if ($to < $from) throw new CustomException('Data « do » jest przed datą « od ».', 'errors', 422);
        if ($from < $today) throw new CustomException('Urlop tylko od dziś.', 'errors', 422);
        if ($to > date('Y-m-d', strtotime($today . ' +' . self::MAX_AHEAD_DAYS . ' days'))) throw new CustomException('Za daleko w przyszłość.', 'errors', 422);
        if (substr($from, 0, 4) !== substr($to, 0, 4)) throw new CustomException('Jeden urlop w jednym roku kalendarzowym — dodaj dwa (do 31.12 i od 1.01).', 'errors', 422);
        $days = TimeService::workdays($from, $to);
        if ($days === 0) throw new CustomException('W tych dniach nie ma dni roboczych.', 'errors', 422);
        foreach ($this->repo->forUser($userId, $from, $to) as $l) {
            throw new CustomException(sprintf('Masz już urlop %s – %s (%s).', date('d.m', strtotime($l['date_from'])), date('d.m', strtotime($l['date_to'])), self::STATUS[$l['status']]), 'errors', 409);
        }
        if ($type !== 'inna') {
            $b = $this->balance($userId, (int) substr($from, 0, 4));
            if ($days > $b['remaining']) throw new CustomException(sprintf('Za mało urlopu : %d dni, zostało %d.', $days, max(0, $b['remaining'])), 'errors', 422);
        }
        $sub = $this->substitute($userId, $in['substitute_id'] ?? null, $from, $to);
        $note = trim((string) ($in['note'] ?? ''));
        $id = $this->repo->insert(['id_user' => $userId, 'type' => $type, 'date_from' => $from, 'date_to' => $to, 'days' => $days,
            'note' => $note === '' ? null : mb_substr($note, 0, 255), 'id_substitute' => $sub]);
        $this->log->record($userId, 'create', 'user_leave', $id, null, ['from' => $from, 'to' => $to, 'days' => $days, 'substitute' => $sub], null);
        $l = $this->repo->find($id);
        foreach ($this->deciders() as $u) {
            if ($u === $userId) continue;
            $this->notifications->publishForUser($u, 'user_leave', 'info', 'Urlop do zatwierdzenia — ' . $l['name'],
                self::span($l) . ' (' . $days . ' ' . ($days === 1 ? 'dzień roboczy' : 'dni roboczych') . ')' . ($l['substitute_name'] ? ' · zastępca : ' . $l['substitute_name'] : ' · bez zastępcy'),
                '/my-leave#wnioski', ['leave_id' => $id], 'user_leave:' . $id . ':' . $u);
        }
        return ['id' => $id, 'days' => $days, 'message' => 'Urlop wysłany do zatwierdzenia (CEO). Zadania przejmie zastępca w dni urlopu — bez osobnego zatwierdzenia.'];
    }

    /** Remplaçant : la personne elle-même ou CEO / admin, à tout moment, sans validation. */
    public function changeSubstitute(int $leaveId, mixed $substituteId, int $actorId): array
    {
        $l = $this->repo->find($leaveId) ?? throw new CustomException('Nie znaleziono urlopu.', 'errors', 404);
        if ((int) $l['id_user'] !== $actorId && !$this->canDecide($actorId)) throw new CustomException('Zastępcę zmienia osoba na urlopie albo CEO / administrator.', 'errors', 403);
        if (!in_array($l['status'], ['pending', 'approved'], true)) throw new CustomException('Ten urlop jest już ' . self::STATUS[$l['status']] . '.', 'errors', 409);
        $sub = $this->substitute((int) $l['id_user'], $substituteId, $l['date_from'], $l['date_to'], $leaveId);
        $this->repo->setSubstitute($leaveId, $sub);
        $this->log->record($actorId, 'update', 'user_leave', $leaveId, ['substitute' => $l['id_substitute']], ['substitute' => $sub], null);
        $l = $this->repo->find($leaveId);
        if ($sub && $l['status'] === 'approved') $this->notifySubstitute($l);
        return $this->row($l);
    }

    public function approve(int $leaveId, int $actorId): array
    {
        if (!$this->canDecide($actorId)) throw new CustomException('Urlop zatwierdza CEO albo administrator.', 'errors', 403);
        $l = $this->repo->find($leaveId) ?? throw new CustomException('Nie znaleziono urlopu.', 'errors', 404);
        if ((int) $l['id_user'] === $actorId && !$this->access->isAdmin($actorId)) throw new CustomException('Własny urlop zatwierdza inna osoba (administrator).', 'errors', 403);
        if (!$this->repo->decide($leaveId, 'approved', ['pending'], null, $actorId)) throw new CustomException('Urlop został już rozpatrzony.', 'errors', 409);
        $this->log->record($actorId, 'update', 'user_leave', $leaveId, ['status' => 'pending'], ['status' => 'approved'], null);
        $l = $this->repo->find($leaveId);
        $this->notifications->publishForUser((int) $l['id_user'], 'user_leave', 'info', 'Urlop zatwierdzony', self::span($l)
            . ($l['substitute_name'] ? ' · zadania przejmie ' . $l['substitute_name'] : ' · bez zastępcy — zadania czekają'), '/my-leave', ['leave_id' => $leaveId], 'user_leave_ok:' . $leaveId);
        if ($l['id_substitute']) $this->notifySubstitute($l);
        return $this->row($l);
    }

    public function reject(int $leaveId, string $reason, int $actorId): array
    {
        if (!$this->canDecide($actorId)) throw new CustomException('Urlop odrzuca CEO albo administrator.', 'errors', 403);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new CustomException('Podaj powód odrzucenia.', 'errors', 422);
        if (!$this->repo->decide($leaveId, 'rejected', ['pending'], mb_substr($reason, 0, 255), $actorId)) throw new CustomException('Urlop został już rozpatrzony.', 'errors', 409);
        $l = $this->repo->find($leaveId);
        $this->log->record($actorId, 'update', 'user_leave', $leaveId, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason], null);
        $this->notifications->publishForUser((int) $l['id_user'], 'user_leave', 'warning', 'Urlop odrzucony', self::span($l) . ' — ' . $reason, '/my-leave', ['leave_id' => $leaveId], 'user_leave_no:' . $leaveId);
        return $this->row($l);
    }

    /** La personne annule (demande, ou congé validé pas encore commencé) ; CEO / admin : tout congé pending ou approved. */
    public function cancel(int $leaveId, int $actorId, ?int $now = null): void
    {
        $l = $this->repo->find($leaveId) ?? throw new CustomException('Nie znaleziono urlopu.', 'errors', 404);
        $own = (int) $l['id_user'] === $actorId;
        if (!$own && !$this->canDecide($actorId)) throw new CustomException('Nie możesz anulować tego urlopu.', 'errors', 403);
        if ($own && !$this->canDecide($actorId) && $l['status'] === 'approved' && $l['date_from'] <= date('Y-m-d', $now ?? time())) {
            throw new CustomException('Urlop już trwa — skrócenie przez CEO / administratora.', 'errors', 409);
        }
        if (!$this->repo->decide($leaveId, 'cancelled', ['pending', 'approved'], null, $actorId)) throw new CustomException('Ten urlop jest już rozpatrzony.', 'errors', 409);
        $this->log->record($actorId, 'update', 'user_leave', $leaveId, ['status' => $l['status']], ['status' => 'cancelled'], null);
    }

    /** Congé validé de la personne ce jour-là (bandeau « jesteś na urlopie », plus de blocage). */
    public function onLeave(int $userId, ?string $day = null): ?array
    {
        $l = $this->repo->onLeave($userId, $day ?? date('Y-m-d'));
        return $l ? $this->row($l) : null;
    }

    public function canDecide(?int $userId): bool
    {
        return $userId !== null && ($this->access->isCeo($userId) || $this->access->isAdmin($userId));
    }

    /** CEO d'abord ; sans compte CEO : administrateurs. @return list<int> */
    private function deciders(): array
    {
        $ceo = $this->access->ceoUsers();
        if ($ceo) return $ceo;
        return array_map(fn($a) => (int) $a['id'], array_filter($this->repo->candidates(), fn($c) => $this->access->isAdmin((int) $c['id'])));
    }

    private function substitute(int $userId, mixed $id, string $from, string $to, ?int $leaveId = null): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) return null;
        $id = (int) $id;
        if ($id === $userId) throw new CustomException('Zastępcą nie może być ta sama osoba.', 'errors', 422);
        if (!in_array($id, array_map(fn($c) => (int) $c['id'], $this->repo->candidates()), true)) throw new CustomException('Wybierz zastępcę z listy (konto ERP ze stanowiskiem).', 'errors', 422);
        foreach ($this->repo->forUser($id, $from, $to) as $l) {
            if ((int) $l['id'] === $leaveId) continue;
            throw new CustomException(sprintf('Zastępca ma urlop %s – %s — wybierz inną osobę.', date('d.m', strtotime($l['date_from'])), date('d.m', strtotime($l['date_to']))), 'errors', 409);
        }
        return $id;
    }

    private function notifySubstitute(array $l): void
    {
        $this->notifications->publishForUser((int) $l['id_substitute'], 'user_leave', 'info', 'Zastępstwo : ' . ($l['position_name'] ?: $l['name']),
            'Od ' . date('d.m', strtotime($l['date_from'])) . ' do ' . date('d.m.Y', strtotime($l['date_to'])) . ' zadania ' . $l['name'] . ' są w Twoich « Dzisiaj i zadania ».',
            '/today#zadania', ['leave_id' => (int) $l['id']], 'user_leave_sub:' . $l['id'] . ':' . $l['id_substitute']);
    }

    private function row(array $l): array
    {
        return ['id' => (int) $l['id'], 'user_id' => (int) $l['id_user'], 'name' => $l['name'], 'position' => $l['position_name'] ?: null,
            'type' => $l['type'], 'type_label' => self::TYPES[$l['type']], 'from' => $l['date_from'], 'to' => $l['date_to'], 'days' => (int) $l['days'],
            'note' => $l['note'], 'status' => $l['status'], 'status_label' => self::STATUS[$l['status']], 'decision_note' => $l['decision_note'],
            'substitute_id' => $l['id_substitute'] !== null ? (int) $l['id_substitute'] : null, 'substitute' => $l['substitute_name'], 'substitute_position' => $l['substitute_position'],
            'span' => self::span($l), 'created_at' => $l['created_at']];
    }

    private static function span(array $l): string
    {
        return date('d.m', strtotime($l['date_from'])) . ($l['date_to'] !== $l['date_from'] ? ' – ' . date('d.m.Y', strtotime($l['date_to'])) : '.' . substr($l['date_from'], 0, 4));
    }

    private static function date(mixed $v, string $what): string
    {
        $v = (string) $v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) throw new CustomException("Nieprawidłowa data « {$what} ».", 'errors', 422);
        return $v;
    }
}
