<?php
namespace App\Services\Organization;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Organization\TaskFormRepository;
use App\Services\Workshop\Execution\ProductionFollowUpService;

/**
 * Zadania z formularzem : protokół zebrania (data, uczestnicy, tematy, działania z osobą i terminem).
 * Zebranie produkcyjne dodaje śledzenie produkcji okresu (plan, zrobione, straty, zatwierdzone dni, NC) —
 * na żywo w szkicu, zapisane w protokole przy zakończeniu. Zakończenie protokołu = zadanie okresu zrobione.
 * Działania otwarte wracają na następne zebranie tego rodzaju, aż ktoś je zamknie.
 */
final class TaskFormService
{
    public const FORMS = [
        'production_meeting' => [
            'label' => 'Protokół zebrania produkcyjnego',
            'follow_up' => true,
            'topics' => [
                'safety' => 'BHP i bezpieczeństwo',
                'quality' => 'Jakość : straty, reklamacje, niezgodności',
                'production' => 'Produkcja : wykonanie planu, plan na kolejny tydzień',
                'team' => 'Zespół : obecności, szkolenia, kompetencje',
                'equipment' => 'Maszyny, utrzymanie ruchu, higiena',
                'other' => 'Inne',
            ],
        ],
        'meeting' => [
            'label' => 'Protokół zebrania',
            'follow_up' => false,
            'topics' => ['notes' => 'Ustalenia'],
        ],
    ];

    public function __construct(
        private TaskFormRepository $forms,
        private PositionRepository $positions,
        private ProductionFollowUpService $followUp,
        private TransactionManager $tx,
    ) {
    }

    /** @return array<string, string> kod => nazwa (wybór w zadaniu stanowiska) */
    public static function choices(): array
    {
        return array_map(fn($f) => $f['label'], self::FORMS);
    }

    /** Formularz okresu zadania : zapisany (szkic / zakończony) albo nowy z podpowiedziami. */
    public function open(int $occurrenceId, ?string $from = null, ?string $to = null): array
    {
        [$occurrence, $task, $definition] = $this->context($occurrenceId);
        $form = $this->forms->byOccurrence($occurrenceId);
        $data = $form ? (json_decode((string) $form['data'], true) ?: []) : [];
        if (!$form) {
            $previous = $this->forms->previous($task['form_code'], 0);
            $data['participants'] = $previous ? (json_decode((string) $previous['data'], true)['participants'] ?? []) : [];
        }
        $meetingDate = $form['meeting_date'] ?? (date('Y-m-d') <= $occurrence['due_date'] ? date('Y-m-d') : $occurrence['due_date']);
        $final = ($form['status'] ?? '') === 'final';
        $followUp = null;
        if ($definition['follow_up']) {
            if ($final && isset($data['follow_up'])) {
                $followUp = $data['follow_up'];
            } else {
                // Domyślnie : 7 dni przed zebraniem.
                $defaultTo = date('Y-m-d', strtotime($meetingDate . ' -1 day'));
                $followUp = $this->followUp->summary($from ?? $form['period_from'] ?? date('Y-m-d', strtotime($defaultTo . ' -6 days')), $to ?? $form['period_to'] ?? $defaultTo);
            }
        }
        $formId = (int) ($form['id'] ?? 0);
        return [
            'occurrence' => $occurrence,
            'task' => $task,
            'definition' => $definition + ['code' => $task['form_code']],
            'form' => $form,
            'final' => $final,
            'meeting_date' => $meetingDate,
            'participants' => array_values(array_map('intval', $data['participants'] ?? [])),
            'guests' => (string) ($data['guests'] ?? ''),
            'topics' => array_map(fn($k) => (string) ($data['topics'][$k] ?? ''), array_combine(array_keys($definition['topics']), array_keys($definition['topics']))),
            'follow_up' => $followUp,
            'actions' => $formId ? $this->forms->actions($formId) : [],
            'carried' => $this->forms->carried($task['form_code'], $formId),
            'users' => $this->forms->users(),
        ];
    }

