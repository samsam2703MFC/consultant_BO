<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Hr\HrSuccessionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Notification\AppNotificationService;
use DateTimeImmutable;

/**
 * [278] HR › Sukcesja (makieta 43) : toute personne active dont la retraite tombe dans les hr.succession_months mois
 * (24 par défaut) reçoit un plan de relève en 5 étapes, datées à rebours sur les 12 derniers mois avant le départ
 * (comprimées s'il en reste moins) : 1. audit du poste et des savoirs, 2. recrutement / choix du successeur,
 * 3. formation en binôme, 4. passage progressif (agenda 80 → 60 → 40 → 0 %), 5. dernière passation et entretien final.
 * Chaque étape a ses cases à cocher ; elle est terminée quand tout est coché ; choisir le successeur coche la case
 * de l'étape 2 et ouvre l'étape 3. Autres départs (wypowiedzenie, długi urlop, inne) : plan ajouté à la main.
 * Lecture sans écriture : un plan pas encore enregistré est montré « à la volée » ; il est enregistré par le cron du
 * matin (Bin/hr_followups.php) ou dès la première action. Étapes ouvertes → « Mój tydzień » du responsable ;
 * étapes en retard → cloche du responsable, une fois par semaine. Réduire le temps de travail suppose l'accord écrit
 * de la personne (aneks du modèle), rappelé dans l'interface.
 */
final class HrSuccessionService
{
    public const REASONS = ['emerytura' => 'Emerytura', 'wypowiedzenie' => 'Wypowiedzenie', 'urlop' => 'Długi urlop', 'inne' => 'Inne odejście'];

    /** Étapes : titre, bornes en douzièmes de la fenêtre (12 mois avant le départ), cases à cocher (code « successor » = choix du successeur). */
    public const STEPS = [
        1 => ['Audyt stanowiska i wiedzy', 0, 2, ['Lista zadań i obowiązków ze stanowiska', 'Wiedza nieopisana : receptury, ustawienia maszyn, kontakty, „triki”',
            'Procedury do spisania lub aktualizacji', 'Rozmowa z pracownikiem o planie i terminach']],
        2 => ['Rekrutacja / wybór następcy', 1, 4, ['Profil stanowiska z karty stanowiska', 'Decyzja : kandydat wewnętrzny czy rekrutacja',
            'Ogłoszenie / rozmowy z kandydatami', ['Następca wybrany → automatycznie etap 3', 'successor']]],
        3 => ['Szkolenie w parze (tandem)', 3, 8, ['Plan szkolenia : lista umiejętności do przekazania', 'Następca pracuje w parze z pracownikiem',
            'Następca wykonuje zadania pod nadzorem', 'Ocena po szkoleniu (rozmowa z następcą)']],
        4 => ['Stopniowe przekazanie (100→80→60 %)', 6, 10, ['Aneks : zmiana wymiaru czasu pracy — tylko za zgodą pracownika',
            'Następca prowadzi zmianę sam 2 dni w tygodniu', 'Pracownik : tylko nadzór i kontrola jakości']],
        5 => ['Ostatnie przekazanie, rozmowa końcowa', 10, 12, ['Przekazanie kluczy, haseł i dostępów', 'Procedury i dokumentacja przekazane',
            'Rozmowa końcowa', 'Świadectwo pracy i sprawy kadrowe']],
    ];

    /** Agenda de l'étape 4 : bornes en douzièmes, part du temps encore sur le poste, remarque. */
    private const AGENDA = [
        [6, 8, 80, '80 % czasu na stanowisku, 20 % szkolenie następcy'],
        [8, 10, 60, '60 % / 40 %, następca prowadzi zmianę sam 2 dni w tygodniu'],
        [10, 11, 40, '40 % / 60 %, tylko nadzór i kontrola jakości'],
        [11, 12, 0, 'przekazanie kluczy, haseł, procedur ; rozmowa końcowa'],
    ];

    public function __construct(private HrSuccessionRepository $repo, private HrRepository $hrRepo, private HrService $hr, private ParamRepository $params,
        private AppNotificationService $notifications)
    {
    }

    public function months(): int
    {
        return max(6, min(60, (int) $this->params->get('hr.succession_months', '24')));
    }

