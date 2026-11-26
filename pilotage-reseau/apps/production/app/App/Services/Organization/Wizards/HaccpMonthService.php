<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\HaccpMonthRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « HACCP miesiąca » [380] (co miesiąc ; obligations de la matrice 224 : Specjalista ds. jakości 1, Kierownik
 * produkcji 5, Brygadzista zmiany 4, Kierowca-dostawca 7). Mois examiné = le mois précédent (comme les assistants mensuels
 * nc_reviewed, material_costs_reviewed, month_closed) ; le mois en cours jusqu'à aujourd'hui est rattrapé dans la même
 * revue (ce qui est décidé maintenant ne revient pas le mois suivant).
 *   1. Przegląd — éléments signalés, avec leurs signaux :
 *        - haccp_untreated : rupture de la chaîne du froid non traitée (n_mgr_haccp_event.kind = 'cold_chain_break',
 *          treated = 0 — la règle COLD_CHAIN_UNTREATED de ManagerService::haccp), aussi celles d'avant le mois examiné sur
 *          haccp_month.backlog_months mois ;
 *        - haccp_unfavorable : contrôle officiel, audit indépendant ou autocontrôle au résultat défavorable (result) ;
 *        - haccp_below : audit indépendant sous le seuil du plan de l'année (n_mgr_plan.audit_threshold, règle
 *          AUDIT_BELOW_THRESHOLD) ;
 *        - sop_review_late : procédure en vigueur (n_sop.status = 'active') dont la date de revue (review_at) est passée ;
 *          sop_review_missing : en vigueur sans date de revue ;
 *        - sop_deviation_haccp / sop_deviation : exécution de SOP avec écart (n_sop_run.result = 'deviation' : valeur hors
 *          plage, SopService::run), selon qu'une étape en écart est une étape HACCP (n_sop_step.tag = 'haccp'). Le JSON de
 *          l'exécution ne garde pas l'étiquette : l'étape est retrouvée parmi les étapes actuelles de la procédure par sa
 *          position puis par son titre (étape renommée ou supprimée depuis → « nieznany », sans étiquette HACCP) ;
 *        - delivery_temp : incident « Temperatura » signalé à la livraison sur la tablette chauffeur
 *          (n_delivery_incident.kind = 'temperature') ;
 *        - [410] hala_chill_warm : schładzanie du tablet hali hors limites (n_hala_chill : au cœur au-dessus de la limite, ou
 *          mesuré / pas encore mesuré après le délai — limites copiées au départ, HalaService::chillState) ;
 *        - [409] hala_clean_missing : jour de production d'un groupe (avant aujourd'hui) avec des strefy sprzątania non
 *          faites (n_hala_clean_zone / n_hala_clean_record), groupe avec l'étape « Sprzątanie » ;
 *        - [432] cold_dirty : obchód chłodni (tablette qualité) — miejsce zapisane « Czysto i porządek ? Nie » avec photo
 *          (n_cold_visit_place.clean = 'no').
 *      Les événements HACCP non signalés du mois sont listés pour information. Source sans table, ou sans aucune ligne
 *      (personne n'y enregistre rien) → « brak danych ».
 *   2. Do wyjaśnienia : une décision par élément signalé (n_haccp_month_review, par mois examiné) — Wyjaśnione / zamknięte
 *      (note), Działanie korygujące (note obligatoire, responsable / poste optionnels comme [375]), Niezgodność (NC) (note ;
 *      lien vers le registre NC, aucune NC créée ici). Un événement, une exécution ou un incident décidé une fois ne revient
 *      plus ; une SOP en retard de revue revient chaque mois tant que la revue n'est pas faite dans « Procedury SOP ».
 *   3. Zatwierdź : nombres par type, tendance sur haccp_month.trend_months mois précédents, « Zakończ » écrit
 *      n_wizard_done — bloqué tant qu'un élément attend ; la tâche du mois se valide seule.
 * Lecture seule hors de n_haccp_month_review et n_wizard_done : aucun événement HACCP marqué « obsłużone », aucune SOP
 * changée, aucune NC créée, aucun e-mail ni SMS.
 */
final class HaccpMonthService
{
    public const CODE = 'haccp_month_reviewed';
    public const PATH = '/production/haccp-month';
    public const DECISIONS = ['explained' => 'Wyjaśnione / zamknięte', 'corrective' => 'Działanie korygujące', 'nc' => 'Niezgodność (NC)'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['corrective'];
    public const KINDS = ['haccp_event' => 'Zdarzenie HACCP', 'sop_review' => 'Przegląd SOP', 'sop_run' => 'Odchylenie SOP', 'delivery_temp' => 'Temperatura w dostawie',
        'hala_chill' => 'Schładzanie (tablet hali)', 'hala_clean' => 'Sprzątanie (tablet hali)', 'cold_clean' => 'Porządek w chłodni (obchód chłodni)'];
    /** Signaux, dans l'ordre de priorité. */
    public const SIGNALS = ['haccp_untreated' => 'nieobsłużone przerwanie łańcucha chłodniczego', 'haccp_unfavorable' => 'wynik negatywny',
        'haccp_below' => 'audyt poniżej progu', 'sop_deviation_haccp' => 'odchylenie na etapie HACCP', 'delivery_temp' => 'problem z temperaturą przy dostawie',
        'hala_chill_warm' => 'schładzanie poza limitem', 'sop_deviation' => 'odchylenie przy wykonaniu SOP', 'hala_clean_missing' => 'strefy niesprzątnięte',
        'cold_dirty' => 'nieporządek w chłodni',
        'sop_review_late' => 'przegląd SOP po terminie', 'sop_review_missing' => 'SOP bez daty przeglądu'];
    /** Libellés du module Kierownik produkcji (resources/lang/ui/pl/manager.json, haccp.*). */
    public const HACCP_KINDS = ['official_control' => 'Kontrola urzędowa', 'cold_chain_break' => 'Przerwanie łańcucha chłodniczego',
        'independent_audit' => 'Audyt niezależny', 'self_check' => 'Samokontrola'];
    private const RESULTS = ['favorable' => 'pozytywny', 'unfavorable' => 'negatywny'];
    /** Écrans existants : module Kierownik produkcji (HACCP), procédure, exécutions SOP, confirmations de livraison, registre NC. */
    public const HACCP_LINK = '/workshop/manager';
    public const SOP_RUNS_LINK = '/sop?tab=runs';
    public const DELIVERIES_LINK = '/logistics/deliveries';
    public const NC_LINK = '/purchasing/nc#new';
    /** [409] Réglage des strefy et des étapes des groupes (tablet hali). */
    public const HALA_LINK = '/production/hala';
    /** [432] Obchód chłodni : son résumé (dernier obchód, porządek) est dans Zakupy tygodnia ; la photo dans le suivi des lots. */
    public const COLD_LINK = '/workshop/buy';
    public const PHOTO_LINK = '/workshop/traceability/photo/';
    private const PARAMS = ['backlog' => ['haccp_month.backlog_months', 12], 'trend' => ['haccp_month.trend_months', 3]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private HaccpMonthRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Réglage haccp_month.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Premier jour du mois examiné : le mois qui précède celui de $today. */
    public static function reviewedMonth(string $today): string
    {
        return date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    }

    /** Données des trois étapes. */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = self::reviewedMonth($today);
        $cur = substr($today, 0, 7) . '-01';
        $last = self::addDays($cur, -1);
        $backlog = $this->param('backlog');
        $trendMonths = $this->param('trend');
        $backlogFrom = date('Y-m-01', strtotime($from . ' -' . $backlog . ' months'));
        $period = fn(?string $d) => $d === null ? 'state' : ($d < $from ? 'backlog' : ($d < $cur ? 'month' : 'current'));

        $items = [];
        $thresholds = $this->repo->auditThresholds();
        $events = $this->repo->haccpEvents($from, $today, $backlogFrom);
        $info = [];
        foreach ($events ?? [] as $e) {
            $it = $this->haccpItem($e, $thresholds, $period);
            if ($it['signals']) $items[] = $it;
            elseif ($it['period'] !== 'backlog') $info[] = $it;
        }
        $sops = $this->repo->activeSops();
        foreach ($sops ?? [] as $s) if ($it = $this->sopReviewItem($s, $today)) $items[] = $it;
        $runs = $this->repo->deviationRuns($from, $today);
        $steps = $this->repo->sopSteps(array_column($runs ?? [], 'id_sop'));
        foreach ($runs ?? [] as $r) $items[] = $this->sopRunItem($r, $steps[(int) $r['id_sop']] ?? [], $period);
        $temps = $this->repo->temperatureIncidents($from, $today);
        foreach ($temps ?? [] as $t) $items[] = $this->tempItem($t, $period);
        $chills = $this->repo->halaChills($from, $today);
        foreach ($chills ?? [] as $c) if ($it = $this->chillItem($c, $period)) $items[] = $it;
        // Sprzątanie : jours terminés seulement (aujourd'hui, la journée est en cours).
        $cleaning = $this->repo->halaCleaningGaps($from, self::addDays($today, -1));
        foreach ($cleaning ?? [] as $c) $items[] = $this->cleanItem($c, $period);
        // [432] Obchód chłodni : porządek « Nie » (photo).
        foreach ($this->repo->coldDirty($from, $today) ?? [] as $c) $items[] = $this->coldItem($c, $period);

        // Décisions : celle du mois examiné ; sinon, pour un élément ponctuel, une décision d'une revue précédente.
        $dec = $this->repo->decisions(array_column($items, 'key'));
        foreach ($items as &$it) {
            $mine = null;
            $earlier = null;
            foreach ($dec[$it['key']] ?? [] as $d) {
                if (substr((string) $d['month_start'], 0, 10) === $from) { $mine ??= $d; continue; }
                if ($it['kind'] !== 'sop_review') $earlier ??= $d;
            }
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['earlier'] = !$mine && $earlier ? $this->presentDecision($earlier) : null;
            $it['waiting'] = $it['decision'] === null && $it['earlier'] === null;
        }
        unset($it);
        $rank = array_flip(array_keys(self::SIGNALS));
        $pRank = ['backlog' => 0, 'state' => 1, 'month' => 2, 'current' => 3];
        usort($items, fn($a, $b) => [$rank[$a['signals'][0]['code']], $pRank[$a['period']], $a['date'] ?? '', $a['key']] <=> [$rank[$b['signals'][0]['code']], $pRank[$b['period']], $b['date'] ?? '', $b['key']]);

        $sources = $this->sources($events, $sops, $runs, $temps);
        $kindCount = fn(array $list, string $k) => count(array_filter($list, fn($i) => $i['kind'] === $k));
        $monthItems = array_values(array_filter($items, fn($i) => $i['period'] === 'month'));
        $eff = fn(array $i) => $i['decision'] ?? $i['earlier'];
        $decided = fn(string $k) => count(array_filter($items, fn($i) => ($d = $eff($i)) !== null && $d['code'] === $k));
        $runsMonth = array_values(array_filter($items, fn($i) => $i['kind'] === 'sop_run' && $i['period'] === 'month'));
        $eventsMonth = array_values(array_filter($events ?? [], fn($e) => $period(substr((string) $e['event_date'], 0, 10)) === 'month'));
        $totals = ['review' => count($items), 'waiting' => count(array_filter($items, fn($i) => $i['waiting'])),
            'resolved' => count(array_filter($items, fn($i) => !$i['waiting'])), 'earlier' => count(array_filter($items, fn($i) => $i['decision'] === null && $i['earlier'] !== null)),
            'explained' => $decided('explained'), 'corrective' => $decided('corrective'), 'nc' => $decided('nc'),
            'by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $kindCount($items, $k), array_keys(self::KINDS))),
            'month_by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $k === 'sop_review' ? $kindCount($items, $k) : $kindCount($monthItems, $k), array_keys(self::KINDS))),
            'current' => count(array_filter($items, fn($i) => $i['period'] === 'current')), 'backlog' => count(array_filter($items, fn($i) => $i['period'] === 'backlog')),
            'month_events' => count($eventsMonth), 'month_runs_haccp' => count(array_filter($runsMonth, fn($i) => $i['haccp'])),
            'sops_active' => $sops === null ? null : count($sops)];
        $done = $this->wizards->done(self::CODE, $cur, date('Y-m-01', strtotime($cur . ' +1 month')));
        return ['today' => $today, 'label' => self::label($today), 'month_start' => $from, 'month_end' => $last, 'month_key' => substr($from, 0, 7),
            'month_label' => TaskWizards::monthLabel(substr($from, 0, 7)), 'current_label' => TaskWizards::monthLabel(substr($cur, 0, 7)) . ' (do ' . self::label($today) . ')',
            'backlog_months' => $backlog, 'trend_months' => $trendMonths, 'sources' => $sources,
            'review' => $items, 'info' => $info, 'totals' => $totals, 'trend' => $this->trend($from, $cur, $trendMonths, $sources),
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary(TaskWizards::monthLabel(substr($from, 0, 7)), $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => self::SIGNALS, 'kind_labels' => self::KINDS,
            'users' => $this->wizards->users(), 'positions' => $this->repo->positions(), 'nc_link' => self::NC_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Événement HACCP : signaux selon les règles du module Kierownik produkcji. */
    private function haccpItem(array $e, array $thresholds, callable $period): array
    {
        $date = substr((string) $e['event_date'], 0, 10);
        $kind = (string) $e['kind'];
        $kl = self::HACCP_KINDS[$kind] ?? $kind;
        $treated = (bool) (int) $e['treated'];
        $score = $e['score'] !== null ? round((float) $e['score'], 2) : null;
        $thr = $thresholds[(int) substr($date, 0, 4)] ?? null;
        $authority = trim((string) ($e['authority'] ?? ''));
        $signals = [];
        if ($kind === 'cold_chain_break' && !$treated) $signals[] = self::sig('haccp_untreated', $kl . ' ' . self::label($date) . ' — nieobsłużone w module Kierownik produkcji');
        if ($e['result'] === 'unfavorable') $signals[] = self::sig('haccp_unfavorable', $kl . ($authority !== '' ? ' (' . $authority . ')' : '') . ' ' . self::label($date) . ' — wynik negatywny');
        if ($kind === 'independent_audit' && $score !== null && $thr !== null && $score < $thr) {
            $signals[] = self::sig('haccp_below', 'wynik ' . self::num($score) . ' % — poniżej progu ' . self::num($thr) . ' % (plan ' . substr($date, 0, 4) . ')');
        }
        $desc = trim((string) ($e['description'] ?? ''));
        $parts = array_values(array_filter([$authority !== '' ? $authority : null, $e['result'] ? 'wynik ' . (self::RESULTS[$e['result']] ?? $e['result']) : null,
            $score !== null ? self::num($score) . ' %' : null, $kind === 'cold_chain_break' ? ($treated ? 'obsłużone' . ($e['treated_at'] ? ' ' . self::label((string) $e['treated_at']) : '') : 'NIEOBSŁUŻONE') : null]));
        return ['key' => 'haccp:' . (int) $e['id'], 'kind' => 'haccp_event', 'kind_label' => self::KINDS['haccp_event'], 'source_id' => (int) $e['id'],
            'date' => $date, 'date_label' => self::label($date) . ($period($date) === 'backlog' ? '.' . substr($date, 0, 4) : ''), 'period' => $period($date),
            'title' => $kl, 'sub' => implode(' · ', $parts), 'text' => $desc !== '' ? mb_substr($desc, 0, 200) : null, 'by' => $e['created_by_name'] ?: null,
            'event_kind' => $kind, 'treated' => $treated, 'haccp' => true, 'signals' => $signals,
            'link' => ['href' => self::HACCP_LINK, 'label' => 'Kierownik produkcji › HACCP']];
    }

    /** Procédure en vigueur : revue dépassée (ou sans date). null = rien à signaler. */
    private function sopReviewItem(array $s, string $today): ?array
    {
        $at = $s['review_at'] !== null && (string) $s['review_at'] !== '' ? substr((string) $s['review_at'], 0, 10) : null;
        if ($at !== null && $at >= $today) return null;
        $late = $at !== null ? self::days($at, $today) : null;
        $sig = $at === null ? self::sig('sop_review_missing', 'obowiązuje (v' . (int) $s['version'] . ') bez daty przeglądu')
            : self::sig('sop_review_late', 'przegląd do ' . self::label($at) . '.' . substr($at, 0, 4) . ' — ' . $late . ' ' . self::pl($late, 'dzień', 'dni', 'dni') . ' po terminie (co ' . (int) $s['review_months'] . ' mies.)');
        return ['key' => 'sop_review:' . (int) $s['id'] . ':' . ($at ?? '-'), 'kind' => 'sop_review', 'kind_label' => self::KINDS['sop_review'], 'source_id' => (int) $s['id'],
            'date' => $at, 'date_label' => $at !== null ? self::label($at) . '.' . substr($at, 0, 4) : '—', 'period' => 'state',
            'title' => $s['code'] . ' ' . $s['title'], 'sub' => trim(($s['area'] ? $s['area'] . ' · ' : '') . 'wersja ' . (int) $s['version'] . ((int) $s['haccp_steps'] ? ' · ' . (int) $s['haccp_steps'] . ' ' . self::pl((int) $s['haccp_steps'], 'etap', 'etapy', 'etapów') . ' HACCP' : '')),
            'text' => null, 'by' => null, 'late_days' => $late, 'haccp' => (int) $s['haccp_steps'] > 0, 'signals' => [$sig],
            'link' => ['href' => '/sop/' . (int) $s['id'] . '/edit', 'label' => 'Procedura — przegląd i zatwierdzenie']];
    }

    /** Exécution avec écart : étapes hors plage, étiquette HACCP retrouvée dans les étapes actuelles. */
    private function sopRunItem(array $r, array $current, callable $period): array
    {
        $date = substr((string) $r['finished_at'], 0, 10);
        $steps = json_decode((string) $r['steps'], true);
        $dev = [];
        $haccp = false;
        foreach (is_array($steps) ? $steps : [] as $s) {
            if (!is_array($s) || ($s['ok'] ?? true)) continue;
            $n = (int) ($s['step'] ?? 0);
            $title = (string) ($s['title'] ?? '');
            $match = isset($current[$n - 1]) && $current[$n - 1]['title'] === $title ? $current[$n - 1] : (array_values(array_filter($current, fn($c) => $c['title'] === $title))[0] ?? null);
            $tag = $match['tag'] ?? null;
            if ($tag === 'haccp') $haccp = true;
            $dev[] = ['step' => $n, 'title' => $title, 'tag' => $tag, 'known' => $match !== null,
                'value' => isset($s['value']) ? self::num((float) $s['value']) . (!empty($s['unit']) ? ' ' . $s['unit'] : '') : null];
        }
        $detail = implode(' ; ', array_map(fn($d) => 'etap ' . $d['step'] . ' « ' . $d['title'] . ' »' . ($d['value'] !== null ? ' : ' . $d['value'] : '')
            . ($d['tag'] === 'haccp' ? ' (HACCP)' : (!$d['known'] ? ' (etap zmieniony od wykonania)' : '')), $dev)) ?: 'odchylenie bez szczegółów etapu';
        return ['key' => 'sop_run:' . (int) $r['id'], 'kind' => 'sop_run', 'kind_label' => self::KINDS['sop_run'], 'source_id' => (int) $r['id'],
            'date' => $date, 'date_label' => self::label($date) . ', ' . self::hm((string) $r['finished_at']), 'period' => $period($date),
            'title' => $r['code'] . ' ' . $r['title'], 'sub' => 'wersja ' . (int) $r['version'] . ($r['user_name'] ? ' · ' . $r['user_name'] : '') . ($r['nc_number'] ? ' · ' . $r['nc_number'] : ''),
            'text' => null, 'by' => $r['user_name'] ?: null, 'nc' => $r['nc_number'] ?: null, 'deviations' => $dev, 'haccp' => $haccp,
            'signals' => [self::sig($haccp ? 'sop_deviation_haccp' : 'sop_deviation', $detail)],
            'link' => ['href' => self::SOP_RUNS_LINK, 'label' => 'Procedury SOP › Wykonania']];
    }

    /** Incident « Temperatura » de la tablette chauffeur. */
    private function tempItem(array $t, callable $period): array
    {
        $date = substr((string) $t['recorded_at'], 0, 10);
        $ship = substr((string) $t['ship_date'], 0, 10);
        $customer = trim((string) ($t['customer'] ?? '')) !== '' ? (string) $t['customer'] : 'klient #' . (int) $t['id_customer'];
        $note = trim((string) ($t['note'] ?? ''));
        return ['key' => 'temp:' . (int) $t['id'], 'kind' => 'delivery_temp', 'kind_label' => self::KINDS['delivery_temp'], 'source_id' => (int) $t['id'],
            'date' => $date, 'date_label' => self::label($date) . ', ' . self::hm((string) $t['recorded_at']), 'period' => $period($date),
            'title' => $customer, 'sub' => 'dostawa ' . self::label($ship) . ($t['driver_name'] ? ' · ' . $t['driver_name'] : ''), 'text' => $note !== '' ? mb_substr($note, 0, 200) : null,
            'by' => $t['driver_name'] ?: null, 'haccp' => true,
            'signals' => [self::sig('delivery_temp', 'zgłoszenie « Temperatura » na tablecie kierowcy' . ($note !== '' ? '' : ' (bez opisu)'))],
            'link' => ['href' => self::DELIVERIES_LINK . '?date=' . $ship, 'label' => 'Potwierdzenia dostaw']];
    }

    /** État de chaque source : table présente et au moins une ligne enregistrée, sinon « brak danych ». */
    private function sources(?array $events, ?array $sops, ?array $runs, ?array $temps): array
    {
        $src = function (?int $rows, string $empty, string $from) {
            $ok = $rows !== null && $rows > 0;
            return ['available' => $ok, 'rows' => $rows, 'note' => $ok ? null : ($rows === null ? 'brak danych — brak tabeli w bazie' : 'brak danych — ' . $empty), 'from' => $from];
        };
        return [
            'haccp' => $src($this->repo->sourceRows('n_mgr_haccp_event'), 'nikt nie wpisuje zdarzeń HACCP (moduł Kierownik produkcji)', 'n_mgr_haccp_event'),
            'sop' => $src($sops === null ? null : count($sops), 'żadna procedura SOP nie obowiązuje', 'n_sop'),
            'sop_haccp' => $src($this->repo->sourceRows('n_sop_step', "tag = 'haccp' AND id_sop IN (SELECT id FROM n_sop WHERE status = 'active')"), 'żadna obowiązująca procedura nie ma etapu HACCP', 'n_sop_step'),
            'sop_run' => $src($this->repo->sourceRows('n_sop_run'), 'żadna procedura nie była jeszcze wykonana', 'n_sop_run'),
            'temp' => $src($this->repo->sourceRows('n_delivery_incident'), 'brak zgłoszeń z tabletu kierowcy', 'n_delivery_incident'),
            'hala_chill' => $src($this->repo->sourceRows('n_hala_chill'), 'brak pomiarów schładzania z tabletu hali', 'n_hala_chill'),
            'hala_clean' => $src($this->repo->sourceRows('n_hala_clean_record'), 'brak zapisów sprzątania z tabletu hali', 'n_hala_clean_record'),
            'cold_clean' => $src($this->repo->sourceRows('n_cold_visit_place'), 'brak obchodów chłodni z tabletu jakości', 'n_cold_visit_place'),
        ];
    }

    /** Tendance : $n mois avant le mois examiné, le mois examiné, le mois en cours (partiel). null pour une source absente. */
    private function trend(string $from, string $cur, int $n, array $sources): array
    {
        $start = date('Y-m-01', strtotime($from . ' -' . $n . ' months'));
        $counts = $this->repo->monthlyCounts($start, date('Y-m-01', strtotime($cur . ' +1 month')));
        $months = [];
        for ($m = $start; $m <= $cur; $m = date('Y-m-01', strtotime($m . ' +1 month'))) $months[] = substr($m, 0, 7);
        $rows = [];
        foreach (['haccp' => 'Zdarzenia HACCP (przerwania łańcucha, wyniki negatywne)', 'sop_run' => 'Odchylenia przy wykonaniu SOP', 'temp' => 'Temperatura w dostawie',
                     'hala_chill' => 'Schładzanie poza limitem (tablet hali)'] as $k => $label) {
            $has = isset($counts[$k]) && $sources[$k]['available'];
            $values = array_map(fn($m) => $has ? (int) ($counts[$k][$m] ?? 0) : null, $months);
            $prev = $has ? array_slice($values, 0, $n) : [];
            $avg = $prev ? array_sum($prev) / count($prev) : null;
            $rows[] = ['key' => $k, 'label' => $label, 'values' => $values, 'available' => $has, 'month' => $has ? $values[$n] : null,
                'avg_prev' => $avg !== null ? round($avg, 1) : null, 'dir' => $avg === null ? null : ($values[$n] > $avg ? 'up' : ($values[$n] < $avg ? 'down' : 'flat'))];
        }
        return ['months' => array_map(fn($m) => ['key' => $m, 'label' => TaskWizards::monthLabel($m), 'reviewed' => $m . '-01' === $from, 'current' => $m . '-01' === $cur], $months), 'rows' => $rows];
    }

    /** [410] Schładzanie hors limites (trop chaud, ou délai dépassé) ; en cours dans les limites : rien. */
    private function chillItem(array $c, callable $period): ?array
    {
        $st = \App\Services\Workshop\Execution\HalaService::chillState($c);
        if (!in_array($st['state'], ['warm', 'late'], true)) return null;
        $date = (string) $c['work_date'];
        $detail = $c['temp_c'] !== null
            ? self::num((float) $c['temp_c']) . ' °C po ' . $st['minutes'] . ' min (limit ' . self::num((float) $c['target_c']) . ' °C w ' . (int) $c['max_minutes'] . ' min)'
            : 'brak pomiaru po ' . $st['minutes'] . ' min (limit ' . (int) $c['max_minutes'] . ' min)';
        return ['key' => 'hala_chill:' . (int) $c['id'], 'kind' => 'hala_chill', 'kind_label' => self::KINDS['hala_chill'], 'source_id' => (int) $c['id'],
            'date' => $date, 'date_label' => self::label($date) . ', ' . self::hm((string) $c['out_at']), 'period' => $period($date),
            'title' => (string) $c['name'], 'sub' => trim(($c['group_name'] ?? '') . ' · wyjście z pieca ' . self::hm((string) $c['out_at']), ' ·'), 'text' => null, 'by' => null,
            'haccp' => true, 'signals' => [self::sig('hala_chill_warm', $detail)], 'link' => ['href' => self::HALA_LINK, 'label' => 'Tablet hali : kroki i strefy']];
    }

    /** [409] Jour de production avec strefy non sprzątnięte. */
    private function cleanItem(array $c, callable $period): array
    {
        $date = substr((string) $c['work_date'], 0, 10);
        $missing = (int) $c['zones'] - (int) $c['done'];
        return ['key' => 'hala_clean:' . $date . ':' . (int) $c['id_group'], 'kind' => 'hala_clean', 'kind_label' => self::KINDS['hala_clean'], 'source_id' => (int) $c['id_group'],
            'date' => $date, 'date_label' => self::label($date), 'period' => $period($date), 'title' => (string) ($c['group_name'] ?? ('grupa #' . (int) $c['id_group'])),
            'sub' => (int) $c['done'] . ' z ' . (int) $c['zones'] . ' stref', 'text' => null, 'by' => null, 'haccp' => true,
            'signals' => [self::sig('hala_clean_missing', 'niesprzątnięte : ' . $c['missing'] . ' (' . max(1, $missing) . ' ' . self::pl(max(1, $missing), 'strefa', 'strefy', 'stref') . ')')],
            'link' => ['href' => self::HALA_LINK, 'label' => 'Tablet hali : kroki i strefy']];
    }

    /** [432] Obchód chłodni : miejsce « Czysto i porządek ? Nie » (photo obligatoire sur la tablette). */
    private function coldItem(array $c, callable $period): array
    {
        $date = substr((string) $c['saved_at'], 0, 10);
        $note = trim((string) ($c['note'] ?? ''));
        $who = trim((string) ($c['employee_name'] ?? ''));
        return ['key' => 'cold_clean:' . (int) $c['id'], 'kind' => 'cold_clean', 'kind_label' => self::KINDS['cold_clean'], 'source_id' => (int) $c['id'],
            'date' => $date, 'date_label' => self::label($date) . ', ' . self::hm((string) $c['saved_at']), 'period' => $period($date),
            'title' => (string) $c['place_name'], 'sub' => 'obchód chłodni #' . (int) $c['id_visit'] . ($who !== '' ? ' · ' . $who : ''), 'text' => $note !== '' ? mb_substr($note, 0, 200) : null,
            'by' => $who !== '' ? $who : null, 'haccp' => true, 'photo' => $c['id_photo'] !== null ? self::PHOTO_LINK . (int) $c['id_photo'] : null,
            'signals' => [self::sig('cold_dirty', 'porządek : nie' . ($c['id_photo'] !== null ? ' (zdjęcie)' : ''))],
            'link' => ['href' => self::COLD_LINK, 'label' => 'Zakupy tygodnia › Obchód chłodni']];
    }

    private function presentDecision(array $d): array
    {
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'],
            'month' => substr((string) $d['month_start'], 0, 7), 'month_label' => TaskWizards::monthLabel(substr((string) $d['month_start'], 0, 7)),
            'id_responsible' => $d['id_responsible'] !== null ? (int) $d['id_responsible'] : null, 'responsible' => $d['responsible_name'],
            'id_position' => $d['id_position'] !== null ? (int) $d['id_position'] : null, 'position' => $d['position_name'], 'flags' => $d['flags'],
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /** Krok 2 : décision pour un élément signalé (une par mois examiné, la dernière compte). */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : wyjaśnione / zamknięte, działanie korygujące albo niezgodność (NC).', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException('Działanie korygujące : wpisz, co zostanie zrobione (kto, co, do kiedy).', 'errors', 422);
        }
        $responsible = (int) ($in['id_responsible'] ?? 0) ?: null;
        $position = (int) ($in['id_position'] ?? 0) ?: null;
        if ($decision !== 'corrective') { $responsible = null; $position = null; }
        if ($responsible !== null && !in_array($responsible, array_column($this->wizards->users(), 'id'), true)) throw new CustomException('Nieznana osoba odpowiedzialna.', 'errors', 422);
        if ($position !== null && !in_array($position, array_column($this->repo->positions(), 'id'), true)) throw new CustomException('Nieznane stanowisko.', 'errors', 422);
        $d = $this->data($today);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        if ($it === null) throw new CustomException('Tego elementu nie ma na liście do wyjaśnienia (bez sygnału w ocenianym miesiącu).', 'errors', 422);
        $this->repo->saveDecision($d['month_start'], $key, $it['kind'], $it['source_id'], $decision, $note !== '' ? $note : null, $responsible, $position,
            implode(',', array_column($it['signals'], 'code')), $userId);
        return $this->data($today);
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

    /** « październik 2026 : 2 zdarzenia HACCP · 1 SOP po terminie przeglądu · 2 odchylenia SOP · 1 temperatura w dostawie · 6 do wyjaśnienia : 3 wyjaśnione · 2 działania korygujące · 1 NC » */
    public static function summary(string $month, array $t): string
    {
        $k = $t['by_kind'];
        $parts = [];
        if ($k['haccp_event']) $parts[] = $k['haccp_event'] . ' ' . self::pl($k['haccp_event'], 'zdarzenie HACCP', 'zdarzenia HACCP', 'zdarzeń HACCP');
        if ($k['sop_review']) $parts[] = $k['sop_review'] . ' SOP po terminie przeglądu';
        if ($k['sop_run']) $parts[] = $k['sop_run'] . ' ' . self::pl($k['sop_run'], 'odchylenie SOP', 'odchylenia SOP', 'odchyleń SOP');
        if ($k['delivery_temp']) $parts[] = $k['delivery_temp'] . ' ' . self::pl($k['delivery_temp'], 'temperatura w dostawie', 'temperatury w dostawie', 'temperatur w dostawie');
        if (!empty($k['hala_chill'])) $parts[] = $k['hala_chill'] . ' ' . self::pl($k['hala_chill'], 'schładzanie poza limitem', 'schładzania poza limitem', 'schładzań poza limitem');
        if (!empty($k['hala_clean'])) $parts[] = $k['hala_clean'] . ' ' . self::pl($k['hala_clean'], 'dzień bez pełnego sprzątania', 'dni bez pełnego sprzątania', 'dni bez pełnego sprzątania');
        if (!empty($k['cold_clean'])) $parts[] = $k['cold_clean'] . ' ' . self::pl($k['cold_clean'], 'nieporządek w chłodni', 'nieporządki w chłodniach', 'nieporządków w chłodniach');
        $s = $month . ' : ' . ($parts ? implode(' · ', $parts) : 'brak sygnałów HACCP');
        if ($t['review'] > 0) {
            $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
            $dec = [];
            if ($t['explained']) $dec[] = $t['explained'] . ' ' . self::pl($t['explained'], 'wyjaśnione', 'wyjaśnione', 'wyjaśnionych');
            if ($t['corrective']) $dec[] = $t['corrective'] . ' ' . self::pl($t['corrective'], 'działanie korygujące', 'działania korygujące', 'działań korygujących');
            if ($t['nc']) $dec[] = $t['nc'] . ' NC';
            if ($dec) $s .= ' : ' . implode(' · ', $dec);
        }
        return $s;
    }

    private static function sig(string $code, string $detail): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    /** Jours calendaires de $from à $to (0 = le même jour). */
    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime(substr($from, 0, 10) . ' 12:00:00')) / 86400);
    }

    private static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
