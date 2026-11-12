<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Organization\TaskLockRepository;
use App\Services\Notification\AppNotificationService;
use DateTimeImmutable;

/**
 * [280] Zadania obowiązkowe (makieta 46). Une tâche de poste cochée « obowiązkowe » bloque l'ERP de la personne du poste
 * si elle n'est pas faite : le jour du terme à l'heure choisie (block_mode = due ; sans heure : l'heure du terme, sinon la
 * fin de la journée) ou le lendemain (next_day ; sans heure : 09:00), plus la tolérance. Seules les occurrences des
 * 7 derniers jours bloquent (au-delà : « zaległe » dans Mój tydzień). Une tâche reliée à un assistant qui se valide tout
 * seul est vérifiée avant de bloquer. ERP bloqué = écran « Najpierw to zadanie » + l'assistant de la tâche (les autres
 * pages renvoient à l'écran). Jamais l'administrateur. Déblocage pour la journée : supérieur du poste ou administrateur,
 * avec un motif. Chaque tâche a toujours un assistant : le sien, sinon un assistant à étapes (wizard_steps ou étapes par
 * défaut) qui se termine par « Zakończ zadanie ».
 */
final class TaskLockService
{
    private const WINDOW_DAYS = 7;

    public function __construct(private TaskLockRepository $repo, private PositionRepository $positions, private PositionTaskService $tasks,
        private PageAccessService $access, private AppNotificationService $notifications, private HrRepository $hr)
    {
    }

    /**
     * Tâches obligatoires en retard qui bloquent l'ERP du compte.
     * @return list<array{task_id:int, occurrence_id:int, title:string, due:string, block_at:string, link:string, minutes:?int, generic:bool}>
     */
    public function blocking(?int $userId, ?DateTimeImmutable $now = null): array
    {
        if (!$userId || $this->access->isAdmin($userId) || $this->access->isCeo($userId)) return [];
        $tasks = $this->repo->mandatoryTasks($userId);
        if (!$tasks) return [];
        $now ??= new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $from = $today->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $out = [];
        foreach ($tasks as $t) {
            if ($this->repo->unblocked($userId, (int) $t['id'], $today->format('Y-m-d'))) continue;
            $period = $this->tasks->taskPeriod($t, $today);
            if ($period) $this->positions->ensureOccurrence((int) $t['id'], $period['key'], $period['due']);
            foreach ($this->repo->openOccurrences((int) $t['id'], $from, $today->format('Y-m-d')) as $o) {
                $at = self::blockAt($t, (string) $o['due_date']);
                if ($now < $at) continue;
                $o = $this->tasks->closeIfWizardDone($o, $t, $today);
                if ($o['status'] !== 'open') continue;
                $out[] = ['task_id' => (int) $t['id'], 'occurrence_id' => (int) $o['id'], 'title' => $t['title_pl'], 'due' => (string) $o['due_date'],
                    'due_time' => !empty($t['due_time']) ? substr((string) $t['due_time'], 0, 5) : null, 'block_at' => $at->format('Y-m-d H:i'),
                    'link' => $this->wizardLink($t, (int) $o['id']), 'minutes' => isset($t['minutes']) ? (int) $t['minutes'] : null, 'generic' => self::isGeneric($t)];
                break;
            }
        }
        return $out;
    }

    /** Moment où une occurrence non faite bloque l'ERP. */
    public static function blockAt(array $task, string $dueDate): DateTimeImmutable
    {
        $next = ($task['block_mode'] ?? 'due') === 'next_day';
        $time = !empty($task['block_time']) ? substr((string) $task['block_time'], 0, 5)
            : ($next ? '09:00' : (!empty($task['due_time']) ? substr((string) $task['due_time'], 0, 5) : '23:59'));
        $at = new DateTimeImmutable($dueDate . ' ' . $time . ':00');
        if ($next) $at = $at->modify('+1 day');
        return $at->modify('+' . max(0, min(120, (int) ($task['block_tolerance'] ?? 0))) . ' minutes');
    }

    /** « od 11:00 tego dnia », « dzień po terminie, od 09:00 » (+ tolerancja). */
    public static function blockLabel(array $task): string
    {
        $next = ($task['block_mode'] ?? 'due') === 'next_day';
        $time = !empty($task['block_time']) ? substr((string) $task['block_time'], 0, 5) : null;
        $tol = (int) ($task['block_tolerance'] ?? 0);
        $label = $next ? 'dzień po terminie, od ' . ($time ?? '09:00')
            : ($time ? 'od ' . $time . ' w dniu terminu' : (!empty($task['due_time']) ? 'od ' . substr((string) $task['due_time'], 0, 5) . ' (termin)' : 'po dniu terminu'));
        return $label . ($tol ? ' + ' . $tol . ' min' : '');
    }

    /** Tâche sans assistant propre (ni assistant qui se valide seul, ni protocole) → assistant à étapes. */
    public static function isGeneric(array $task): bool
    {
        return TaskWizards::info($task['auto_code'] ?? null) === null && empty($task['form_code']);
    }

