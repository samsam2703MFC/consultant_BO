<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Hr\StaffDeadlinesRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Badania i szkolenia (przegląd terminów) » [404] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik
 * produkcji 9.5 i 9.6). Mois du contrôle = le mois de la tâche (état des échéances aujourd'hui, comme « PPWR i opakowania »).
 * Salariés actifs seulement (n_prod_employee.active = 1) ; aucune donnée chiffrée lue, rien de personnel dans les journaux.
 *   1. Przegląd : par salarié, deux sortes d'éléments :
 *        - badania lekarskie (n_hr_employee.medical_until, la date du tableau HR) : medical_missing (pas de date),
 *          medical_expired (dépassée), medical_soon (dans les hr.alert_days jours — la règle « medical_alert » du tableau
 *          HR) ; date du dernier document HR « Badania lekarskie » pour information ;
 *        - szkolenia du registre de l'assistant (n_staff_training), par type : on garde le meilleur enregistrement (sans
 *          échéance, sinon la date « ważne do » la plus lointaine) — training_expired, training_soon (hr.alert_days jours) ;
 *          training_missing : aucun szkolenie BHP enregistré, si staff_deadlines.bhp_required = 1.
 *      Registre des szkolenia et formulaire d'ajout (salarié, type, fait le, valable jusqu'au) dans l'assistant.
 *   2. Do decyzji : une décision par élément signalé (n_staff_deadline_review, par mois) — Umówione (date obligatoire, de
 *      aujourd'hui à un an ; reprise les mois suivants jusqu'à cette date), Aktualne — sprawdzone (acceptée seulement si le
 *      signal a disparu : date mise à jour dans HR, szkolenie saisi ici — enregistrée seule après la saisie), Nie dotyczy
 *      (note obligatoire, reprise tant que les signaux sont les mêmes).
 *   3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un élément attend ; la tâche du mois se valide seule.
 * N'écrit que n_staff_training, n_staff_deadline_review et n_wizard_done : la fiche HR n'est pas modifiée (lien vers HR),
 * aucun rendez-vous pris, aucun e-mail ni SMS envoyé.
 */
final class StaffDeadlinesService
{
    public const CODE = 'staff_deadlines_reviewed';
    public const PATH = '/hr/deadlines';
    public const DECISIONS = ['booked' => 'Umówione', 'current' => 'Aktualne — sprawdzone', 'not_applicable' => 'Nie dotyczy'];
    public const KINDS = ['medical' => 'Badania lekarskie', 'training' => 'Szkolenie'];
    public const TRAININGS = ['bhp' => 'BHP', 'sanitary' => 'Higieniczno-sanitarne (HACCP)', 'first_aid' => 'Pierwsza pomoc', 'fire' => 'Ochrona ppoż.',
        'equipment' => 'Obsługa urządzeń (np. UDT)', 'other' => 'Inne'];
    /** Signaux, dans l'ordre de priorité. */
    public const SIGNALS = ['medical_expired' => 'badania lekarskie po terminie', 'medical_missing' => 'brak daty badań lekarskich w HR',
        'training_expired' => 'szkolenie po terminie', 'training_missing' => 'brak szkolenia BHP w rejestrze',
        'medical_soon' => 'badania lekarskie kończą się wkrótce', 'training_soon' => 'szkolenie kończy się wkrótce'];
    public const LINKS = ['hr' => ['/hr', 'HR — karta pracownika (data badań)'], 'hr_docs' => ['/hr/documents', 'HR — dokumenty']];
    public const SETTINGS = ['alert_days' => 'hr.alert_days', 'bhp_required' => 'staff_deadlines.bhp_required'];