    /**
     * Zapis protokołu (szkic albo zakończenie). Nowe działania (tytuł, osoba, termin), działania z poprzednich
     * zebrań zamknięte lub otwarte ponownie, działania tego protokołu usunięte.
     */
    public function save(int $occurrenceId, array $input, ?int $userId): array
    {
        [$occurrence, $task, $definition] = $this->context($occurrenceId);
        $final = !empty($input['final']);
        $date = (string) ($input['meeting_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new CustomException('Podaj datę zebrania.', 'errors', 422);
        $users = array_column($this->forms->users(), 'id');
        $participants = array_values(array_unique(array_filter(array_map('intval', (array) ($input['participants'] ?? [])), fn($id) => in_array($id, array_map('intval', $users), true))));
        $guests = trim((string) ($input['guests'] ?? ''));
        if (mb_strlen($guests) > 500) throw new CustomException('Goście : najwyżej 500 znaków.', 'errors', 422);
        if ($final && !$participants && $guests === '') throw new CustomException('Zaznacz uczestników zebrania.', 'errors', 422);
        $topics = [];
        foreach (array_keys($definition['topics']) as $k) {
            $text = trim((string) ($input['topics'][$k] ?? ''));
            if (mb_strlen($text) > 4000) throw new CustomException('Temat « ' . $definition['topics'][$k] . ' » : najwyżej 4000 znaków.', 'errors', 422);
            $topics[$k] = $text;
        }
        $newActions = [];
        foreach ((array) ($input['new_actions'] ?? []) as $a) {
            $title = trim((string) ($a['title'] ?? ''));
            if ($title === '') continue;
            if (mb_strlen($title) > 300) throw new CustomException('Działanie : najwyżej 300 znaków.', 'errors', 422);
            $owner = isset($a['user_id']) && $a['user_id'] !== '' && $a['user_id'] !== null ? (int) $a['user_id'] : null;
            if ($owner !== null && !in_array($owner, array_map('intval', $users), true)) throw new CustomException('Nieznana osoba odpowiedzialna.', 'errors', 422);
            $due = trim((string) ($a['due_date'] ?? ''));
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new CustomException('Nieprawidłowy termin działania.', 'errors', 422);
            $newActions[] = [$title, $owner, $due === '' ? null : $due];
        }
        if ($final && !array_filter($topics, fn($t) => $t !== '') && !$newActions) throw new CustomException('Wpisz ustalenia zebrania (co najmniej jeden temat albo działanie).', 'errors', 422);
        $form = $this->forms->byOccurrence($occurrenceId);
        $formId = (int) ($form['id'] ?? 0);
        $closeIds = array_map('intval', (array) ($input['close_actions'] ?? []));
        $reopenIds = array_map('intval', (array) ($input['reopen_actions'] ?? []));
        $deleteIds = array_map('intval', (array) ($input['delete_actions'] ?? []));
        $period = [null, null];
        $followUp = null;
        if ($definition['follow_up']) {
            $period = ProductionFollowUpService::period($input['period_from'] ?? null, $input['period_to'] ?? null);
            if ($final) $followUp = $this->followUp->summary($period[0], $period[1]);
        }
        $data = ['participants' => $participants, 'guests' => $guests, 'topics' => $topics] + ($followUp ? ['follow_up' => $followUp] : []);
        $formId = $this->tx->execute(function () use ($form, $formId, $occurrenceId, $task, $date, $period, $data, $final, $userId, $newActions, $closeIds, $reopenIds, $deleteIds, $occurrence) {
            $row = ['meeting_date' => $date, 'period_from' => $period[0], 'period_to' => $period[1], 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => $final ? 'final' : 'draft', 'updated_by' => $userId];
            if ($form) {
                $this->forms->update($formId, $row);
            } else {
                $formId = $this->forms->insert($row + ['id_occurrence' => $occurrenceId, 'form_code' => $task['form_code'], 'created_by' => $userId]);
            }
            foreach ($newActions as [$title, $owner, $due]) $this->forms->insertAction($formId, $title, $owner, $due);
            foreach ($deleteIds as $id) {
                $a = $this->forms->action($id);
                if ($a && (int) $a['id_form'] === $formId) $this->forms->deleteAction($id);
            }
            foreach (array_merge(array_map(fn($id) => [$id, true], $closeIds), array_map(fn($id) => [$id, false], $reopenIds)) as [$id, $done]) {
                $a = $this->forms->action($id);
                if ($a && $this->sameKind($a, $task['form_code'])) $this->forms->setActionDone($id, $done, $userId, $formId);
            }
            if ($final && $occurrence['status'] !== 'done') {
                $this->positions->setOccurrence($occurrenceId, 'done', $userId, 'Protokół zebrania ' . date('d.m.Y', strtotime($date)));
            }
            return $formId;
        });
        return $this->open($occurrenceId) + ['saved_id' => $formId];
    }

    /** Protokół do odczytu i druku. */
    public function view(int $formId): array
    {
        $form = $this->forms->form($formId) ?? throw new CustomException('Nie znaleziono protokołu.', 'errors', 404);
        $definition = self::FORMS[$form['form_code']] ?? self::FORMS['meeting'];
        $data = json_decode((string) $form['data'], true) ?: [];
        $names = array_column($this->forms->users(), 'name', 'id');
        return [
            'form' => $form,
            'definition' => $definition,
            'participants' => array_values(array_filter(array_map(fn($id) => $names[$id] ?? null, $data['participants'] ?? []))),
            'guests' => (string) ($data['guests'] ?? ''),
            'topics' => array_filter(array_map(fn($k) => ['label' => $definition['topics'][$k], 'text' => (string) ($data['topics'][$k] ?? '')], array_keys($definition['topics'])), fn($t) => $t['text'] !== ''),
            'follow_up' => $data['follow_up'] ?? null,
            'actions' => $this->forms->actions($formId),
            'closed' => array_values(array_filter($this->forms->carried($form['form_code'], $formId), fn($a) => (int) $a['done_in_form'] === $formId)),
        ];
    }

    public function list(): array
    {
        return array_map(function ($f) {
            $data = json_decode((string) $f['data'], true) ?: [];
            return $f + ['label' => self::FORMS[$f['form_code']]['label'] ?? $f['form_code'], 'participants' => count($data['participants'] ?? []),
                'actions' => count($this->forms->actions((int) $f['id']))];
        }, $this->forms->forms());
    }

    /** @return array{0: array, 1: array, 2: array} [okres zadania, zadanie, definicja formularza] */
    private function context(int $occurrenceId): array
    {
        $occurrence = $this->positions->occurrence($occurrenceId) ?? throw new CustomException('Nie znaleziono zadania.', 'errors', 404);
        $task = $this->positions->task((int) $occurrence['id_task']) ?? throw new CustomException('Nie znaleziono zadania.', 'errors', 404);
        if (!$task['form_code'] || !isset(self::FORMS[$task['form_code']])) throw new CustomException('To zadanie nie ma formularza.', 'errors', 422);
        return [$occurrence, $task, self::FORMS[$task['form_code']]];
    }

    private function sameKind(array $action, string $code): bool
    {
        $form = $this->forms->form((int) $action['id_form']);
        return $form !== null && $form['form_code'] === $code;
    }
}