    /** Adresse de l'assistant de la tâche (toujours un assistant). */
    public function wizardLink(array $task, ?int $occurrenceId = null): string
    {
        $w = TaskWizards::info($task['auto_code'] ?? null);
        if ($w) return $w['link'];
        if (!empty($task['form_code']) && $occurrenceId) return '/my-tasks/' . $occurrenceId . '/form';
        return '/tasks/wizard/' . (int) $task['id'];
    }

    /** Étapes de l'assistant générique : celles de l'administrateur, sinon « co zrobić » + autres pages de la tâche. */
    public static function steps(array $task): array
    {
        $steps = [];
        foreach (json_decode((string) ($task['wizard_steps'] ?? ''), true) ?: [] as $s) {
            if (!is_array($s) || trim((string) ($s['title'] ?? '')) === '') continue;
            $steps[] = ['title' => (string) $s['title'], 'hint' => (string) ($s['hint'] ?? ''), 'link' => self::safeLink($s['link'] ?? null)];
        }
        if ($steps) return $steps;
        $steps[] = ['title' => 'Co zrobić', 'hint' => $task['title_pl'] . (!empty($task['minutes']) ? ' — ok. ' . (int) $task['minutes'] . ' min.' : ''), 'link' => self::safeLink($task['link_path'] ?? null)];
        foreach (PositionTaskService::extraLinks($task['extra_links'] ?? null) as $l) {
            $steps[] = ['title' => PositionTaskService::LINKS[$l][1] ?? 'Też w tym zadaniu', 'hint' => 'Druga część zadania : sprawdź i uzupełnij tę stronę.', 'link' => self::safeLink($l)];
        }
        return $steps;
    }

    /** Walidacja kroków z panelu administratora (najwyżej 8, tytuł ≤ 80, wskazówka ≤ 300, link = ścieżka ERP). */
    public static function cleanSteps(mixed $steps): ?string
    {
        if (!is_array($steps) || !$steps) return null;
        $out = [];
        foreach (array_slice(array_values($steps), 0, 8) as $i => $s) {
            if (!is_array($s)) continue;
            $title = trim((string) ($s['title'] ?? ''));
            if ($title === '') continue;
            $link = trim((string) ($s['link'] ?? ''));
            if ($link !== '' && self::safeLink($link) === null) throw new CustomException('Krok ' . ($i + 1) . ' : link musi być ścieżką ERP, np. /shipments.', 'errors', 422);
            $out[] = ['title' => mb_substr($title, 0, 80), 'hint' => mb_substr(trim((string) ($s['hint'] ?? '')), 0, 300), 'link' => $link === '' ? null : $link];
        }
        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
    }

    private static function safeLink(mixed $link): ?string
    {
        $link = trim((string) $link);
        return $link !== '' && preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&.%\#]{0,254}$#', $link) ? $link : null;
    }

    // --- Assistant générique ------------------------------------------------------------------------------

    /** Page de l'assistant d'une tâche (personne du poste, remplaçant ou administrateur). */
    public function wizard(int $taskId, ?int $userId, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $task = $this->allowed($taskId, $userId);
        $occ = $this->targetOccurrence($task, $today);
        return ['task' => ['id' => (int) $task['id'], 'title' => $task['title_pl'], 'minutes' => isset($task['minutes']) ? (int) $task['minutes'] : null,
                'mandatory' => (bool) (int) ($task['mandatory'] ?? 0), 'block' => (int) ($task['mandatory'] ?? 0) ? self::blockLabel($task) : null, 'generic' => self::isGeneric($task)],
            'steps' => self::steps($task), 'occurrence' => $occ ? ['id' => (int) $occ['id'], 'due' => $occ['due_date'], 'status' => $occ['status']] : null,
            'own_wizard' => self::isGeneric($task) ? null : $this->wizardLink($task, $occ ? (int) $occ['id'] : null)];
    }