    /**
     * Dates des étapes et de l'agenda : fenêtre = 12 derniers mois avant le départ, ou de $from au départ s'il reste moins.
     * @return array{0: list<array>, 1: list<array>, 2: string}
     */
    public static function schedule(DateTimeImmutable $from, DateTimeImmutable $departure): array
    {
        $w = max($from, $departure->modify('-12 months'));
        $days = max(5, (int) $w->diff($departure)->days);
        $at = fn(int $twelfth) => $w->modify('+' . (int) round($days * $twelfth / 12) . ' days');
        $end = fn(int $twelfth, DateTimeImmutable $start) => max($start, $at($twelfth)->modify('-1 day'))->format('Y-m-d');
        $steps = [];
        foreach (self::STEPS as $no => [$title, $a, $b, $items]) {
            $start = $at($a);
            $steps[] = ['no' => $no, 'title' => $title, 'start' => $start->format('Y-m-d'), 'due' => $end($b, $start),
                'items' => array_map(fn($i) => is_array($i) ? ['label' => $i[0], 'code' => $i[1]] : ['label' => $i, 'code' => null], $items)];
        }
        $agenda = array_map(fn($r) => ['from' => $at($r[0])->format('Y-m-d'), 'to' => $end($r[1], $at($r[0])), 'pct' => $r[2], 'note' => $r[3]], self::AGENDA);
        return [$steps, $agenda, $w->format('Y-m-d')];
    }

    // --- Vue (makieta 43) -----------------------------------------------------------------------------------

