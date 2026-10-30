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
 * mensuelles, trimestrielles), rangées par catégorie (prix, marges, téléversements, production, réunions…).
 * Une tâche donne une occurrence par période, créée quand quelqu'un ouvre sa liste ; une occurrence non
 * faite reste visible (en retard) jusqu'à ce qu'on la traite. Une tâche avec formulaire (compte rendu de
 * réunion) est faite quand son formulaire est terminé.
 */
final class PositionTaskService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly'];
    public const FREQUENCY_LABELS = ['daily' => 'Codziennie', 'weekly' => 'Co tydzień', 'monthly' => 'Co miesiąc', 'quarterly' => 'Co kwartał'];
    public const STATUSES = ['open', 'done', 'skipped'];
    public const WEEKDAYS = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'];
    public const DAY_SHORT = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    /** Czas pracy tygodnia, do którego porównuje się zadania stałe (min). */
    public const WEEK_MINUTES = 2400;
    /** Zadanie zajmuje najwyżej tyle minut (jedno wykonanie). */
    public const MAX_MINUTES = 480;

    /** Strony ERP do wyboru w planie tygodnia : ścieżka => [etykieta listy, przycisk na karcie zadania]. Inny adres zostaje. */
    public const LINKS = [
        '/workshop/supply#tab=invoices&inv=wizard' => ['Kreator faktur (zatwierdzanie pozycji)', 'Kreator faktur'],
        '/workshop/supply#tab=invoices' => ['Faktury zakupowe (wgraj PDF / zdjęcia, KSeF)', 'Faktury'],
        '/finances/price-wizard' => ['Kreator cen — krok 1 : koszty i marże', 'Kreator cen'],
        '/finances/price-wizard#step=2' => ['Kreator cen — krok 2 : nowe ceny', 'Kreator cen'],
        '/finances/price-wizard#step=3' => ['Kreator cen — krok 3 : publikacja', 'Publikacje'],
        '/workshop/buy' => ['Zakupy tygodnia', 'Zakupy tygodnia'],
        '/workshop/buy#tab=suppliers' => ['Zakupy tygodnia › Dostawcy pozycji', 'Dostawcy'],
        '/purchasing/offers' => ['Oferty dostawców', 'Oferty'],
        '/purchasing/tenders' => ['Zapytania ofertowe', 'Zapytania'],
        '/purchasing/nc' => ['Niezgodności (NC)', 'Niezgodności'],
        '/purchasing' => ['Koszty surowców', 'Koszty surowców'],
        '/materials/prices' => ['Ceny surowców', 'Ceny surowców'],
        '/material-suppliers' => ['Dostawcy surowców', 'Dostawcy'],
        '/finances/category-margin' => ['Marże kategorii', 'Marże'],
        '/finances/invoices' => ['Finanse : faktury i koszty', 'Finanse'],
        '/month-close' => ['Zamknięcie miesiąca', 'Zamknięcie'],
        '/client-orders/queue' => ['Do obsłużenia', 'Do obsłużenia'],
        '/client-orders' => ['Zamówienia klientów', 'Zamówienia'],
        '/workshop/team-sheet' => ['Plan zespołów', 'Plan zespołów'],
        '/production/follow-up' => ['Śledzenie produkcji', 'Śledzenie'],
        '/shipping-documents/create' => ['Wysyłka dnia (dokumenty)', 'Wysyłka'],
        '/shipments' => ['Wysyłki', 'Wysyłki'],
        '/communication/messages' => ['Wiadomości', 'Wiadomości'],
        '/communication/automations' => ['E-maile automatyczne', 'E-maile'],
    ];

    /** Zadanie zalicza się samo, gdy kreator jest zakończony w okresie zadania. */
    public const AUTO = [
        'inv_checked' => 'gdy pozycje faktur są sprawdzone (kreator faktur)',
        'costs_recalc' => 'gdy koszty i marże są przeliczone (kreator cen, krok 1)',
        'prices_planned' => 'gdy nowe ceny są zaplanowane (kreator cen, krok 3)',
        'buy_sent' => 'gdy zapytania ofertowe są wysłane (zakupy tygodnia)',
    ];

    /** Link zadania → zaliczenie automatyczne do wyboru w planie (kreator, który zamyka zadanie). */
    public const LINK_AUTO = [
        '/workshop/supply#tab=invoices&inv=wizard' => 'inv_checked',
        '/finances/price-wizard' => 'costs_recalc',
        '/finances/price-wizard#step=2' => 'prices_planned',
        '/workshop/buy' => 'buy_sent',
    ];

    /** Kolor kategorii na kartach tygodnia (pasek z lewej). */
    private const CATEGORY_COLORS = ['CENY' => '#8e1f2f', 'MARZE' => '#2b5f9e', 'UPLOAD' => '#5b3a85', 'JAKOSC' => '#c17a2a', 'PRODUKCJA' => '#1f5a37',
        'LOGISTYKA' => '#3f6b7c', 'ZEBRANIA' => '#6b6b6b', 'ADMIN' => '#6b6b6b'];
    private const FALLBACK_COLORS = ['#1f5a37', '#2b5f9e', '#5b3a85', '#c17a2a', '#3f6b7c', '#8e1f2f'];

    public function __construct(private PositionRepository $positions, private ParamRepository $params, private PageAccessService $access)
    {
    }

    /**
     * Période d'une fréquence à une date : clé et échéance. null pour une tâche quotidienne un
     * jour non travaillé (param tasks.workdays, 1 = lundi) ou hors de ses jours (« 1,4 » = lundi et jeudi).
     * Hebdomadaire : échéance le jour choisi (vendredi par défaut).
     * @return array{key:string,due:string}|null
     */
    public function period(string $frequency, DateTimeImmutable $day, ?int $weekday = null, ?string $weekdays = null): ?array
    {
        $weekday = $weekday !== null && $weekday >= 1 && $weekday <= 7 ? $weekday : 5;
        return match ($frequency) {
            'daily' => in_array((int) $day->format('N'), $this->taskDays($weekdays), true) ? ['key' => $day->format('Y-m-d'), 'due' => $day->format('Y-m-d')] : null,
            'weekly' => ['key' => $day->format('o-\WW'), 'due' => $day->setISODate((int) $day->format('o'), (int) $day->format('W'), $weekday)->format('Y-m-d')],
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

    /** Tâches d'un poste rangées par catégorie (puis fréquence), avec l'état de la période en cours et les retards. */
    public function board(array $tasks, DateTimeImmutable $today): array
    {
        $byId = [];
        $keys = [];
        foreach ($tasks as $task) {
            $period = $this->period($task['frequency'], $today, isset($task['due_weekday']) ? (int) $task['due_weekday'] : null, $task['weekdays'] ?? null);
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
            $o = $this->autoClose($o, $task, $today);
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
            $key = $task['category_code'] ?? null ?: '_none';
            $groups[$key]['label'] ??= $task['category_name'] ?? null ?: 'Bez kategorii';
            $groups[$key]['icon'] ??= $task['category_icon'] ?? null ?: 'bi-check2-square';
            $groups[$key]['sort'] ??= isset($task['category_sort']) ? (int) $task['category_sort'] : PHP_INT_MAX;
            $groups[$key]['open'] = ($groups[$key]['open'] ?? 0) + ($occurrence && $occurrence['status'] === 'open' ? 1 : 0);
            $groups[$key]['items'][] = ['task' => $task, 'occurrence' => $occurrence, 'frequency' => self::FREQUENCY_LABELS[$task['frequency']] ?? $task['frequency']];
        }
        uasort($groups, fn($a, $b) => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);
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
        $task = $this->positions->task((int) $occurrence['id_task']);
        if ($status === 'done' && $task && $task['form_code'] && !$this->positions->formFinal($occurrenceId)) {
            throw new CustomException('To zadanie kończy się protokołem — wypełnij i zakończ formularz.', 'errors', 422);
        }
        $this->positions->setOccurrence($occurrenceId, $status, $userId, $note === '' ? null : $note);
        return $this->positions->occurrence($occurrenceId);
    }

    public function categories(): array
    {
        return $this->positions->categories();
    }

    /** Kategoria zadań : kod (nowa), nazwy, ikona Bootstrap Icons, kolejność, aktywna. */
    public function saveCategory(array $input, ?int $id): int
    {
        $current = $id === null ? null : ($this->positions->category($id) ?? throw new CustomException('Nie znaleziono kategorii.', 'errors', 404));
        $v = (new Validator($input, $current !== null))->only(['code', 'name_pl', 'name_fr', 'icon', 'sort_order', 'active'])
            ->code('code', $current === null)
            ->string('name_pl', 100, $current === null)
            ->string('name_fr', 100, false, true)
            ->string('icon', 40, false, true, '/^bi-[a-z0-9-]+$/', 'INVALID_CODE_FORMAT')
            ->integer('sort_order', false, 0, 10000)
            ->boolean('active');
        $v->throwIfInvalid();
        $data = $v->clean();
        if ($v->provided('code') && $this->positions->categoryCodeTaken($v->value('code'), $id)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        if (array_key_exists('name_fr', $data) && ($data['name_fr'] ?? '') === '') $data['name_fr'] = $data['name_pl'] ?? $current['name_pl'];
        if ($current === null) {
            $data['name_fr'] ??= $data['name_pl'];
            $data['icon'] = ($data['icon'] ?? '') !== '' ? $data['icon'] : 'bi-check2-square';
            $data['sort_order'] ??= 90;
        } elseif (array_key_exists('icon', $data) && ($data['icon'] ?? '') === '') {
            $data['icon'] = 'bi-check2-square';
        }
        return $this->positions->saveCategory($id, $data);
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
        $v = (new Validator($input, $current !== null))->only(['code', 'name_pl', 'name_fr', 'description', 'kind', 'sort_order', 'active'])
            ->code('code', $current === null)
            ->string('name_pl', 100, $current === null)
            ->string('name_fr', 100, $current === null)
            ->string('description', 500, false, true)
            ->choice('kind', array_keys(PositionSheetService::KINDS), false)
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
        $v = (new Validator($input, $current !== null))->only(['title_pl', 'title_fr', 'frequency', 'due_weekday', 'minutes', 'weekdays', 'category_id', 'form_code', 'link_path', 'auto_code', 'sort_order', 'active'])
            ->string('title_pl', 200, $current === null)
            ->string('title_fr', 200, false, true)
            ->choice('frequency', self::FREQUENCIES, $current === null)
            ->integer('due_weekday', false, 1, 7, true)
            ->integer('minutes', false, 0, self::MAX_MINUTES, true)
            ->string('weekdays', 13, false, true, '/^[1-7](,[1-7])*$/', 'INVALID_CODE_FORMAT')
            ->choice('auto_code', array_keys(self::AUTO), false, true)
            ->integer('category_id', false, 1, null, true)
            ->choice('form_code', array_keys(TaskFormService::FORMS), false, true)
            ->string('link_path', 255, false, true, '/^\/[A-Za-z0-9\/_\-?=&.%#]*$/', 'INVALID_URL')
            ->integer('sort_order', false, 0, 10000)
            ->boolean('active');
        $v->throwIfInvalid();
        $data = $v->clean();
        if (array_key_exists('category_id', $data)) {
            if ($data['category_id'] !== null && !$this->positions->category((int) $data['category_id'])) throw new CustomException('Nie znaleziono kategorii.', 'errors', 404);
            $data['id_category'] = $data['category_id'];
            unset($data['category_id']);
        }
        foreach (['form_code', 'link_path', 'weekdays', 'auto_code'] as $k) if (array_key_exists($k, $data) && $data[$k] === '') $data[$k] = null;
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

    /**
     * Mój tydzień (Dzisiaj) : zadania stałe stanowiska dzień po dniu w bieżącym tygodniu ISO (dni robocze), z minutami,
     * linkiem do strony ERP i stanem. Zadania codzienne na wszystkie dni — w pasku nad tygodniem ; zaległe sprzed
     * tygodnia ; miesiąc i kwartał osobno. Okresy bieżące tworzą się jak w « Moje zadania » (dziś, tydzień, miesiąc,
     * kwartał) ; przyszłe dni tygodnia są tylko zaplanowane. Zadanie z kreatorem zalicza się samo (autoClose).
     */
    public function week(int $userId, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $user = $this->positions->user($userId);
        $monday = $today->setISODate((int) $today->format('o'), (int) $today->format('W'), 1);
        $sunday = $monday->modify('+6 days');
        $workdays = $this->workdays();
        $days = [];
        foreach ($workdays as $n) {
            $date = $monday->modify('+' . ($n - 1) . ' days');
            $days[$n] = ['n' => $n, 'date' => $date->format('Y-m-d'), 'label' => self::DAY_ABBR[$n] . ' ' . $date->format('d.m'), 'short' => self::DAY_SHORT[$n],
                'name' => self::WEEKDAYS[$n], 'today' => $date == $today, 'past' => $date < $today, 'items' => [], 'minutes' => 0, 'done_minutes' => 0];
        }
        $view = ['user' => $user, 'week' => ['number' => (int) $today->format('W'), 'from' => $monday->format('Y-m-d'), 'to' => $monday->modify('+' . (max($workdays) - 1) . ' days')->format('Y-m-d')],
            'days' => $days, 'strip' => [], 'overdue' => [], 'later' => [], 'totals' => null];
        $tasks = $user && $user['id_position'] ? $this->positions->tasks((int) $user['id_position'], true) : [];
        $byId = [];
        $current = [];
        foreach ($tasks as $task) {
            $byId[(int) $task['id']] = $task;
            $period = $this->period($task['frequency'], $today, isset($task['due_weekday']) ? (int) $task['due_weekday'] : null, $task['weekdays'] ?? null);
            if ($period) {
                $this->positions->ensureOccurrence((int) $task['id'], $period['key'], $period['due']);
                if ($task['frequency'] !== 'daily') $current[(int) $task['id']] = $period['key'];
            }
        }
        // Okres wystąpienia : dzień tygodnia (codzienne), ten tydzień, bieżący miesiąc / kwartał ; inne otwarte = zaległe.
        $occ = [];
        $from = $monday->format('Y-m-d');
        foreach ($this->positions->weekOccurrences(array_keys($byId), array_values(array_unique($current)), $from, $sunday->format('Y-m-d')) as $o) {
            $id = (int) $o['id_task'];
            $task = $byId[$id];
            $o = $this->autoClose($o, $task, $today);
            $mine = $task['frequency'] === 'daily' ? $o['due_date'] >= $from : ($current[$id] ?? null) === $o['period_key'];
            if ($mine) $occ[$id][$task['frequency'] === 'daily' ? $o['due_date'] : 'current'] = $o;
            elseif ($o['status'] === 'open') $view['overdue'][] = $this->weekItem($task, $o, $today);
        }
        foreach ($byId as $id => $task) {
            if ($task['frequency'] === 'daily') {
                $taskDays = $this->taskDays($task['weekdays'] ?? null);
                $slots = [];
                foreach ($days as $n => $day) {
                    if (in_array($n, $taskDays, true)) $slots[$n] = $this->weekItem($task, $occ[$id][$day['date']] ?? null, $today, $day);
                }
                if (!$slots) continue;
                if ($taskDays === $workdays) {
                    $todayN = (int) $today->format('N');
                    $view['strip'][] = ['item' => $slots[$todayN] ?? null, 'task' => reset($slots), 'slots' => $slots];
                } else {
                    foreach ($slots as $n => $slot) $view['days'][$n]['items'][] = $slot;
                }
            } elseif ($task['frequency'] === 'weekly') {
                $n = isset($task['due_weekday']) ? (int) $task['due_weekday'] : 5;
                if (!isset($days[$n])) $n = max($workdays);
                $view['days'][$n]['items'][] = $this->weekItem($task, $occ[$id]['current'] ?? null, $today, $days[$n]);
            } else {
                $view['later'][] = $this->weekItem($task, $occ[$id]['current'] ?? null, $today);
            }
        }
        usort($view['later'], fn($a, $b) => [$a['done'], $a['due']] <=> [$b['done'], $b['due']]);
        $view['totals'] = $this->weekTotals($view, $today);
        return $view;
    }

    /** Liczby kafelków « Mój tydzień » (sloty = zadania × dni) i minuty dni (pasek zrobione / zaplanowane). */
    private function weekTotals(array &$view, DateTimeImmutable $today): array
    {
        $t = ['done' => 0, 'total' => 0, 'minutes' => 0, 'done_minutes' => 0, 'today' => ['count' => 0, 'minutes' => 0, 'buttons' => [], 'open' => 0], 'late' => 0];
        $count = function (array $item, int $n) use (&$t, &$view) {
            $min = $item['minutes'] ?? 0;
            $t['total']++;
            $t['minutes'] += $min;
            $view['days'][$n]['minutes'] += $min;
            if ($item['done']) {
                $t['done']++;
                $t['done_minutes'] += $min;
                $view['days'][$n]['done_minutes'] += $min;
            }
            if ($item['late']) $t['late']++;
            if ($view['days'][$n]['today']) {
                $t['today']['count']++;
                $t['today']['minutes'] += $min;
                if (!$item['done']) $t['today']['open']++;
                if ($item['link']) $t['today']['buttons'][$item['button']] = true;
            }
        };
        foreach ($view['days'] as $n => $day) foreach ($day['items'] as $item) $count($item, $n);
        foreach ($view['strip'] as $row) foreach ($row['slots'] as $n => $slot) $count($slot, $n);
        $t['today']['buttons'] = array_keys($t['today']['buttons']);
        $t['overdue'] = count($view['overdue']);
        $t['share'] = (int) round($t['minutes'] / self::WEEK_MINUTES * 100);
        $open = array_values(array_filter($view['later'], fn($i) => !$i['done']));
        $t['later_open'] = count($open);
        $t['later_next'] = $open[0] ?? null;
        $t['today_name'] = self::WEEKDAYS[(int) $today->format('N')];
        return $t;
    }

    /** Karta zadania w tygodniu : stan (open, done, skipped ; bez wystąpienia : planned w przyszłości, none w przeszłości). */
    private function weekItem(array $task, ?array $o, DateTimeImmutable $today, ?array $day = null): array
    {
        $status = $o['status'] ?? ($day && $day['past'] ? 'none' : 'planned');
        $due = $o['due_date'] ?? ($day['date'] ?? null);
        $link = ($task['link_path'] ?? '') !== '' ? $task['link_path'] : null;
        return [
            'id' => $o ? (int) $o['id'] : null,
            'task_id' => (int) $task['id'],
            'title' => $task['title_pl'],
            'category' => $task['category_name'] ?? null,
            'color' => $this->color($task),
            'minutes' => isset($task['minutes']) ? (int) $task['minutes'] : null,
            'frequency' => self::FREQUENCY_LABELS[$task['frequency']] ?? $task['frequency'],
            'status' => $status,
            'done' => in_array($status, ['done', 'skipped'], true),
            'late' => $status === 'open' && $due !== null && $due < $today->format('Y-m-d'),
            'due' => $due,
            'link' => $link,
            'button' => $link !== null ? (self::LINKS[$link][1] ?? 'Otwórz') : null,
            'auto' => ($task['auto_code'] ?? null) ? (self::AUTO[$task['auto_code']] ?? null) : null,
            'auto_done' => $o !== null && $status === 'done' && $o['done_by'] === null && ($task['auto_code'] ?? null) !== null,
            'done_by' => $o['done_by_name'] ?? null,
            'done_at' => $o['done_at'] ?? null,
            'form' => ($task['form_code'] ?? null) ? ['status' => $o['form_status'] ?? null] : null,
        ];
    }

    /**
     * Zalicza wystąpienie samo, gdy kreator powiązany z zadaniem jest zakończony w jego okresie (faktury sprawdzone,
     * koszty przeliczone, ceny zaplanowane, zapytania wysłane). Brak tabeli albo błąd zapytania nie blokuje listy.
     */
    private function autoClose(array $o, array $task, DateTimeImmutable $today): array
    {
        $code = $task['auto_code'] ?? null;
        if ($code === null || $o['status'] !== 'open' || !isset(self::AUTO[$code])) return $o;
        [$from, $to] = $this->periodRange($task['frequency'], (string) $o['period_key'], (string) $o['due_date']);
        if ($from > $today->format('Y-m-d') . ' 23:59:59') return $o;
        try {
            if (!$this->positions->autoCondition($code, $from, $to)) return $o;
        } catch (\PDOException) {
            return $o;
        }
        $note = 'Automatycznie : ' . self::AUTO[$code];
        $this->positions->autoDone((int) $o['id'], $note);
        return ['status' => 'done', 'done_by' => null, 'done_by_name' => null, 'done_at' => date('Y-m-d H:i:s'), 'note' => $note] + $o;
    }

    /** Okres wystąpienia [od, do) : dzień, tydzień ISO, miesiąc, kwartał. @return array{0:string,1:string} */
    private function periodRange(string $frequency, string $key, string $due): array
    {
        $start = match ($frequency) {
            'weekly' => preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) ? (new DateTimeImmutable('today'))->setISODate((int) $m[1], (int) $m[2], 1) : new DateTimeImmutable($due),
            'monthly' => new DateTimeImmutable(substr($due, 0, 7) . '-01'),
            'quarterly' => (function () use ($due) {
                $d = new DateTimeImmutable($due);
                return new DateTimeImmutable(sprintf('%s-%02d-01', $d->format('Y'), (intdiv((int) $d->format('n') - 1, 3)) * 3 + 1));
            })(),
            default => new DateTimeImmutable($due),
        };
        $start = $start->setTime(0, 0);
        $end = $start->modify(match ($frequency) { 'weekly' => '+7 days', 'monthly' => '+1 month', 'quarterly' => '+3 months', default => '+1 day' });
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private function color(array $task): string
    {
        $code = $task['category_code'] ?? null;
        if ($code !== null && isset(self::CATEGORY_COLORS[$code])) return self::CATEGORY_COLORS[$code];
        return isset($task['id_category']) ? self::FALLBACK_COLORS[(int) $task['id_category'] % count(self::FALLBACK_COLORS)] : '#8e1f2f';
    }

    // --- Plan tygodnia stanowiska (administrator) -------------------------------------------------------------

    /**
     * Plan tygodnia stanowiska : zadania aktywne z dniami, minutami, linkiem i czasem rzeczywistym (średni czas aktywny
     * osób na stanowisku na stronie zadania w ostatnich 4 tygodniach, na jedno wykonanie), obciążenie dni i tygodnia.
     */
    public function weekPlan(int $positionId, ?int $actorId, ?DateTimeImmutable $now = null): array
    {
        $this->assertAdmin($actorId);
        $position = $this->positions->find($positionId) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $users = array_values(array_filter($this->positions->users(), fn($u) => (int) $u['id_position'] === $positionId));
        $workdays = $this->workdays();
        $rows = array_map(fn($t) => $this->planRow($t), $this->positions->tasks($positionId, true));
        usort($rows, fn($a, $b) => [array_search($a['period'], ['week', 'monthly', 'quarterly'], true), $a['days'][0] ?? 9, count($a['days']), $a['sort_order'], $a['id']]
            <=> [array_search($b['period'], ['week', 'monthly', 'quarterly'], true), $b['days'][0] ?? 9, count($b['days']), $b['sort_order'], $b['id']]);
        $since = ($now ?? new DateTimeImmutable())->modify('-28 days')->format('Y-m-d H:i:s');
        $rows = $this->withRealTime($rows, $this->positions->activeSecondsByPath(array_map(fn($u) => (int) $u['id'], $users), $since));
        $load = array_fill_keys($workdays, 0);
        foreach ($rows as $r) {
            if ($r['period'] !== 'week') continue;
            foreach ($r['days'] as $d) if (isset($load[$d])) $load[$d] += $r['minutes'] ?? 0;
        }
        $week = array_sum($load);
        $others = array_values(array_filter($this->positions->positions(), fn($p) => (int) $p['id'] !== $positionId && (int) $p['task_count'] > 0));
        return [
            'position' => $position,
            'users' => $users,
            'tasks' => $rows,
            'workdays' => $workdays,
            'day_short' => array_intersect_key(self::DAY_SHORT, array_flip($workdays)),
            'load' => ['days' => $load, 'week' => $week, 'share' => (int) round($week / self::WEEK_MINUTES * 100), 'week_minutes' => self::WEEK_MINUTES],
            'categories' => array_values(array_filter($this->positions->categories(), fn($c) => (int) $c['active'] === 1)),
            'links' => array_map(fn($l) => $l[0], self::LINKS),
            'auto' => self::AUTO,
            'link_auto' => self::LINK_AUTO,
            'others' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name_pl'], 'tasks' => (int) $p['task_count']], $others),
            'max_minutes' => self::MAX_MINUTES,
        ];
    }

    private function planRow(array $task): array
    {
        $period = in_array($task['frequency'], ['monthly', 'quarterly'], true) ? $task['frequency'] : 'week';
        $days = match ($task['frequency']) {
            'weekly' => [isset($task['due_weekday']) ? (int) $task['due_weekday'] : 5],
            'daily' => $this->taskDays($task['weekdays'] ?? null),
            default => [],
        };
        return [
            'id' => (int) $task['id'],
            'title_pl' => $task['title_pl'],
            'category_id' => isset($task['id_category']) ? (int) $task['id_category'] : null,
            'category_name' => $task['category_name'] ?? null,
            'period' => $period,
            'days' => $days,
            'minutes' => isset($task['minutes']) ? (int) $task['minutes'] : null,
            'link_path' => $task['link_path'] ?? null,
            'auto_code' => $task['auto_code'] ?? null,
            'form_code' => $task['form_code'] ?? null,
            'sort_order' => (int) ($task['sort_order'] ?? 0),
            // wykonania w 4 tygodniach (podział czasu rzeczywistego)
            'per_4w' => $period === 'week' ? 4 * count($days) : ($period === 'monthly' ? 1 : 1 / 3),
        ];
    }

    /**
     * Czas rzeczywisty zadania : czas aktywny na stronach pod ścieżką linku (bez # i ?, najdłuższy pasujący prefiks),
     * dzielony między zadania tej samej strony proporcjonalnie do planu, ÷ liczba wykonań w 4 tygodniach.
     * « over » = ponad 125 % minut planu, « ok » = w planie. Tylko zadania tygodnia (dni).
     */
    private function withRealTime(array $rows, array $secondsByPath): array
    {
        $base = fn(?string $link) => $link ? '/' . trim((string) strtok($link, '?#'), '/') : null;
        $groups = [];
        foreach ($rows as $i => $r) if (($b = $base($r['link_path'])) !== null) $groups[$b][] = $i;
        $seconds = array_fill_keys(array_keys($groups), 0);
        foreach ($secondsByPath as $path => $s) {
            $best = null;
            foreach (array_keys($groups) as $b) {
                if (($path === $b || str_starts_with($path, rtrim($b, '/') . '/')) && ($best === null || strlen($b) > strlen($best))) $best = $b;
            }
            if ($best !== null) $seconds[$best] += $s;
        }
        foreach ($rows as $i => $r) $rows[$i] += ['real' => null, 'real_state' => '', 'shared' => false];
        foreach ($groups as $b => $idx) {
            $weights = array_map(fn($i) => ($rows[$i]['minutes'] ?? 0) * $rows[$i]['per_4w'], $idx);
            $sum = array_sum($weights);
            foreach ($idx as $k => $i) {
                $rows[$i]['shared'] = count($idx) > 1;
                $share = $sum > 0 ? $seconds[$b] * $weights[$k] / $sum : $seconds[$b] / count($idx);
                if ($rows[$i]['period'] !== 'week' || $share <= 0 || $rows[$i]['per_4w'] <= 0) continue;
                $real = max(1, (int) round($share / 60 / $rows[$i]['per_4w']));
                $rows[$i]['real'] = $real;
                $planned = $rows[$i]['minutes'];
                $rows[$i]['real_state'] = $planned === null ? '' : ($real > $planned * 1.25 ? 'over' : ($real <= $planned ? 'ok' : ''));
            }
        }
        return $rows;
    }

    /**
     * Zapis planu tygodnia (cały plan naraz, w transakcji) : wiersze w kolejności ekranu — nazwa, kategoria, dni
     * (1 dzień = co tydzień w ten dzień, wszystkie dni robocze = codziennie, kilka = codziennie w te dni) albo
     * co miesiąc / co kwartał, minuty, link, zaliczenie automatyczne ; usunięte = nieaktywne. Zmiana dni kasuje otwarte
     * wystąpienia od poniedziałku (powstaną według nowego planu). @return int liczba zadań planu
     */
    public function saveWeekPlan(int $positionId, array $input, ?int $actorId, ?DateTimeImmutable $today = null): int
    {
        $this->assertAdmin($actorId);
        if (!$this->positions->exists($positionId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $rows = $input['tasks'] ?? null;
        if (!is_array($rows)) throw new CustomException('Brak zadań planu.', 'errors', 422);
        $existing = array_column($this->positions->tasks($positionId), null, 'id');
        $workdays = $this->workdays();
        $plan = [];
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) throw new CustomException("Wiersz {$n} : nieprawidłowe dane.", 'errors', 422);
            $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
            if ($id !== null && !isset($existing[$id])) throw new CustomException("Zadanie {$id} nie należy do tego stanowiska.", 'errors', 404);
            $title = trim((string) ($row['title_pl'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) throw new CustomException("Wiersz {$n} : podaj nazwę zadania (najwyżej 200 znaków).", 'errors', 422);
            $minutes = $row['minutes'] ?? null;
            if ($minutes === '' || $minutes === null) {
                $minutes = null;
            } elseif (!is_numeric($minutes) || (int) $minutes != $minutes || (int) $minutes < 0 || (int) $minutes > self::MAX_MINUTES) {
                throw new CustomException("Wiersz {$n} : minuty od 0 do " . self::MAX_MINUTES . '.', 'errors', 422);
            } else {
                $minutes = (int) $minutes;
            }
            $category = isset($row['category_id']) && $row['category_id'] !== '' ? (int) $row['category_id'] : null;
            if ($category !== null && !$this->positions->category($category)) throw new CustomException("Wiersz {$n} : nie znaleziono kategorii.", 'errors', 422);
            $link = trim((string) ($row['link_path'] ?? ''));
            if ($link === '') $link = null;
            elseif (mb_strlen($link) > 255 || !preg_match('/^\/[A-Za-z0-9\/_\-?=&.%#]*$/', $link)) throw new CustomException("Wiersz {$n} : link musi być ścieżką ERP, np. /purchasing.", 'errors', 422);
            $auto = ($row['auto_code'] ?? '') === '' ? null : (string) $row['auto_code'];
            if ($auto !== null && !isset(self::AUTO[$auto])) throw new CustomException("Wiersz {$n} : nieznane zaliczenie automatyczne.", 'errors', 422);
            $period = (string) ($row['period'] ?? 'week');
            if (!in_array($period, ['week', 'monthly', 'quarterly'], true)) throw new CustomException("Wiersz {$n} : nieznana częstotliwość.", 'errors', 422);
            $days = array_values(array_unique(array_filter(array_map('intval', is_array($row['days'] ?? null) ? $row['days'] : []), fn($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if ($period !== 'week') $schedule = ['frequency' => $period, 'due_weekday' => null, 'weekdays' => null];
            elseif (!$days) throw new CustomException("Wiersz {$n} : wybierz co najmniej jeden dzień.", 'errors', 422);
            elseif (count($days) === 1) $schedule = ['frequency' => 'weekly', 'due_weekday' => $days[0], 'weekdays' => null];
            elseif ($days === $workdays) $schedule = ['frequency' => 'daily', 'due_weekday' => null, 'weekdays' => null];
            else $schedule = ['frequency' => 'daily', 'due_weekday' => null, 'weekdays' => implode(',', $days)];
            $plan[] = ['id' => $id, 'data' => ['title_pl' => $title, 'id_category' => $category, 'minutes' => $minutes, 'link_path' => $link, 'auto_code' => $auto] + $schedule];
        }
        $removed = array_values(array_unique(array_map('intval', is_array($input['removed'] ?? null) ? $input['removed'] : [])));
        foreach ($removed as $rid) if (!isset($existing[$rid])) throw new CustomException("Zadanie {$rid} nie należy do tego stanowiska.", 'errors', 404);
        $today = ($today ?? new DateTimeImmutable('today'));
        $monday = $today->setISODate((int) $today->format('o'), (int) $today->format('W'), 1)->format('Y-m-d');
        $this->positions->beginTransaction();
        try {
            foreach ($plan as $i => $p) {
                $data = $p['data'] + ['sort_order' => ($i + 1) * 10, 'active' => 1];
                if ($p['id'] === null) {
                    $this->positions->insertTask($data + ['id_position' => $positionId, 'title_fr' => $data['title_pl']]);
                    continue;
                }
                $cur = $existing[$p['id']];
                if ($cur['title_fr'] === $cur['title_pl']) $data['title_fr'] = $data['title_pl'];
                $was = [$cur['frequency'], (string) ($cur['due_weekday'] ?? ''), (string) ($cur['weekdays'] ?? '')];
                if ($was !== [$data['frequency'], (string) ($data['due_weekday'] ?? ''), (string) ($data['weekdays'] ?? '')]) {
                    $this->positions->dropOpenOccurrences($p['id'], $monday);
                }
                $this->positions->updateTask($p['id'], $data);
            }
            foreach ($removed as $rid) {
                $this->positions->updateTask($rid, ['active' => 0]);
                $this->positions->dropOpenOccurrences($rid, $monday);
            }
            $this->positions->commit();
        } catch (\Throwable $e) {
            $this->positions->rollBack();
            throw $e;
        }
        return count($plan);
    }

    /** Kopiuje aktywne zadania innego stanowiska (bez tych o tej samej nazwie). @return int liczba skopiowanych */
    public function copyPlan(int $positionId, int $sourceId, ?int $actorId): int
    {
        $this->assertAdmin($actorId);
        if (!$this->positions->exists($positionId) || !$this->positions->exists($sourceId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        if ($positionId === $sourceId) throw new CustomException('Wybierz inne stanowisko.', 'errors', 422);
        $have = array_map(fn($t) => mb_strtolower(trim($t['title_pl'])), $this->positions->tasks($positionId, true));
        $n = 0;
        foreach ($this->positions->tasks($sourceId, true) as $t) {
            if (in_array(mb_strtolower(trim($t['title_pl'])), $have, true)) continue;
            $this->positions->insertTask(['id_position' => $positionId] + array_intersect_key($t, array_flip(['id_category', 'title_pl', 'title_fr', 'frequency', 'due_weekday', 'minutes', 'weekdays',
                'link_path', 'form_code', 'auto_code', 'sort_order'])) + ['active' => 1]);
            $n++;
        }
        return $n;
    }

    private function assertAdmin(?int $actorId): void
    {
        if (!$this->access->isAdmin($actorId)) throw new CustomException('Plan tygodnia stanowiska ustala administrator.', 'errors', 403);
    }

    /** Dni zadania codziennego : jego dni (np. « 1,4 ») spośród dni roboczych, inaczej wszystkie dni robocze. @return list<int> */
    private function taskDays(?string $weekdays): array
    {
        $work = $this->workdays();
        if ($weekdays === null || $weekdays === '') return $work;
        return array_values(array_intersect($work, array_map('intval', explode(',', $weekdays))));
    }

    /** @return list<int> */
    private function workdays(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('tasks.workdays', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7));
        return $days ?: [1, 2, 3, 4, 5];
    }
}