    public function __construct(private StaffDeadlinesRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Premier jour du mois du contrôle (clé des décisions et de l'état de la tâche du mois). */
    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /** Réglages : délai d'alerte du tableau HR (1–180 jours, comme HrService::rules) et signal « brak BHP ». */
    public function settings(): array
    {
        $alert = (int) $this->params->get(self::SETTINGS['alert_days'], '30');
        $bhp = $this->params->get(self::SETTINGS['bhp_required'], '1');
        return ['alert_days' => max(1, min(180, $alert ?: 30)), 'bhp_required' => ParamRules::rangeError(self::SETTINGS['bhp_required'], $bhp) === null ? (int) $bhp === 1 : true];
    }

    /** Données des trois étapes ($withAll : aussi les éléments sans signal — pour enregistrer « aktualne »). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $set = $this->settings();
        $soon = date('Y-m-d', strtotime($today . ' +' . $set['alert_days'] . ' days'));
        $employees = $this->repo->employees();
        $register = $this->repo->hasTable('n_staff_training') ? $this->repo->trainings() : [];
        $byEmp = [];
        foreach ($register as $t) $byEmp[(int) $t['id_employee']][(string) $t['kind']][] = $t;
        $names = [];
        $items = [];
        $stats = ['employees' => 0, 'medical_missing' => 0, 'medical_expired' => 0, 'medical_soon' => 0, 'training_missing' => 0, 'training_expired' => 0, 'training_soon' => 0, 'trainings' => count($register)];
        foreach ($employees ?? [] as $e) {
            $id = (int) $e['id'];
            $stats['employees']++;
            $names[$id] = trim((string) $e['name']) ?: '#' . $id;
            $sub = implode(' · ', array_filter([$e['group_name'] ?? null, $e['position'] ?? null]));
            $med = $e['medical_until'] !== null ? substr((string) $e['medical_until'], 0, 10) : null;
            $codes = $med === null ? ['medical_missing'] : ($med < $today ? ['medical_expired'] : ($med <= $soon ? ['medical_soon'] : []));
            foreach ($codes as $c) $stats[$c]++;
            $detail = $med === null ? 'pole « Badania ważne do » puste' : 'ważne do ' . self::d($med) . self::left($med, $today);
            $items[] = ['key' => 'medical:' . $id, 'kind' => 'medical', 'kind_label' => self::KINDS['medical'], 'id_employee' => $id, 'title' => $names[$id], 'sub' => $sub,
                'subject' => self::KINDS['medical'], 'training_kind' => null, 'until' => $med, 'done_on' => null,
                'info' => $e['medical_doc'] ? 'ostatni dokument « Badania lekarskie » w HR : ' . self::d(substr((string) $e['medical_doc'], 0, 10)) : 'brak dokumentu « Badania lekarskie » w HR',
                'codes' => $codes, 'signals' => array_map(fn($c) => self::sig($c, $detail), $codes), 'links' => self::links(['hr', 'hr_docs'])];
            $kinds = array_keys($byEmp[$id] ?? []);
            if ($set['bhp_required'] && !in_array('bhp', $kinds, true)) $kinds[] = 'bhp';
            foreach (array_values(array_intersect(array_keys(self::TRAININGS), $kinds)) as $k) {
                $rows = $byEmp[$id][$k] ?? [];
                $best = $rows[0] ?? null; // trié : sans échéance d'abord, puis « ważne do » la plus lointaine
                $until = $best && $best['valid_until'] !== null ? substr((string) $best['valid_until'], 0, 10) : null;
                $codes = $best === null ? ['training_missing'] : ($until === null ? [] : ($until < $today ? ['training_expired'] : ($until <= $soon ? ['training_soon'] : [])));
                foreach ($codes as $c) $stats[$c]++;
                $detail = $best === null ? 'brak wpisu w rejestrze szkoleń' : ($until === null ? 'bezterminowe' : 'ważne do ' . self::d($until) . self::left($until, $today));
                $items[] = ['key' => 'training:' . $id . ':' . $k, 'kind' => 'training', 'kind_label' => self::KINDS['training'], 'id_employee' => $id, 'title' => $names[$id], 'sub' => $sub,
                    'subject' => 'Szkolenie ' . self::TRAININGS[$k], 'training_kind' => $k, 'until' => $until, 'done_on' => $best && $best['done_on'] ? substr((string) $best['done_on'], 0, 10) : null,
                    'info' => $best ? trim(($best['title'] ? $best['title'] . ' · ' : '') . ($best['done_on'] ? 'szkolenie ' . self::d(substr((string) $best['done_on'], 0, 10)) : 'bez daty szkolenia')) : null,
                    'codes' => $codes, 'signals' => array_map(fn($c) => self::sig($c, $detail), $codes), 'links' => []];
            }
        }

        // Décisions : celle du mois ; sinon une décision d'un mois précédent qui tient encore (reprise) : « Nie dotyczy » pour
        // les mêmes signaux, « Umówione » jusqu'à la date du rendez-vous. Un élément décidé ce mois reste à l'écran.
        $decided = array_flip($this->repo->decidedIn($month));
        $all = $withAll ? array_column($items, null, 'key') : [];
        $items = array_values(array_filter($items, fn($i) => $i['codes'] !== [] || isset($decided[$i['key']])));
        $dec = $this->repo->decisions(array_column($items, 'key'));
        foreach ($items as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $month) { $mine ??= $d; continue; }
                $earlier ??= $d;
            }
            $covers = fn(?array $d) => $d !== null && array_diff($it['codes'], array_filter(explode(',', (string) $d['flags']))) === [];
            $pending = fn(?array $d) => $d !== null && $d['decision'] === 'booked' && $d['booked_on'] !== null && substr((string) $d['booked_on'], 0, 10) >= $today;
            $carried = $mine === null && $earlier !== null && $it['codes'] !== [] && (($earlier['decision'] === 'not_applicable' && $covers($earlier)) || $pending($earlier));
            $covered = $mine !== null && match ((string) $mine['decision']) { 'current' => $it['codes'] === [], 'booked' => $pending($mine) || $it['codes'] === [], default => $covers($mine) };
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['previous'] = !$carried && $mine === null && $earlier !== null ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covered && !$carried;
            $it['reason'] = null;
            if ($it['waiting'] && ($mine ?? $earlier) !== null) {
                $last = $mine ?? $earlier;
                $it['reason'] = match ((string) $last['decision']) {
                    'booked' => 'termin umówiony ' . self::d(substr((string) $last['booked_on'], 0, 10)) . ' minął — wpisz nową datę ' . ($it['kind'] === 'medical' ? 'badań w HR' : 'szkolenia') . ' albo umów ponownie',
                    'current' => 'sygnał wrócił po « Aktualne — sprawdzone »',
                    default => $mine !== null ? 'nowy sygnał po decyzji' : null,
                };
            }
        }
        unset($it);
        $rank = array_flip(array_keys(self::SIGNALS));
        $first = fn(array $i) => $i['codes'] ? $rank[$i['codes'][0]] : 99;
        usort($items, fn($a, $b) => [$b['waiting'], $first($a), $a['title'], $a['key']] <=> [$a['waiting'], $first($b), $b['title'], $b['key']]);

        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $count = fn(callable $f) => count(array_filter($items, $f));
        $totals = ['review' => count($items), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']),
            'earlier' => $count(fn($i) => $i['decision'] === null && $i['earlier'] !== null),
            'booked' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'booked'),
            'current' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'current'),
            'not_applicable' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'not_applicable'),
            'by_kind' => ['medical' => $count(fn($i) => $i['kind'] === 'medical'), 'training' => $count(fn($i) => $i['kind'] === 'training')]] + $stats;
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        $regRows = array_map(fn($t) => ['id' => (int) $t['id'], 'id_employee' => (int) $t['id_employee'], 'employee' => $names[(int) $t['id_employee']] ?? null,
            'kind' => $t['kind'], 'kind_label' => self::TRAININGS[$t['kind']] ?? $t['kind'], 'title' => $t['title'], 'done_on' => $t['done_on'], 'valid_until' => $t['valid_until'],
            'note' => $t['note'], 'state' => $t['valid_until'] === null ? 'ok' : ((string) $t['valid_until'] < $today ? 'expired' : ((string) $t['valid_until'] <= $soon ? 'soon' : 'ok'))], $register);
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'month_start' => $month, 'month_label' => $label, 'soon_until' => $soon, 'settings' => $set,
            'sources' => $this->sources($employees),
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            // Registre : salariés actifs seulement (un salarié parti n'est plus suivi).
            'register' => array_values(array_filter($regRows, fn($r) => $r['employee'] !== null)),
            'employees' => array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($names), array_values($names)),
            'decisions' => self::DECISIONS, 'trainings' => self::TRAININGS, 'signal_labels' => self::SIGNALS, 'links' => self::links(['hr', 'hr_docs']),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::d((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    private function sources(?array $employees): array
    {
        $hr = $this->repo->hasTable('n_hr_employee');
        return [
            'employees' => ['available' => $employees !== null && $employees !== [], 'note' => $employees === null ? 'brak danych — brak tabeli pracowników' : ($employees === [] ? 'brak aktywnych pracowników' : null)],
            'hr' => ['available' => $hr, 'note' => $hr ? null : 'brak danych — brak tabeli HR : każda data badań « pusta »'],
        ];
    }

    private function presentDecision(array $d): array
    {
        $flags = array_values(array_filter(explode(',', (string) $d['flags'])));
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'], 'flags' => $flags,
            'booked_on' => $d['booked_on'] !== null ? substr((string) $d['booked_on'], 0, 10) : null,
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'by' => $d['decided_by'] ?? null, 'at' => self::d(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un élément (une par mois, la dernière compte). « Aktualne — sprawdzone » n'est acceptée que si le
     * signal a disparu ; « Umówione » demande la date (d'aujourd'hui à un an) ; « Nie dotyczy » une note. Rien n'est écrit
     * dans HR, rien n'est envoyé.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : umówione, aktualne — sprawdzone albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        // Date corrigée dans HR depuis l'affichage : l'élément n'est plus signalé, « aktualne » s'enregistre avec les signaux vus.
        if ($it === null && $decision === 'current' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['codes'] === []) $it = $d['all'][$key];
        if ($it === null) throw new CustomException('Tego terminu nie ma na liście (bez sygnału w tym miesiącu).', 'errors', 422);
        $booked = null;
        if ($decision === 'booked') {
            $booked = self::dateIn($in['booked_on'] ?? null);
            if ($booked === null) throw new CustomException('Umówione : wpisz datę ' . ($it['kind'] === 'medical' ? 'badań' : 'szkolenia') . ' (RRRR-MM-DD).', 'errors', 422);
            if ($booked < $today || $booked > date('Y-m-d', strtotime($today . ' +1 year'))) throw new CustomException('Umówione : data od dziś do roku naprzód.', 'errors', 422);
        }
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. długie zwolnienie, umowa kończy się).', 'errors', 422);
        if ($decision === 'current' && $it['codes'] !== []) {
            throw new CustomException('Nadal : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c], $it['codes'])) . '. '
                . ($it['kind'] === 'medical' ? 'Wpisz nową datę badań w HR' : 'Dodaj szkolenie w rejestrze') . ' i zapisz ponownie — albo « Umówione ».', 'errors', 422);
        }
        if ($decision !== 'current' && $it['codes'] === []) throw new CustomException('Ten termin jest aktualny — wybierz « Aktualne — sprawdzone ».', 'errors', 422);
        $flags = $it['codes'] ?: ($seen ?: ($it['decision']['flags'] ?? []));
        $this->repo->saveDecision($d['month_start'], $key, $it['kind'], (int) $it['id_employee'], $decision, $booked, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /**
     * Krok 1 / 2 : szkolenie au registre (salarié actif, type, fait le — obligatoire, pas dans le futur —, valable jusqu'au —
     * vide = sans échéance, après la date du szkolenie). Si l'élément signalé n'a plus de signal, « Aktualne — sprawdzone »
     * est enregistrée avec les signaux qu'il avait.
     */
    public function saveTraining(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $emp = (int) ($in['id_employee'] ?? 0);
        $kind = (string) ($in['kind'] ?? '');
        $d = $this->data($today, true);
        if (!in_array($emp, array_column($d['employees'], 'id'), true)) throw new CustomException('Wybierz aktywnego pracownika.', 'errors', 422);
        if (!isset(self::TRAININGS[$kind])) throw new CustomException('Wybierz rodzaj szkolenia.', 'errors', 422);
        $title = trim((string) ($in['title'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($title) > 160 || mb_strlen($note) > 255) throw new CustomException('Nazwa : najwyżej 160 znaków, notatka : 255.', 'errors', 422);
        if ($kind === 'other' && mb_strlen($title) < 3) throw new CustomException('Szkolenie « Inne » : wpisz nazwę.', 'errors', 422);
        $done = self::dateIn($in['done_on'] ?? null);
        if ($done === null) throw new CustomException('Wpisz datę szkolenia (RRRR-MM-DD).', 'errors', 422);
        if ($done > $today) throw new CustomException('Data szkolenia nie może być w przyszłości — termin umówiony zapisz jako « Umówione ».', 'errors', 422);
        $rawUntil = trim((string) ($in['valid_until'] ?? ''));
        $until = $rawUntil === '' ? null : self::dateIn($rawUntil);
        if ($rawUntil !== '' && $until === null) throw new CustomException('Ważne do : data RRRR-MM-DD albo puste (bezterminowe).', 'errors', 422);
        if ($until !== null && $until <= $done) throw new CustomException('Ważne do : data po dacie szkolenia.', 'errors', 422);
        $key = 'training:' . $emp . ':' . $kind;
        $before = $d['all'][$key]['codes'] ?? [];
        $this->repo->addTraining($emp, $kind, $title !== '' ? $title : null, $done, $until, $note !== '' ? $note : null, $userId);
        return $this->autoComplete($key, $before, $userId, $today);
    }

    /** Wpis błędny : retiré du registre (désactivé). */
    public function removeTraining(array $in, ?int $userId, ?string $today = null): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0 || $this->repo->training($id) === null) throw new CustomException('Nie ma takiego wpisu w rejestrze szkoleń.', 'errors', 404);
        $this->repo->removeTraining($id, $userId);
        return $this->data($today ?? date('Y-m-d'));
    }

    /** Après une saisie : si l'élément signalé n'a plus de signal, « Aktualne — sprawdzone » avec les signaux qu'il avait. */
    private function autoComplete(string $key, array $before, ?int $userId, string $today): array
    {
        $d = $this->data($today, true);
        $it = $d['all'][$key] ?? null;
        if ($it !== null && $before !== [] && $it['codes'] === []) {
            $this->repo->saveDecision($d['month_start'], $key, $it['kind'], (int) $it['id_employee'], 'current', null, null, implode(',', $before), $userId);
            return $this->data($today);
        }
        unset($d['all']);
        return $d;
    }

    /** Krok 3 : « Zakończ » — chaque élément signalé a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'terminu', 'terminów', 'terminów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : 12 pracowników · badania 1 po terminie · 2 bez daty · 1 wkrótce · szkolenia 3 bez BHP · decyzje : 2 umówione · 1 aktualne » */
    public static function summary(string $month, array $t): string
    {
        $s = $month . ' : ' . $t['employees'] . ' ' . self::pl($t['employees'], 'pracownik', 'pracowników', 'pracowników');
        $med = array_filter([$t['medical_expired'] ? $t['medical_expired'] . ' po terminie' : null, $t['medical_missing'] ? $t['medical_missing'] . ' bez daty' : null,
            $t['medical_soon'] ? $t['medical_soon'] . ' wkrótce' : null]);
        $tr = array_filter([$t['training_expired'] ? $t['training_expired'] . ' po terminie' : null, $t['training_missing'] ? $t['training_missing'] . ' bez BHP' : null,
            $t['training_soon'] ? $t['training_soon'] . ' wkrótce' : null]);
        $s .= ' · badania ' . ($med ? implode(' · ', $med) : 'aktualne') . ' · szkolenia ' . ($tr ? implode(' · ', $tr) : 'aktualne');
        $dec = array_filter([$t['booked'] ? $t['booked'] . ' umówione' : null, $t['current'] ? $t['current'] . ' aktualne' : null, $t['not_applicable'] ? $t['not_applicable'] . ' nie dotyczy' : null]);
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    private static function links(array $keys): array
    {
        return array_map(fn($k) => ['href' => self::LINKS[$k][0], 'label' => self::LINKS[$k][1]], $keys);
    }

    private static function sig(string $code, ?string $detail = null): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
    }

    /** Date AAAA-MM-JJ valide, sinon null. */
    public static function dateIn(mixed $v): ?string
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        return $v;
    }

    /** « (za 12 dni) », « (5 dni temu) », « (dziś) ». */
    private static function left(string $until, string $today): string
    {
        $n = (int) round((strtotime($until) - strtotime($today)) / 86400);
        return $n === 0 ? ' (dziś)' : ($n > 0 ? ' (za ' . $n . ' ' . self::pl($n, 'dzień', 'dni', 'dni') . ')' : ' (' . -$n . ' ' . self::pl(-$n, 'dzień', 'dni', 'dni') . ' temu)');
    }

    private static function d(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : date('d.m.Y', $t);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