    public function view(?int $viewer, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $date = $today->format('Y-m-d');
        $limit = $this->months();
        $people = [];
        foreach ($this->hr->view($viewer, false, $today)['people'] as $p) $people[$p['id']] = $p;
        $users = $this->users();
        $default = $this->defaultResponsible();

        $rows = $this->repo->plans();
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $steps = $this->repo->steps($ids);
        $items = $this->repo->items(array_map(fn($s) => (int) $s['id'], $steps));
        $agenda = $this->repo->agenda($ids);
        $plans = [];
        $retirePlanned = [];
        foreach ($rows as $r) {
            if ($r['reason'] === 'emerytura') $retirePlanned[(int) $r['id_employee']] = true;
            $mySteps = array_values(array_filter($steps, fn($s) => (int) $s['id_plan'] === (int) $r['id']));
            $plans[] = $this->planRow($r, $people[(int) $r['id_employee']] ?? null, array_map(fn($s) => $s + ['items' => array_values(array_filter($items, fn($i) => (int) $i['id_step'] === (int) $s['id']))], $mySteps),
                array_values(array_filter($agenda, fn($a) => (int) $a['id_plan'] === (int) $r['id'])), $date);
        }
        // Départs à la retraite pas encore enregistrés : montrés à la volée (enregistrés par le cron ou à la première action).
        $soon = 0;
        foreach ($people as $p) {
            if (!$p['active'] || !$p['retire'] || $p['retire']['date'] <= $date || $p['retire']['months_left'] > $limit) continue;
            $soon++;
            if (isset($retirePlanned[$p['id']])) continue;
            [$s, $a] = self::schedule($today, new DateTimeImmutable($p['retire']['date']));
            $plans[] = $this->planRow(['id' => null, 'id_employee' => $p['id'], 'reason' => 'emerytura', 'departure_date' => $p['retire']['date'], 'successor_employee' => null,
                'successor_note' => null, 'successor_name' => null, 'responsible_user' => $default['id'] ?? null, 'responsible_name' => $default['name'] ?? null,
                'status' => 'active', 'auto' => 1, 'created_at' => $date],
                $p, array_map(fn($x) => ['id' => null, 'step_no' => $x['no'], 'title' => $x['title'], 'start_date' => $x['start'], 'due_date' => $x['due'], 'done_at' => null,
                    'items' => array_map(fn($i, $k) => ['id' => null, 'label' => $i['label'], 'code' => $i['code'], 'sort' => $k, 'done_at' => null, 'done_name' => null], $x['items'], array_keys($x['items']))], $s),
                array_map(fn($x) => ['date_from' => $x['from'], 'date_to' => $x['to'], 'own_pct' => $x['pct'], 'note' => $x['note']], $a), $date);
        }
        usort($plans, fn($x, $y) => [$x['status'] !== 'active', $x['departure']] <=> [$y['status'] !== 'active', $y['departure']]);
        $active = array_values(array_filter($plans, fn($p) => $p['status'] === 'active'));
        return [
            'plans' => $plans, 'today' => $date, 'months' => $limit, 'reasons' => self::REASONS, 'users' => $users,
            'people' => array_values(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'group' => $p['group'], 'position' => $p['position']], array_filter($people, fn($p) => $p['active']))),
            'kpi' => [
                'retire_soon' => $soon, 'active' => count($active),
                'progress' => $active ? (int) round(array_sum(array_column($active, 'progress')) / count($active)) : null,
                'late' => array_sum(array_column($active, 'late')),
                'successors' => count(array_filter($active, fn($p) => $p['successor'] !== null)),
            ],
        ];
    }

    private function planRow(array $r, ?array $person, array $steps, array $agenda, string $date): array
    {
        $outSteps = [];
        foreach ($steps as $s) {
            $its = array_map(fn($i) => ['id' => $i['id'] === null ? null : (int) $i['id'], 'label' => $i['label'], 'code' => $i['code'], 'sort' => (int) $i['sort'],
                'done' => $i['done_at'] !== null, 'done_at' => $i['done_at'], 'done_name' => $i['done_name'] ?? null], $s['items']);
            $n = count($its);
            $done = $s['done_at'] !== null;
            $pct = $n ? (int) round(100 * count(array_filter($its, fn($i) => $i['done'])) / $n) : 0;
            $outSteps[] = ['id' => $s['id'] === null ? null : (int) $s['id'], 'no' => (int) $s['step_no'], 'title' => $s['title'], 'start' => $s['start_date'], 'due' => $s['due_date'],
                'progress' => $done ? 100 : $pct, 'done' => $done, 'late' => !$done && $s['due_date'] < $date, 'current' => !$done && $s['start_date'] <= $date, 'items' => $its];
        }
        $successor = $r['successor_employee'] !== null ? ['id' => (int) $r['successor_employee'], 'name' => $r['successor_name']] : null;
        $dep = new DateTimeImmutable($r['departure_date']);
        $today = new DateTimeImmutable($date);
        $months = $dep > $today ? (int) $today->diff($dep)->y * 12 + (int) $today->diff($dep)->m : 0;
        $created = substr((string) $r['created_at'], 0, 10);
        return [
            'id' => $r['id'] === null ? null : (int) $r['id'], 'virtual' => $r['id'] === null, 'employee_id' => (int) $r['id_employee'],
            'name' => $person['name'] ?? '—', 'group' => $person['group'] ?? null, 'group_color' => $person['group_color'] ?? null, 'position' => $person['position'] ?? null,
            'reason' => $r['reason'], 'reason_label' => self::REASONS[$r['reason']] ?? $r['reason'], 'departure' => $r['departure_date'], 'months_left' => $months,
            'retire_age' => $r['reason'] === 'emerytura' ? ($person['retire']['age'] ?? null) : null,
            'successor' => $successor, 'successor_note' => $r['successor_note'], 'responsible' => $r['responsible_user'] !== null ? ['id' => (int) $r['responsible_user'], 'name' => $r['responsible_name']] : null,
            'status' => $r['status'], 'auto' => (bool) $r['auto'], 'window_from' => self::schedule(new DateTimeImmutable($created), $dep)[2], 'window_to' => $r['departure_date'],
            'progress' => $outSteps ? (int) round(array_sum(array_column($outSteps, 'progress')) / count($outSteps)) : 0,
            'late' => count(array_filter($outSteps, fn($s) => $s['late'])), 'steps' => $outSteps,
            'agenda' => array_map(fn($a) => ['from' => $a['date_from'], 'to' => $a['date_to'], 'pct' => (int) $a['own_pct'], 'note' => $a['note']], $agenda),
        ];
    }

    // --- Écritures -----------------------------------------------------------------------------------------

    /** Ouvre un plan (idempotent pour un plan actif du même motif) et renvoie son id. */
    public function open(array $in, ?int $viewer, ?DateTimeImmutable $today = null, bool $auto = false): int
    {
        $today ??= new DateTimeImmutable('today');
        $employee = (int) ($in['employee_id'] ?? 0);
        $reason = (string) ($in['reason'] ?? 'emerytura');
        if (!isset(self::REASONS[$reason])) throw new CustomException('Nieznany powód odejścia.', 'errors', 422, ['fields' => ['reason' => 'Wybierz powód.']]);
        $person = null;
        foreach ($this->hr->view($viewer, false, $today)['people'] as $p) if ($p['id'] === $employee && $p['active']) $person = $p;
        if (!$person) throw new CustomException('Wybierz aktywnego pracownika.', 'errors', 422, ['fields' => ['employee_id' => 'Wybierz pracownika.']]);
        $existing = $this->repo->planFor($employee, $reason);
        if ($existing && ($existing['status'] === 'active' || ($reason === 'emerytura' && empty($in['departure_date'])))) return (int) $existing['id'];
        $departure = trim((string) ($in['departure_date'] ?? '')) ?: ($reason === 'emerytura' ? ($person['retire']['date'] ?? '') : '');
        $dep = DateTimeImmutable::createFromFormat('!Y-m-d', $departure);
        if (!$dep || $dep->format('Y-m-d') !== $departure) throw new CustomException('Podaj datę odejścia.', 'errors', 422, ['fields' => ['departure_date' => 'Data odejścia (RRRR-MM-DD).']]);
        if ($dep <= $today->modify('+7 days')) throw new CustomException('Data odejścia musi być co najmniej za tydzień.', 'errors', 422, ['fields' => ['departure_date' => 'Najwcześniej za tydzień.']]);
        $resp = $this->defaultResponsible();
        [$steps, $agenda] = self::schedule($today, $dep);
        $id = $this->repo->create(['employee' => $employee, 'reason' => $reason, 'departure' => $departure, 'responsible' => $resp['id'] ?? null, 'auto' => $auto, 'by' => $viewer], $steps, $agenda);
        $this->hrRepo->log($viewer, $employee, 'succession_open');
        return $id;
    }

    /** Successeur, responsable, date de départ (étapes recalculées), annulation / réouverture. */
    public function update(int $id, array $in, ?int $viewer, ?DateTimeImmutable $today = null): void
    {
        $today ??= new DateTimeImmutable('today');
        $plan = $this->repo->plan($id) ?? throw new CustomException('Nie ma takiego planu.', 'errors', 404);
        $set = [];
        if (array_key_exists('successor_employee', $in)) {
            $s = $in['successor_employee'] === null || $in['successor_employee'] === '' ? null : (int) $in['successor_employee'];
            if ($s !== null) {
                if ($s === (int) $plan['id_employee']) throw new CustomException('Następca nie może być osobą, która odchodzi.', 'errors', 422, ['fields' => ['successor_employee' => 'Inna osoba.']]);
                $ok = false;
                foreach ($this->hr->view($viewer, false, $today)['people'] as $p) if ($p['id'] === $s && $p['active']) $ok = true;
                if (!$ok) throw new CustomException('Wybierz aktywnego pracownika.', 'errors', 422, ['fields' => ['successor_employee' => 'Aktywny pracownik.']]);
            }
            $set['successor_employee'] = $s;
        }
        if (array_key_exists('successor_note', $in)) $set['successor_note'] = mb_substr(trim((string) $in['successor_note']), 0, 200) ?: null;
        if (array_key_exists('responsible_user', $in)) {
            $u = $in['responsible_user'] === null || $in['responsible_user'] === '' ? null : (int) $in['responsible_user'];
            if ($u !== null && !in_array($u, array_column($this->users(), 'id'), true)) throw new CustomException('Wybierz osobę z listy.', 'errors', 422, ['fields' => ['responsible_user' => 'Z listy.']]);
            $set['responsible_user'] = $u;
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['active', 'cancelled'], true)) throw new CustomException('Nieznany status.', 'errors', 422);
            $set['status'] = $in['status'];
            $set['closed_at'] = $in['status'] === 'cancelled' ? $today->format('Y-m-d H:i:s') : null;
        }
        $this->repo->setPlan($id, $set);
        if (array_key_exists('successor_employee', $set) || array_key_exists('successor_note', $set)) {
            $chosen = (array_key_exists('successor_employee', $set) ? $set['successor_employee'] : $plan['successor_employee']) !== null;
            $this->repo->setCodeItem($id, 'successor', $chosen, $viewer);
            $this->repo->refreshSteps($id);
            $this->closeIfDone($id);
        }
        if (!empty($in['departure_date']) && $in['departure_date'] !== $plan['departure_date']) {
            $dep = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $in['departure_date']);
            if (!$dep || $dep->format('Y-m-d') !== $in['departure_date']) throw new CustomException('Podaj datę odejścia.', 'errors', 422, ['fields' => ['departure_date' => 'Data (RRRR-MM-DD).']]);
            $created = new DateTimeImmutable(substr((string) $plan['created_at'], 0, 10));
            if ($dep <= $created->modify('+7 days')) throw new CustomException('Data odejścia za blisko początku planu.', 'errors', 422, ['fields' => ['departure_date' => 'Później.']]);
            [$steps, $agenda] = self::schedule($created, $dep);
            $this->repo->reschedule($id, $dep->format('Y-m-d'), $steps, $agenda);
        }
        $this->hrRepo->log($viewer, (int) $plan['id_employee'], 'succession_update');
    }

    public function check(int $itemId, bool $done, ?int $viewer): void
    {
        $item = $this->repo->item($itemId) ?? throw new CustomException('Nie ma takiej pozycji.', 'errors', 404);
        if ($item['code'] === 'successor') throw new CustomException('Wybierz następcę w planie — ta pozycja zaznacza się sama.', 'errors', 422);
        $plan = $this->repo->plan((int) $item['id_plan']);
        if (!$plan || $plan['status'] === 'cancelled') throw new CustomException('Plan jest anulowany.', 'errors', 422);
        $this->repo->setItem($itemId, $done, $viewer);
        $this->repo->refreshSteps((int) $item['id_plan']);
        $this->closeIfDone((int) $item['id_plan']);
    }

    /** Plan terminé quand toutes les étapes le sont (rouvert si une case est décochée). */
    private function closeIfDone(int $planId): void
    {
        $plan = $this->repo->plan($planId);
        if (!$plan || $plan['status'] === 'cancelled') return;
        $steps = $this->repo->steps([$planId]);
        $all = $steps && !array_filter($steps, fn($s) => $s['done_at'] === null);
        if ($all && $plan['status'] === 'active') $this->repo->setPlan($planId, ['status' => 'done', 'closed_at' => date('Y-m-d H:i:s')]);
        if (!$all && $plan['status'] === 'done') $this->repo->setPlan($planId, ['status' => 'active', 'closed_at' => null]);
    }

    // --- Cron (Bin/hr_followups.php) ----------------------------------------------------------------------

    /** Enregistre les plans automatiques manquants. */
    public function sync(?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable('today');
        $n = 0;
        foreach ($this->view(null, $today)['plans'] as $p) {
            if (!$p['virtual']) continue;
            $this->open(['employee_id' => $p['employee_id'], 'reason' => 'emerytura'], null, $today, true);
            $n++;
        }
        return $n;
    }

    /** Étapes en retard : cloche du responsable, une fois par semaine et par étape. */
    public function reminders(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $n = 0;
        foreach ($this->repo->lateSteps($now->format('Y-m-d')) as $s) {
            $this->notifications->publishForUser((int) $s['responsible_user'], 'hr_succession', 'warning', 'Sukcesja : etap spóźniony — ' . $s['name'],
                $s['step_no'] . '. ' . $s['title'] . ' (termin ' . date('d.m.Y', strtotime($s['due_date'])) . ')', '/hr/succession?plan=' . $s['id_plan'],
                ['plan_id' => (int) $s['id_plan'], 'step' => (int) $s['step_no']], 'hr_succession:' . $s['id'] . ':' . $now->format('o-W'));
            $n++;
        }
        return $n;
    }

    // --- Aides ---------------------------------------------------------------------------------------------

    /** Personnes qui peuvent porter un plan : administrateurs et postes de bureau (mêmes que les follow-ups). */
    private function users(): array
    {
        $codes = array_values(array_filter(array_keys(HrQuickNoteService::AUDIENCE), fn($c) => !in_array($c, ['admin', 'me'], true)));
        return $this->hrRepo->followupUsers($codes);
    }

    /** Responsable par défaut : le Kierownik produkcji, sinon le premier administrateur. */
    private function defaultResponsible(): ?array
    {
        $k = array_values($this->hrRepo->recipients(['KIEROWNIK_PRODUKCJI']));
        if ($k) return ['id' => (int) $k[0]['id'], 'name' => $k[0]['name']];
        $a = $this->hrRepo->admins()[0] ?? null;
        if (!$a) return null;
        foreach ($this->users() as $u) if ($u['id'] === $a['id']) return ['id' => $u['id'], 'name' => $u['name']];
        return ['id' => $a['id'], 'name' => null];
    }
}