    /** « Zakończ zadanie » : l'occurrence ouverte (la plus ancienne des 7 jours, sinon celle de la période) est faite. */
    public function finish(int $taskId, array $in, ?int $userId, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $task = $this->allowed($taskId, $userId);
        if (!self::isGeneric($task)) throw new CustomException('To zadanie ma własnego asystenta — zalicza się samo po jego zakończeniu.', 'errors', 422);
        $occ = $this->targetOccurrence($task, $today) ?? throw new CustomException('Brak zadania do zrobienia w tym okresie.', 'errors', 404);
        if ($occ['status'] !== 'open') return ['done' => true, 'message' => 'Zadanie było już zrobione.'];
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 400) throw new CustomException('Uwaga : najwyżej 400 znaków.', 'errors', 422);
        $this->positions->setOccurrence((int) $occ['id'], 'done', $userId, 'Asystent' . ($note !== '' ? ' : ' . $note : ''));
        return ['done' => true, 'message' => 'Zadanie zakończone.' . ((int) ($task['mandatory'] ?? 0) ? ' ERP jest odblokowany.' : '')];
    }

    private function targetOccurrence(array $task, DateTimeImmutable $today): ?array
    {
        $period = $this->tasks->taskPeriod($task, $today);
        if ($period) $this->positions->ensureOccurrence((int) $task['id'], $period['key'], $period['due']);
        $open = $this->repo->openOccurrences((int) $task['id'], $today->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d'), $today->format('Y-m-d'));
        if ($open) return $open[0];
        return $period ? $this->tasks->occurrenceFor((int) $task['id'], $period['key']) : null;
    }

    private function allowed(int $taskId, ?int $userId): array
    {
        $task = $this->repo->task($taskId);
        if (!$task || !(int) $task['active']) throw new CustomException('Nie ma takiego zadania.', 'errors', 404);
        if (!$userId) throw new CustomException('Zaloguj się.', 'errors', 403);
        $own = $this->repo->userHasPosition($userId, (int) $task['id_position']) || ($task['id_backup_position'] && $this->repo->userHasPosition($userId, (int) $task['id_backup_position']));
        if (!$own && !$this->access->isAdmin($userId)) throw new CustomException('To zadanie należy do innego stanowiska.', 'errors', 403);
        return $task;
    }

    // --- Déblocage ------------------------------------------------------------------------------------------

    /** Demande de déblocage : cloche du supérieur du poste et des administrateurs (lien vers la décision). */
    public function requestUnblock(int $taskId, array $in, ?int $userId): array
    {
        $task = $this->allowed($taskId, $userId);
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new CustomException('Podaj powód (3–255 znaków).', 'errors', 422);
        $to = array_unique(array_merge($this->repo->usersOfPosition(isset($task['id_reports_to']) ? (int) $task['id_reports_to'] : null), array_column($this->hr->admins(), 'id'), $this->access->ceoUsers()));
        $who = $this->repo->userName((int) $userId) ?? 'Osoba';
        foreach ($to as $u) {
            if ((int) $u === (int) $userId) continue;
            // [281] Klucz z odbiorcą : każdy przełożony / administrator / CEO dostaje własne powiadomienie (klucz jest unikalny).
            $this->notifications->publishForUser((int) $u, 'task_unblock', 'warning', 'Prośba o odblokowanie ERP — ' . $who, $task['title_pl'] . ' : ' . $reason,
                '/tasks/unblock/' . (int) $userId . '/' . $taskId, ['task_id' => $taskId, 'user_id' => (int) $userId], 'task_unblock:' . $userId . ':' . $taskId . ':' . date('Y-m-d') . ':' . (int) $u);
        }
        return ['message' => 'Prośba wysłana — przełożony albo administrator dostanie powiadomienie.'];
    }

    public function canUnblock(?int $actorId, array $task): bool
    {
        if (!$actorId) return false;
        // [281] Profil CEO : decyzja « Odblokuj na dziś » jak administrator.
        if ($this->access->isAdmin($actorId) || $this->access->isCeo($actorId)) return true;
        return in_array($actorId, $this->repo->usersOfPosition(isset($task['id_reports_to']) ? (int) $task['id_reports_to'] : null), true);
    }

    public function unblockView(int $userId, int $taskId, ?int $actorId): array
    {
        $task = $this->repo->task($taskId) ?? throw new CustomException('Nie ma takiego zadania.', 'errors', 404);
        if (!$this->canUnblock($actorId, $task)) throw new CustomException('Odblokować może przełożony stanowiska albo administrator.', 'errors', 403);
        return ['user' => ['id' => $userId, 'name' => $this->repo->userName($userId)], 'task' => ['id' => $taskId, 'title' => $task['title_pl'], 'block' => self::blockLabel($task)],
            'blocked' => array_values(array_filter($this->blocking($userId), fn($b) => $b['task_id'] === $taskId)) !== [], 'today' => date('Y-m-d')];
    }

    public function unblock(int $userId, int $taskId, array $in, ?int $actorId, ?DateTimeImmutable $today = null): array
    {
        $task = $this->repo->task($taskId) ?? throw new CustomException('Nie ma takiego zadania.', 'errors', 404);
        if (!$this->canUnblock($actorId, $task)) throw new CustomException('Odblokować może przełożony stanowiska albo administrator.', 'errors', 403);
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) throw new CustomException('Podaj powód odblokowania (3–255 znaków).', 'errors', 422);
        $day = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $this->repo->unblock($userId, $taskId, $day, $reason, $actorId);
        $this->notifications->publishForUser($userId, 'task_unblock', 'info', 'ERP odblokowany na dziś', $task['title_pl'] . ' : ' . $reason, '/today', ['task_id' => $taskId],
            'task_unblocked:' . $userId . ':' . $taskId . ':' . $day);
        return ['message' => 'Odblokowane na dziś.'];
    }
}
