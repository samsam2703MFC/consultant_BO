<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\MaintenanceRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Serwisy i przeglądy maszyn (przegląd miesiąca) » [405] (co miesiąc, utilisable à tout moment ; zakres obowiązków
 * « produkcja » 237 : Kierownik produkcji 10.1, 10.2, 10.3, 10.6). Mois du contrôle = le mois de la tâche (état des registres
 * aujourd'hui) ; les arrêts « awaria » examinés sont ceux depuis le début du mois écoulé.
 *   1. Przegląd : registres de l'assistant (urządzenia, przeglądy / serwisy / pomiary avec terme, zgłoszenia) et formulaires
 *      d'ajout ; éléments signalés :
 *        - check (n_equipment_check) : check_overdue (terme dépassé), check_soon (dans maintenance.alert_days jours),
 *          check_no_date (sans prochain terme) ;
 *        - ticket (n_equipment_ticket ouvert / zlecony) : ticket_overdue (termin realizacji dépassé), ticket_no_due (sans termin) ;
 *        - stop (n_prod_ws_state, arrêt « awaria » de la tablette, au moins maintenance.breakdown_min_minutes minutes) :
 *          stop_no_ticket — aucun zgłoszenie lié (id_ws_state), ni sur la même machine (directement ou par son urządzenie)
 *          déclaré de la veille à 7 jours après le début de l'arrêt ;
 *        - machine (n_prod_workstation active, hors stanowisko ręczne) : machine_unregistered — aucun urządzenie actif lié.
 *   2. Do decyzji : une décision par élément (n_maintenance_review, par mois) — Umówione — termin (date d'aujourd'hui à un an ;
 *      pour un zgłoszenie, devient son termin realizacji ; reprise jusqu'à cette date), Załatwione (seulement sans signal :
 *      enregistrée seule après la saisie — wykonanie zapisane, zgłoszenie zamknięte, zgłoszenie utworzone, maszyna w rejestrze),
 *      Nie dotyczy (note obligatoire ; reprise tant que les signaux sont les mêmes ; un arrêt décidé ne revient plus).
 *   3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un élément attend ; la tâche du mois se valide seule.
 * N'écrit que les tables de l'assistant (n_equipment, n_equipment_check, n_equipment_ticket, n_maintenance_review) et
 * n_wizard_done : aucune machine ni aucun arrêt de l'ERP modifié, rien de commandé ni d'envoyé.
 */
final class MaintenanceService
{
    public const CODE = 'maintenance_reviewed';
    public const PATH = '/maintenance';
    public const DECISIONS = ['booked' => 'Umówione — termin', 'done' => 'Załatwione', 'not_applicable' => 'Nie dotyczy'];
    public const KINDS = ['check' => 'Przegląd / serwis', 'ticket' => 'Zgłoszenie', 'stop' => 'Awaria z tabletu', 'machine' => 'Maszyna bez rejestru'];
    public const KIND_DECISIONS = ['check' => ['booked', 'done', 'not_applicable'], 'ticket' => ['booked', 'done', 'not_applicable'], 'stop' => ['done', 'not_applicable'], 'machine' => ['done', 'not_applicable']];
    /** Signaux, dans l'ordre de priorité. */
    public const SIGNALS = ['check_overdue' => 'przegląd / serwis po terminie', 'ticket_overdue' => 'zgłoszenie po terminie realizacji', 'stop_no_ticket' => 'awaria z tabletu bez zgłoszenia',
        'check_no_date' => 'brak następnego terminu', 'ticket_no_due' => 'zgłoszenie bez terminu realizacji', 'check_soon' => 'termin wkrótce',
        'machine_unregistered' => 'maszyna bez wpisu w rejestrze urządzeń'];
    public const EQUIPMENT = ['machine' => 'Maszyna', 'cold' => 'Chłodnia / mroźnia', 'scale' => 'Waga', 'fire' => 'Sprzęt ppoż.', 'electrical' => 'Instalacja elektryczna',
        'vehicle' => 'Pojazd', 'other' => 'Inne'];
    public const CHECKS = ['service' => 'Serwis okresowy', 'inspection' => 'Przegląd', 'measurement' => 'Pomiar', 'calibration' => 'Legalizacja / wzorcowanie', 'udt' => 'Badanie UDT', 'other' => 'Inne'];
    public const TICKET_KINDS = ['breakdown' => 'Awaria', 'service' => 'Zlecony serwis'];
    public const STATUSES = ['open' => 'otwarte', 'ordered' => 'zlecone', 'done' => 'zakończone', 'cancelled' => 'anulowane'];
    /** Types de n_prod_workstation suivis comme machines (le stanowisko ręczne n'a rien à serwisować). */
    public const MACHINE_TYPES = ['machine' => 'maszyna', 'four' => 'piec', 'robot' => 'robot', 'fermentation' => 'komora rozrostu', 'froid' => 'chłodzenie', 'surgelation' => 'mrożenie'];
    public const LINKS = ['capacity' => ['/production/capacity', 'Moce produkcyjne (maszyny)'], 'costs' => ['/finances/costs', 'Koszty usług miesiąca']];
    public const SETTINGS = ['alert_days' => 'maintenance.alert_days', 'breakdown_min_minutes' => 'maintenance.breakdown_min_minutes'];
    private const DEFAULTS = ['alert_days' => 30, 'breakdown_min_minutes' => 15];

    public function __construct(private MaintenanceRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Premier jour du mois du contrôle (clé des décisions et de l'état de la tâche du mois). */
    public static function reviewMonth(string $today): string
    {
        return substr($today, 0, 7) . '-01';
    }

    /** Réglages (valeur hors bornes → valeur par défaut). */
    public function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $k => $code) {
            $raw = trim($this->params->get($code, (string) self::DEFAULTS[$k]));
            $out[$k] = ParamRules::rangeError($code, $raw) === null ? (int) $raw : self::DEFAULTS[$k];
        }
        return $out;
    }

    /** Données des trois étapes ($withAll : aussi les éléments sans signal — pour enregistrer « załatwione »). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewMonth($today);
        $from = date('Y-m-01', strtotime($month . ' -1 month'));
        $set = $this->settings();
        $soon = date('Y-m-d', strtotime($today . ' +' . $set['alert_days'] . ' days'));
        $now = $today === date('Y-m-d') ? date('Y-m-d H:i:s') : $today . ' 23:59:59';
        $own = $this->repo->hasTable('n_equipment');
        $ws = $this->repo->workstations();
        $wsById = array_column($ws ?? [], null, 'id');
        $equipment = $own ? $this->repo->equipment() : [];
        $eqById = array_column($equipment, null, 'id');
        $checks = $own ? $this->repo->checks() : [];
        $tickets = $own ? $this->repo->tickets($from) : [];
        $stops = $this->repo->breakdowns($from);
        $linked = $own ? $this->repo->linkedStops() : [];
        $wsName = fn(?int $id) => $id !== null && isset($wsById[$id]) ? (string) $wsById[$id]['name_pl'] : null;
        $eqName = fn(array $e) => (string) $e['name'] . ($e['id_workstation'] !== null && ($n = $wsName((int) $e['id_workstation'])) && $n !== $e['name'] ? ' (' . $n . ')' : '');
        $items = [];
        // Éléments décidés ce mois : restent à l'écran même sans signal (zgłoszenie fermé, machine enregistrée depuis).
        $decided = array_flip($this->repo->decidedIn($month));

        $stats = ['equipment' => count($equipment), 'checks' => count($checks), 'check_overdue' => 0, 'check_soon' => 0, 'check_no_date' => 0, 'tickets_open' => 0, 'ticket_overdue' => 0,
            'ticket_no_due' => 0, 'stops' => 0, 'stop_no_ticket' => 0, 'machines' => 0, 'machine_unregistered' => 0];
        foreach ($checks as $c) {
            $id = (int) $c['id'];
            $e = $eqById[(int) $c['id_equipment']];
            $next = $c['next_due'] !== null ? substr((string) $c['next_due'], 0, 10) : null;
            $codes = $next === null ? ['check_no_date'] : ($next < $today ? ['check_overdue'] : ($next <= $soon ? ['check_soon'] : []));
            foreach ($codes as $k) $stats[$k]++;
            $detail = $next === null ? 'wpisz następny termin albo okres (co ile miesięcy)' : 'termin ' . self::d($next) . self::left($next, $today);
            $items[] = ['key' => 'check:' . $id, 'kind' => 'check', 'kind_label' => self::KINDS['check'], 'source_id' => $id, 'title' => $eqName($e),
                'sub' => implode(' · ', array_filter([self::CHECKS[$c['kind']] . ($c['title'] ? ' — ' . $c['title'] : ''), $c['interval_months'] ? 'co ' . (int) $c['interval_months'] . ' mies.' : null,
                    $c['last_done'] ? 'ostatnio ' . self::d(substr((string) $c['last_done'], 0, 10)) : 'brak ostatniego wykonania', $c['provider'] ?: null])),
                'interval_months' => $c['interval_months'] !== null ? (int) $c['interval_months'] : null, 'next_due' => $next,
                'codes' => $codes, 'signals' => array_map(fn($k) => self::sig($k, $detail), $codes), 'links' => []];
        }
        foreach ($tickets as $t) {
            $id = (int) $t['id'];
            $open = in_array($t['status'], ['open', 'ordered'], true);
            if (!$open && !isset($decided['ticket:' . $id])) continue;
            if ($open) $stats['tickets_open']++;
            $due = $t['due_date'] !== null ? substr((string) $t['due_date'], 0, 10) : null;
            $codes = !$open ? [] : ($due === null ? ['ticket_no_due'] : ($due < $today ? ['ticket_overdue'] : []));
            foreach ($codes as $k) $stats[$k]++;
            $what = $t['id_equipment'] !== null && isset($eqById[(int) $t['id_equipment']]) ? $eqName($eqById[(int) $t['id_equipment']]) : $wsName($t['id_workstation'] !== null ? (int) $t['id_workstation'] : null);
            $items[] = ['key' => 'ticket:' . $id, 'kind' => 'ticket', 'kind_label' => self::KINDS['ticket'], 'source_id' => $id, 'title' => (string) $t['title'],
                'sub' => implode(' · ', array_filter([self::TICKET_KINDS[$t['kind']], $what, 'zgłoszone ' . self::d(substr((string) $t['reported_on'], 0, 10)), $t['provider'] ?: null, self::STATUSES[$t['status']]])),
                'status' => $t['status'], 'due_date' => $due,
                'codes' => $codes, 'signals' => array_map(fn($k) => self::sig($k, $due === null ? 'wpisz termin uzgodniony z firmą' : 'termin ' . self::d($due) . self::left($due, $today)), $codes), 'links' => []];
        }
        foreach ($stops ?? [] as $s) {
            $id = (int) $s['id'];
            $wsId = (int) $s['id_workstation'];
            $start = (string) $s['started_at'];
            $min = (int) floor((strtotime((string) ($s['ended_at'] ?? $now)) - strtotime($start)) / 60);
            if ($min < $set['breakdown_min_minutes']) continue;
            $stats['stops']++;
            $day = substr($start, 0, 10);
            $lo = date('Y-m-d', strtotime($day . ' -1 day'));
            $hi = date('Y-m-d', strtotime($day . ' +7 days'));
            $covered = isset($linked[$id]) || array_filter($tickets, fn($t) => ((int) ($t['id_workstation'] ?? 0) === $wsId
                    || ($t['id_equipment'] !== null && isset($eqById[(int) $t['id_equipment']]) && (int) ($eqById[(int) $t['id_equipment']]['id_workstation'] ?? 0) === $wsId))
                && (string) $t['reported_on'] >= $lo && (string) $t['reported_on'] <= $hi) !== [];
            $codes = $covered ? [] : ['stop_no_ticket'];
            if (!$covered) $stats['stop_no_ticket']++;
            $items[] = ['key' => 'stop:' . $id, 'kind' => 'stop', 'kind_label' => self::KINDS['stop'], 'source_id' => $id, 'id_workstation' => $wsId,
                'title' => $wsName($wsId) ?? 'maszyna #' . $wsId,
                'sub' => 'postój « awaria » ' . self::d($day) . ', ' . self::hm($start) . ' · ' . self::duration($min) . ($s['ended_at'] === null ? ' (trwa)' : '') . ($s['note'] ? ' · ' . $s['note'] : ''),
                'started_on' => $day, 'codes' => $codes, 'signals' => array_map(fn($k) => self::sig($k, 'brak zgłoszenia od ' . self::d($lo) . ' do ' . self::d($hi)), $codes), 'links' => self::links(['capacity'])];
        }
        $registered = array_fill_keys(array_map('intval', array_filter(array_column($equipment, 'id_workstation'), fn($v) => $v !== null)), true);
        foreach ($ws ?? [] as $w) {
            if (!(int) $w['active'] || !isset(self::MACHINE_TYPES[$w['type']])) continue;
            $stats['machines']++;
            $reg = isset($registered[(int) $w['id']]);
            if (!$own || ($reg && !isset($decided['machine:' . (int) $w['id']]))) continue;
            if (!$reg) $stats['machine_unregistered']++;
            $items[] = ['key' => 'machine:' . (int) $w['id'], 'kind' => 'machine', 'kind_label' => self::KINDS['machine'], 'source_id' => (int) $w['id'], 'id_workstation' => (int) $w['id'],
                'title' => (string) $w['name_pl'], 'sub' => implode(' · ', array_filter([self::MACHINE_TYPES[$w['type']], $w['group_name'] ?? null, $w['code'] ?? null])),
                'codes' => $reg ? [] : ['machine_unregistered'], 'signals' => $reg ? [] : [self::sig('machine_unregistered', 'dodaj ją do rejestru z przeglądami albo zapisz « Nie dotyczy »')],
                'links' => self::links(['capacity'])];
        }

        // Décisions : celle du mois ; sinon une décision précédente qui tient encore (reprise) : « Nie dotyczy » pour les mêmes
        // signaux, « Umówione » jusqu'à sa date, tout arrêt déjà décidé. Un élément décidé ce mois reste à l'écran.
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
            $carried = $mine === null && $earlier !== null && $it['codes'] !== []
                && ($it['kind'] === 'stop' || ($earlier['decision'] === 'not_applicable' && $covers($earlier)) || $pending($earlier));
            $covered = $mine !== null && match ((string) $mine['decision']) { 'done' => $it['codes'] === [], 'booked' => $pending($mine) || $it['codes'] === [], default => $covers($mine) };
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = $carried ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covered && !$carried;
            $it['reason'] = null;
            if ($it['waiting'] && ($last = $mine ?? $earlier) !== null) {
                $it['reason'] = match ((string) $last['decision']) {
                    'booked' => 'termin umówiony ' . self::d(substr((string) $last['booked_on'], 0, 10)) . ' minął — zapisz wykonanie albo nowy termin',
                    'done' => 'sygnał wrócił po « Załatwione »',
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
            'done' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'done'),
            'not_applicable' => $count(fn($i) => ($d = $eff($i)) !== null && $d['code'] === 'not_applicable'),
            'by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $count(fn($i) => $i['kind'] === $k), array_keys(self::KINDS)))] + $stats;
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $done = $this->wizards->done(self::CODE, $month, date('Y-m-01', strtotime($month . ' +1 month')));
        $machines = array_values(array_map(fn($w) => ['id' => (int) $w['id'], 'name' => (string) $w['name_pl'], 'registered' => isset($registered[(int) $w['id']])],
            array_filter($ws ?? [], fn($w) => (int) $w['active'] && isset(self::MACHINE_TYPES[$w['type']]))));
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'month_start' => $month, 'month_label' => $label, 'stops_from' => $from, 'soon_until' => $soon, 'settings' => $set,
            'sources' => $this->sources($ws, $stops, $from),
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'register' => [
                'equipment' => array_map(fn($e) => ['id' => (int) $e['id'], 'name' => (string) $e['name'], 'kind' => $e['kind'], 'kind_label' => self::EQUIPMENT[$e['kind']],
                    'machine' => $wsName($e['id_workstation'] !== null ? (int) $e['id_workstation'] : null), 'location' => $e['location'],
                    'checks' => count(array_filter($checks, fn($c) => (int) $c['id_equipment'] === (int) $e['id']))], $equipment),
                'checks' => array_map(fn($c) => ['id' => (int) $c['id'], 'equipment' => $eqName($eqById[(int) $c['id_equipment']]), 'kind_label' => self::CHECKS[$c['kind']], 'title' => $c['title'],
                    'interval_months' => $c['interval_months'] !== null ? (int) $c['interval_months'] : null, 'last_done' => $c['last_done'], 'next_due' => $c['next_due'], 'provider' => $c['provider'],
                    'state' => $c['next_due'] === null ? 'none' : ((string) $c['next_due'] < $today ? 'overdue' : ((string) $c['next_due'] <= $soon ? 'soon' : 'ok'))], $checks),
                'tickets' => array_map(fn($t) => ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'kind_label' => self::TICKET_KINDS[$t['kind']], 'status' => $t['status'],
                    'status_label' => self::STATUSES[$t['status']], 'reported_on' => $t['reported_on'], 'due_date' => $t['due_date'], 'closed_on' => $t['closed_on'], 'provider' => $t['provider'],
                    'what' => $t['id_equipment'] !== null && isset($eqById[(int) $t['id_equipment']]) ? $eqName($eqById[(int) $t['id_equipment']]) : $wsName($t['id_workstation'] !== null ? (int) $t['id_workstation'] : null)], $tickets),
            ],
            'machines' => $machines,
            'decisions' => self::DECISIONS, 'kind_decisions' => self::KIND_DECISIONS, 'kind_labels' => self::KINDS, 'signal_labels' => self::SIGNALS,
            'equipment_kinds' => self::EQUIPMENT, 'check_kinds' => self::CHECKS, 'ticket_kinds' => self::TICKET_KINDS, 'statuses' => self::STATUSES,
            'links' => self::links(['capacity', 'costs']),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::d((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    private function sources(?array $ws, ?array $stops, string $from): array
    {
        return [
            'machines' => ['available' => $ws !== null && $ws !== [], 'note' => $ws === null ? 'brak danych — brak tabeli maszyn' : ($ws === [] ? 'brak maszyn w ERP' : null)],
            'stops' => ['available' => $stops !== null, 'note' => $stops === null ? 'brak danych — brak tabeli postojów TRS (tablet grupy)'
                : ($stops === [] ? 'brak postojów « awaria » zapisanych na tabletach od ' . self::d($from) : null)],
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
     * Krok 2 : décision pour un élément (une par mois, la dernière compte). « Załatwione » seulement sans signal ; « Umówione —
     * termin » demande la date (d'aujourd'hui à un an) et devient le termin realizacji d'un zgłoszenie ; « Nie dotyczy » une note.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : umówione, załatwione albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        if ($it === null && $decision === 'done' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['codes'] === []) $it = $d['all'][$key];
        if ($it === null) throw new CustomException('Tego elementu nie ma na liście (bez sygnału w tym miesiącu).', 'errors', 422);
        if (!in_array($decision, self::KIND_DECISIONS[$it['kind']], true)) {
            throw new CustomException('Dla « ' . self::KINDS[$it['kind']] . ' » wybierz : ' . implode(', ', array_map(fn($k) => self::DECISIONS[$k], self::KIND_DECISIONS[$it['kind']])) . '.', 'errors', 422);
        }
        $booked = null;
        if ($decision === 'booked') {
            $booked = StaffDeadlinesService::dateIn($in['booked_on'] ?? null);
            if ($booked === null) throw new CustomException('Umówione : wpisz datę (RRRR-MM-DD).', 'errors', 422);
            if ($booked < $today || $booked > date('Y-m-d', strtotime($today . ' +1 year'))) throw new CustomException('Umówione : data od dziś do roku naprzód.', 'errors', 422);
        }
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. maszyna wycofana, drobna awaria usunięta na miejscu).', 'errors', 422);
        if ($decision === 'done' && $it['codes'] !== []) {
            throw new CustomException('Nadal : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c], $it['codes'])) . '. ' . self::howTo($it['kind']) . ' — albo « Umówione » / « Nie dotyczy ».', 'errors', 422);
        }
        if ($decision !== 'done' && $it['codes'] === []) throw new CustomException('Ten element nie ma sygnału — wybierz « Załatwione ».', 'errors', 422);
        $flags = $it['codes'] ?: ($seen ?: ($it['decision']['flags'] ?? []));
        if ($decision === 'booked' && $it['kind'] === 'ticket') {
            $t = $this->repo->ticket((int) $it['source_id']);
            $this->repo->updateTicket((int) $t['id'], (string) $t['status'], $booked, $t['closed_on'], $userId);
        }
        $this->repo->saveDecision($d['month_start'], $key, $it['kind'], $decision, $booked, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Rejestr : nouvel urządzenie (machine de l'ERP ou autre équipement). Pour une machine signalée : « Załatwione » seule. */
    public function saveEquipment(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $name = trim((string) ($in['name'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        $location = trim((string) ($in['location'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $wsId = (int) ($in['id_workstation'] ?? 0) ?: null;
        $d = $this->data($today, true);
        if (!isset(self::EQUIPMENT[$kind])) throw new CustomException('Wybierz rodzaj urządzenia.', 'errors', 422);
        if ($wsId !== null) {
            $m = array_values(array_filter($d['machines'], fn($x) => $x['id'] === $wsId))[0] ?? null;
            if ($m === null) throw new CustomException('Nie ma takiej aktywnej maszyny w ERP.', 'errors', 422);
            if ($m['registered']) throw new CustomException('Ta maszyna jest już w rejestrze urządzeń.', 'errors', 422);
            if ($name === '') $name = $m['name'];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) throw new CustomException('Nazwa urządzenia : od 2 do 160 znaków.', 'errors', 422);
        if (mb_strlen($location) > 160 || mb_strlen($note) > 255) throw new CustomException('Miejsce : najwyżej 160 znaków, notatka : 255.', 'errors', 422);
        $key = $wsId !== null ? 'machine:' . $wsId : null;
        $before = $key !== null ? ($d['all'][$key]['codes'] ?? []) : [];
        $this->repo->addEquipment($name, $kind, $wsId, $location !== '' ? $location : null, $note !== '' ? $note : null, $userId);
        return $key !== null ? $this->autoComplete($key, $before, $userId, $today) : $this->data($today);
    }

    /**
     * Rejestr : przegląd / serwis / pomiar d'un urządzenie (co ile miesięcy, dernier fait, prochain terme — calculé depuis le
     * dernier fait et le période s'il est vide, firma).
     */
    public function saveCheck(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $eq = (int) ($in['id_equipment'] ?? 0);
        $kind = (string) ($in['kind'] ?? '');
        $title = trim((string) ($in['title'] ?? ''));
        $provider = trim((string) ($in['provider'] ?? ''));
        $d = $this->data($today);
        if (!in_array($eq, array_column($d['register']['equipment'], 'id'), true)) throw new CustomException('Wybierz urządzenie z rejestru.', 'errors', 422);
        if (!isset(self::CHECKS[$kind])) throw new CustomException('Wybierz rodzaj : serwis, przegląd, pomiar, legalizacja, UDT, inne.', 'errors', 422);
        if ($kind === 'other' && mb_strlen($title) < 3) throw new CustomException('Rodzaj « Inne » : wpisz nazwę.', 'errors', 422);
        if (mb_strlen($title) > 160 || mb_strlen($provider) > 160) throw new CustomException('Nazwa i firma : najwyżej 160 znaków.', 'errors', 422);
        $interval = self::interval($in['interval_months'] ?? null);
        $last = self::optDate($in['last_done'] ?? null, 'Ostatnio wykonane');
        if ($last !== null && $last > $today) throw new CustomException('Ostatnio wykonane : data nie może być w przyszłości.', 'errors', 422);
        $next = self::optDate($in['next_due'] ?? null, 'Następny termin') ?? ($last !== null && $interval !== null ? self::addMonths($last, $interval) : null);
        if ($next !== null && $last !== null && $next <= $last) throw new CustomException('Następny termin : data po ostatnim wykonaniu.', 'errors', 422);
        $this->repo->addCheck($eq, $kind, $title !== '' ? $title : null, $interval, $last, $next, $provider !== '' ? $provider : null, $userId);
        return $this->data($today);
    }

    /** Krok 2 : wykonanie d'un przegląd / serwis (date, prochain terme — sinon date + période). Sans signal : « Załatwione » seule. */
    public function checkDone(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($in['id'] ?? 0);
        $c = $id > 0 ? $this->repo->check($id) : null;
        if ($c === null) throw new CustomException('Nie ma takiego przeglądu w rejestrze.', 'errors', 404);
        $done = StaffDeadlinesService::dateIn($in['done_on'] ?? null);
        if ($done === null) throw new CustomException('Wpisz datę wykonania (RRRR-MM-DD).', 'errors', 422);
        if ($done > $today) throw new CustomException('Data wykonania nie może być w przyszłości — termin zapisz jako « Umówione ».', 'errors', 422);
        $interval = $c['interval_months'] !== null ? (int) $c['interval_months'] : null;
        $next = self::optDate($in['next_due'] ?? null, 'Następny termin') ?? ($interval !== null ? self::addMonths($done, $interval) : null);
        if ($next !== null && $next <= $done) throw new CustomException('Następny termin : data po dacie wykonania.', 'errors', 422);
        $key = 'check:' . $id;
        $before = $this->data($today, true)['all'][$key]['codes'] ?? [];
        $this->repo->checkDone($id, $done, $next, $userId);
        return $this->autoComplete($key, $before, $userId, $today);
    }

    /**
     * Zgłoszenie awarii ou serwis zlecony : urządzenie ou machine (ou arrêt TRS — sa machine), titre, firma, date de l'zgłoszenie
     * (aujourd'hui par défaut, pas dans le futur), termin realizacji (pas avant l'zgłoszenie), statut otwarte / zlecone. Depuis
     * un arrêt signalé : « Załatwione » seule. Rien n'est envoyé à la firma.
     */
    public function saveTicket(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today, true);
        $kind = (string) ($in['kind'] ?? 'breakdown');
        $title = trim((string) ($in['title'] ?? ''));
        $provider = trim((string) ($in['provider'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $status = (string) ($in['status'] ?? 'open');
        $eq = (int) ($in['id_equipment'] ?? 0) ?: null;
        $wsId = (int) ($in['id_workstation'] ?? 0) ?: null;
        $stop = (int) ($in['id_ws_state'] ?? 0) ?: null;
        if (!isset(self::TICKET_KINDS[$kind])) throw new CustomException('Wybierz : awaria albo zlecony serwis.', 'errors', 422);
        if (!in_array($status, ['open', 'ordered'], true)) throw new CustomException('Nowe zgłoszenie : otwarte albo zlecone.', 'errors', 422);
        if ($stop !== null) {
            $s = $d['all']['stop:' . $stop] ?? null;
            if ($s === null) throw new CustomException('Nie ma takiej awarii z tabletu w okresie przeglądu.', 'errors', 422);
            $wsId = $s['id_workstation'];
        }
        if ($eq !== null && !in_array($eq, array_column($d['register']['equipment'], 'id'), true)) throw new CustomException('Wybierz urządzenie z rejestru.', 'errors', 422);
        if ($eq === null && $wsId !== null && !in_array($wsId, array_column($d['machines'], 'id'), true) && $stop === null) throw new CustomException('Nie ma takiej aktywnej maszyny w ERP.', 'errors', 422);
        if ($eq === null && $wsId === null) throw new CustomException('Wybierz urządzenie albo maszynę.', 'errors', 422);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200) throw new CustomException('Opis zgłoszenia : od 3 do 200 znaków.', 'errors', 422);
        if (mb_strlen($provider) > 160 || mb_strlen($note) > 255) throw new CustomException('Firma : najwyżej 160 znaków, notatka : 255.', 'errors', 422);
        $reported = trim((string) ($in['reported_on'] ?? '')) === '' ? $today : self::optDate($in['reported_on'], 'Data zgłoszenia');
        if ($reported > $today) throw new CustomException('Data zgłoszenia nie może być w przyszłości.', 'errors', 422);
        $due = self::optDate($in['due_date'] ?? null, 'Termin realizacji');
        if ($due !== null && $due < $reported) throw new CustomException('Termin realizacji : nie przed datą zgłoszenia.', 'errors', 422);
        $key = $stop !== null ? 'stop:' . $stop : null;
        $before = $key !== null ? ($d['all'][$key]['codes'] ?? []) : [];
        $this->repo->addTicket(['id_equipment' => $eq, 'id_workstation' => $wsId, 'id_ws_state' => $stop, 'kind' => $kind, 'title' => $title, 'provider' => $provider !== '' ? $provider : null,
            'reported_on' => $reported, 'due_date' => $due, 'status' => $status, 'note' => $note !== '' ? $note : null], $userId);
        return $key !== null ? $this->autoComplete($key, $before, $userId, $today) : $this->data($today);
    }

    /** Statut d'un zgłoszenie (et termin) ; zakończone / anulowane → date de clôture (aujourd'hui par défaut). Sans signal : « Załatwione » seule. */
    public function ticketStatus(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($in['id'] ?? 0);
        $t = $id > 0 ? $this->repo->ticket($id) : null;
        if ($t === null) throw new CustomException('Nie ma takiego zgłoszenia.', 'errors', 404);
        $status = (string) ($in['status'] ?? '');
        if (!isset(self::STATUSES[$status])) throw new CustomException('Wybierz stan : otwarte, zlecone, zakończone, anulowane.', 'errors', 422);
        $due = array_key_exists('due_date', $in) ? self::optDate($in['due_date'], 'Termin realizacji') : ($t['due_date'] !== null ? substr((string) $t['due_date'], 0, 10) : null);
        if ($due !== null && $due < substr((string) $t['reported_on'], 0, 10)) throw new CustomException('Termin realizacji : nie przed datą zgłoszenia.', 'errors', 422);
        $closed = null;
        if (in_array($status, ['done', 'cancelled'], true)) {
            $closed = trim((string) ($in['closed_on'] ?? '')) === '' ? $today : self::optDate($in['closed_on'], 'Data zakończenia');
            if ($closed > $today) throw new CustomException('Data zakończenia nie może być w przyszłości.', 'errors', 422);
        }
        $key = 'ticket:' . $id;
        $before = $this->data($today, true)['all'][$key]['codes'] ?? [];
        $this->repo->updateTicket($id, $status, $due, $closed, $userId);
        return $this->autoComplete($key, $before, $userId, $today);
    }

    /** Après une saisie : si l'élément signalé n'a plus de signal (ou a disparu : zgłoszenie fermé), « Załatwione ». */
    private function autoComplete(string $key, array $before, ?int $userId, string $today): array
    {
        $d = $this->data($today, true);
        $it = $d['all'][$key] ?? null;
        if ($before !== [] && ($it === null || $it['codes'] === [])) {
            $this->repo->saveDecision($d['month_start'], $key, explode(':', $key)[0], 'done', null, null, implode(',', $before), $userId);
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
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'elementu', 'elementów', 'elementów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « listopad 2026 : przeglądy 1 po terminie · 1 wkrótce / 4 · zgłoszenia 1 po terminie / 2 otwarte · awarie bez zgłoszenia 1 / 3 · 1 maszyna bez rejestru · decyzje : … » */
    public static function summary(string $month, array $t): string
    {
        $c = array_filter([$t['check_overdue'] ? $t['check_overdue'] . ' po terminie' : null, $t['check_soon'] ? $t['check_soon'] . ' wkrótce' : null, $t['check_no_date'] ? $t['check_no_date'] . ' bez terminu' : null]);
        $s = $month . ' : przeglądy ' . ($c ? implode(' · ', $c) : 'w terminie') . ' / ' . $t['checks'];
        $k = array_filter([$t['ticket_overdue'] ? $t['ticket_overdue'] . ' po terminie' : null, $t['ticket_no_due'] ? $t['ticket_no_due'] . ' bez terminu' : null]);
        $s .= ' · zgłoszenia ' . ($k ? implode(' · ', $k) . ' / ' : '') . $t['tickets_open'] . ' ' . self::pl($t['tickets_open'], 'otwarte', 'otwarte', 'otwartych');
        $s .= ' · awarie bez zgłoszenia ' . $t['stop_no_ticket'] . ' / ' . $t['stops'];
        if ($t['machine_unregistered']) $s .= ' · ' . $t['machine_unregistered'] . ' ' . self::pl($t['machine_unregistered'], 'maszyna', 'maszyny', 'maszyn') . ' bez rejestru';
        $dec = array_filter([$t['booked'] ? $t['booked'] . ' umówione' : null, $t['done'] ? $t['done'] . ' załatwione' : null, $t['not_applicable'] ? $t['not_applicable'] . ' nie dotyczy' : null]);
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    private static function howTo(string $kind): string
    {
        return match ($kind) {
            'check' => 'Zapisz wykonanie (data) w kroku 2',
            'ticket' => 'Zamknij zgłoszenie albo wpisz termin realizacji',
            'stop' => 'Utwórz zgłoszenie dla tej awarii',
            default => 'Dodaj maszynę do rejestru urządzeń',
        };
    }

    /** « co ile miesięcy » : 1–120 ou vide. */
    private static function interval(mixed $v): ?int
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 120) throw new CustomException('Co ile miesięcy : liczba od 1 do 120 albo puste.', 'errors', 422);
        return (int) $v;
    }

    private static function optDate(mixed $v, string $label): ?string
    {
        if (trim((string) $v) === '') return null;
        return StaffDeadlinesService::dateIn($v) ?? throw new CustomException($label . ' : data RRRR-MM-DD albo puste.', 'errors', 422);
    }

    /** Date + n mois (fin de mois tenue : 31.01 + 1 → 28 / 29.02). */
    public static function addMonths(string $day, int $months): string
    {
        $d = new \DateTimeImmutable($day);
        $first = $d->modify('first day of this month')->modify('+' . $months . ' months');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $d->format('d'), (int) $first->format('t')))->format('Y-m-d');
    }

    private static function links(array $keys): array
    {
        return array_map(fn($k) => ['href' => self::LINKS[$k][0], 'label' => self::LINKS[$k][1]], $keys);
    }

    private static function sig(string $code, ?string $detail = null): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
    }

    private static function duration(int $min): string
    {
        return $min >= 60 ? intdiv($min, 60) . ' h ' . sprintf('%02d', $min % 60) . ' min' : $min . ' min';
    }

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
