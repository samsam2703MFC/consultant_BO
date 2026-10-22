<?php
namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;

/**
 * Postes des utilisateurs de l'ERP et leurs tâches récurrentes (quotidiennes, hebdomadaires,
 * mensuelles, trimestrielles). Une tâche donne une occurrence par période, créée quand quelqu'un
 * ouvre sa liste ; une occurrence non faite reste visible (en retard) jusqu'à ce qu'on la traite.
 */
final class PositionTaskService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly'];
    public const FREQUENCY_LABELS = ['daily' => 'Codziennie', 'weekly' => 'Co tydzień', 'monthly' => 'Co miesiąc', 'quarterly' => 'Co kwartał'];
    public const STATUSES = ['open', 'done', 'skipped'];

    public function __construct(private PositionRepository $positions, private ParamRepository $params)
    {
    }

    /**
     * Période d'une fréquence à une date : clé et échéance. null pour une tâche quotidienne un
     * jour non travaillé (param tasks.workdays, 1 = lundi).
     * @return array{key:string,due:string}|null
     */
    public function period(string $frequency, DateTimeImmutable $day): ?array
    {
        return match ($frequency) {
            'daily' => in_array((int) $day->format('N'), $this->workdays(), true) ? ['key' => $day->format('Y-m-d'), 'due' => $day->format('Y-m-d')] : null,
            'weekly' => ['key' => $day->format('o-\WW'), 'due' => $day->setISODate((int) $day->format('o'), (int) $day->format('W'), 5)->format('Y-m-d')],
            'monthly' => ['key' => $day->format('Y-m'), 'due' => $day->format('Y-m-t')],
            'quarterly' => (function () use ($day) {
                $q = intdiv((int) $day->format('n') - 1, 3) + 1;
                $end = new DateTimeImmutable(sprintf('%s-%02d-01', $day->format('Y'), $q * 3));
                return ['key' => $day->format('Y') . '-Q' . $q, 'due' => $end->format('Y-m-t')];
            })(),
            default => throw new \InvalidArgumentException('Nieznana częstotliwość: ' . $frequency),
        };
    }

    /** Liste « Moje zadania » d'un utilisateur : tâches de son poste pour la période en cours, plus les retards. */
    public function myTasks(int $userId, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $user = $this->positions->user($userId);
        if (!$user || !$user['id_position']) {
            return ['user' => $user, 'groups' => [], 'overdue' => [], 'counts' => ['open' => 0, 'done' => 0, 'overdue' => 0]];
        }
        return ['user' => $user] + $this->board($this->positions->tasks((int) $user['id_position'], true), $today);
    }

    /** Tâches d'un poste rangées par fréquence, avec l'état de la période en cours et les retards. */
    public function board(array $tasks, DateTimeImmutable $today): array
    {
        $byId = [];
        $keys = [];
        foreach ($tasks as $task) {
            $period = $this->period($task['frequency'], $today);
            $byId[(int) $task['id']] = $task + ['period' => $period];
            if ($period) {
                $this->positions->ensureOccurrence((int) $task['id'], $period['key'], $period['due']);
                $keys[$period['key']] = true;
            }
        }
        $current = [];
        $overdue = [];
        foreach ($this->positions->occurrences(array_keys($byId), array_keys($keys), $today->format('Y-m-d')) as $o) {
            $task = $byId[(int) $o['id_task']];
            if ($task['period'] && $o['period_key'] === $task['period']['key']) {
                $current[(int) $o['id_task']] = $o;
            } elseif ($o['status'] === 'open') {
                $overdue[] = $o + ['task' => $task];
            }
        }
        $groups = [];
        $counts = ['open' => 0, 'done' => 0, 'overdue' => count($overdue)];
        foreach ($byId as $id => $task) {
            $occurrence = $current[$id] ?? null;
            if ($occurrence) $counts[$occurrence['status'] === 'open' ? 'open' : 'done']++;
            $groups[$task['frequency']]['label'] ??= self::FREQUENCY_LABELS[$task['frequency']];
            $groups[$task['frequency']]['items'][] = ['task' => $task, 'occurrence' => $occurrence];
        }
        uksort($groups, fn($a, $b) => array_search($a, self::FREQUENCIES, true) <=> array_search($b, self::FREQUENCIES, true));
        return ['groups' => $groups, 'overdue' => $overdue, 'counts' => $counts];
    }

    public function setStatus(int $occurrenceId, array $input, ?int $userId): array
    {
        $occurrence = $this->positions->occurrence($occurrenceId) ?? throw new CustomException('Nie znaleziono zadania.', 'errors', 404);
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) throw new CustomException('Nieprawidłowy status zadania.', 'errors', 422);
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 500) throw new CustomException('Notatka może mieć najwyżej 500 znaków.', 'errors', 422);
        if ($status === 'skipped' && $note === '') throw new CustomException('Podaj powód pominięcia zadania.', 'errors', 422);
        $this->positions->setOccurrence($occurrenceId, $status, $userId, $note === '' ? null : $note);
        return $this->positions->occurrence($occurrenceId);
    }

    public function positions(): array
    {
        $positions = $this->positions->positions();
        $tasks = [];
        foreach ($this->positions->tasks() as $task) $tasks[(int) $task['id_position']][] = $task;
        $users = [];
        foreach ($this->positions->users() as $user) if ($user['id_position']) $users[(int) $user['id_position']][] = $user;
        return array_map(fn($p) => $p + ['tasks' => $tasks[(int) $p['id']] ?? [], 'users' => $users[(int) $p['id']] ?? []], $positions);
    }

    public function users(): array
    {
        return $this->positions->users();
    }

    /** Fiche d'un utilisateur : poste, tâches de la période, retards, historique. */
    public function userSheet(int $userId, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $user = $this->positions->user($userId) ?? throw new CustomException('Nie znaleziono użytkownika.', 'errors', 404);
        $board = $user['id_position'] ? $this->board($this->positions->tasks((int) $user['id_position'], true), $today) : ['groups' => [], 'overdue' => [], 'counts' => ['open' => 0, 'done' => 0, 'overdue' => 0]];
        return ['user' => $user, 'history' => $user['id_position'] ? $this->positions->history((int) $user['id_position']) : []] + $board;
    }

    public function savePosition(array $input, ?int $id): int
    {
        $current = $id === null ? null : ($this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404));
        $v = (new Validator($input, $current !== null))->only(['code', 'name_pl', 'name_fr', 'description', 'sort_order', 'active'])
            ->code('code', $current === null)
            ->string('name_pl', 100, $current === null)
            ->string('name_fr', 100, $current === null)
            ->string('description', 500, false, true)
            ->integer('sort_order', false, 0, 10000)
            ->boolean('active');
        $v->throwIfInvalid();
        if ($v->provided('code') && $this->positions->codeTaken($v->value('code'), $id)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        if ($current === null) return $this->positions->insert($v->clean());
        $this->positions->update($id, $v->clean());
        return $id;
    }

    public function saveTask(array $input, ?int $id, ?int $positionId): int
    {
        $current = $id === null ? null : ($this->positions->task($id) ?? throw new CustomException('Nie znaleziono zadania.', 'errors', 404));
        if ($current === null && ($positionId === null || !$this->positions->exists($positionId))) {
            throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        }
        $v = (new Validator($input, $current !== null))->only(['title_pl', 'title_fr', 'frequency', 'link_path', 'sort_order', 'active'])
            ->string('title_pl', 200, $current === null)
            ->string('title_fr', 200, false, true)
            ->choice('frequency', self::FREQUENCIES, $current === null)
            ->string('link_path', 255, false, true, '/^\/[A-Za-z0-9\/_\-?=&.%]*$/', 'INVALID_URL')
            ->integer('sort_order', false, 0, 10000)
            ->boolean('active');
        $v->throwIfInvalid();
        $data = $v->clean();
        if ($current === null) {
            $data['id_position'] = $positionId;
            $data['title_fr'] = ($data['title_fr'] ?? '') !== '' ? $data['title_fr'] : $data['title_pl'];
            return $this->positions->insertTask($data);
        }
        if (array_key_exists('title_fr', $data) && ($data['title_fr'] ?? '') === '') $data['title_fr'] = $data['title_pl'] ?? $current['title_pl'];
        $this->positions->updateTask($id, $data);
        return $id;
    }

    public function assign(int $userId, array $input): void
    {
        if (!$this->positions->userExists($userId)) throw new CustomException('Nie znaleziono użytkownika.', 'errors', 404);
        $positionId = ($input['position_id'] ?? '') === '' || $input['position_id'] === null ? null : (int) $input['position_id'];
        if ($positionId !== null && !$this->positions->exists($positionId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $since = trim((string) ($input['since'] ?? ''));
        if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $this->positions->assign($userId, $positionId, $since === '' ? date('Y-m-d') : $since);
    }

    /** Role modułu produkcji do wyboru i rola użytkownika (bez grupy). */
    public function moduleRoles(int $userId): array
    {
        return ['roles' => $this->positions->moduleRoles(), 'current' => $this->positions->userModuleRoles($userId)[0] ?? null];
    }

    public function canSetModuleRoles(?int $actorId): bool
    {
        return $actorId !== null && in_array('prod_admin', $this->positions->userModuleRoles($actorId), true);
    }

    /**
     * Rola w module produkcji (np. « Kierownik produkcji » = prod_manager) — tylko administrator produkcji ; nie
     * można odebrać sobie roli administratora (bez blokady dostępu).
     */
    public function setModuleRole(int $userId, array $input, ?int $actorId): void
    {
        if (!$this->canSetModuleRoles($actorId)) throw new CustomException('Rolę w module produkcji nadaje administrator produkcji.', 'errors', 403);
        if (!$this->positions->userExists($userId)) throw new CustomException('Nie znaleziono użytkownika.', 'errors', 404);
        $role = ($input['role_code'] ?? '') === '' ? null : (string) $input['role_code'];
        if ($role !== null && !in_array($role, array_column($this->positions->moduleRoles(), 'code'), true)) throw new CustomException('Nieznana rola.', 'errors', 422);
        if ($userId === $actorId && $role !== 'prod_admin') throw new CustomException('Nie możesz odebrać sobie roli administratora produkcji.', 'errors', 422);
        $this->positions->setModuleRole($userId, $role);
    }

    /** @return list<int> */
    private function workdays(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('tasks.workdays', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7));
        return $days ?: [1, 2, 3, 4, 5];
    }
}
