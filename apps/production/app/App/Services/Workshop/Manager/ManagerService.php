<?php
declare(strict_types=1);

namespace App\Services\Workshop\Manager;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ManagerRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Kierownik Produkcji i Rozwoju Produktu : plan roku (osoba, wynagrodzenie, pula premii, godziny na misję), cele
 * wskaźników (szkic → zatwierdzenie), raport miesięczny, premia za kwartał, przygotowanie rozmowy.
 *
 * Zasady (opis stanowiska) : dane wyłącznie z ERP ; brak danej = wskaźnik nieliczony, nigdy zgadywany. Wpis ręczny
 * samej osoby na stanowisku = « niezweryfikowane ». Cel liczy się do premii tylko, gdy zatwierdzony przed początkiem
 * okresu ; brak celu = brak premii za sekcję. HACCP nie jest premiowany, jest warunkiem : niekorzystna kontrola
 * urzędowa, nieobsłużone przerwanie łańcucha chłodniczego albo audyt niezależny poniżej progu w okresie = cała
 * premia okresu wstrzymana. Sekcje nie kompensują się nawzajem ; wyniki nie są zaokrąglane przed progiem.
 */
final class ManagerService
{
    private const NEW_PRODUCT_MONTHS = 6;
    private const TRACK_WEEKS_MIN = 8;
    private const SHARP_CHANGE = 0.5;
    public const HACCP_KINDS = ['official_control', 'cold_chain_break', 'independent_audit', 'self_check'];
    private const PLAN_FIELDS = ['holder_user_id', 'holder_name', 'holder_start', 'salary_monthly', 'envelope_pct', 'currency', 'team_organisation', 'auditor',
        'audit_threshold', 'margin_threshold_pct', 'price_reference_date', 'workplace', 'contract_type', 'working_time', 'monthly_hours', 'mission_hours'];

    /** @var array<string, array> pomiary wskaźników na okres (raz na odczyt ; czyszczone na początku każdego odczytu) */
    private array $measured = [];

    public function __construct(
        private ManagerRepository $repo,
        private ProfitabilityService $profit,
        private ProfitabilityRepository $profitRepo,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    // --- Plan i cele ------------------------------------------------------------------------------------------

    /** Plan roku z celami (szkic, zatwierdzony, historia) i podpowiedzią : wynik ostatnich 3 pełnych miesięcy. */
    public function plan(int $year, ApiContext $context): array
    {
        $this->checkYear($year);
        $this->measured = [];
        $plan = $this->repo->plan($year);
        $versions = $plan ? $this->repo->objectives((int) $plan['id']) : [];
        $hintFrom = date('Y-m-01', strtotime('first day of -3 months'));
        $hintTo = date('Y-m-t', strtotime('first day of last month'));
        $hint = $this->measure($hintFrom, $hintTo, $plan);
        $indicators = [];
        foreach (ManagerIndicators::INDICATORS as $code => $def) {
            $draft = null;
            $history = [];
            foreach ($versions as $v) {
                if ($v['indicator'] !== $code) continue;
                if ($v['validated_at'] === null) $draft ??= (float) $v['target'];
                else $history[] = ['target' => (float) $v['target'], 'validated_at' => $v['validated_at']];
            }
            $indicators[] = ['code' => $code, 'section' => $def['section'], 'direction' => $def['direction'], 'unit' => $def['unit'], 'source' => $def['source'],
                'default' => $def['default'] ?? null, 'draft' => $draft, 'validated' => $history[0] ?? null, 'history' => $history,
                'hint' => $hint[$code]['value'], 'hint_missing' => $hint[$code]['missing']];
        }
        return [
            'year' => $year, 'plan' => $this->presentPlan($plan, $year), 'indicators' => $indicators,
            'sections' => array_map(fn($code, $s) => ['code' => $code, 'weight' => $s['weight'], 'months' => $s['months'], 'indicators' => $s['indicators']],
                array_keys(ManagerIndicators::SECTIONS), ManagerIndicators::SECTIONS),
            'missions' => ManagerIndicators::MISSIONS, 'hint_period' => ['from' => $hintFrom, 'to' => $hintTo],
            'drafts' => count(array_filter($indicators, fn($i) => $i['draft'] !== null)),
            'users' => array_map(fn($u) => ['id' => (int) $u['id'], 'name' => trim($u['name']) !== '' ? trim($u['name']) : $u['login']], $this->repo->users()),
            'can_write' => $context->can('prod.manager.write'), 'can_report' => $context->can('prod.manager.report'),
        ];
    }

    public function savePlan(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['year', ...self::PLAN_FIELDS])
            ->integer('year', true, 2020, 2100)->integer('holder_user_id', false, 1, null, true)->string('holder_name', 120, false, true)
            ->date('holder_start')->decimal('salary_monthly', false, 0, 10_000_000, true)->decimal('envelope_pct', false, 0, 100)
            ->string('currency', 3, false, false, '/^[A-Z]{3}$/')->string('team_organisation', 2000, false, true)->string('auditor', 160, false, true)
            ->decimal('audit_threshold', false, 0, 100, true)->decimal('margin_threshold_pct', false, -100, 100, true)->date('price_reference_date')
            ->string('workplace', 160, false, true)->string('contract_type', 160, false, true)->string('working_time', 160, false, true)
            ->decimal('monthly_hours', false, 0, 400, true);
        $hours = null;
        if (array_key_exists('mission_hours', $body)) {
            $hours = $body['mission_hours'];
            $ok = $hours === null || (is_array($hours) && !array_diff(array_keys($hours), ManagerIndicators::MISSIONS)
                && !array_filter($hours, fn($h) => $h !== null && (!is_numeric($h) || $h < 0 || $h > 400)));
            if (!$ok) $v->add('mission_hours', 'INVALID_JSON_VALUE');
        }
        if ($v->value('holder_user_id') !== null && !in_array((int) $v->value('holder_user_id'), array_map(fn($u) => (int) $u['id'], $this->repo->users()), true)) {
            $v->add('holder_user_id', 'REFERENCE_NOT_FOUND');
        }
        $v->throwIfInvalid();
        $data = array_diff_key($v->clean(), ['year' => true]);
        if (array_key_exists('mission_hours', $body)) {
            $data['mission_hours'] = $hours === null ? null : json_encode(array_map(fn($h) => $h === null ? null : (float) $h, $hours));
        }
        $year = (int) $v->value('year');
        $before = $this->repo->plan($year);
        $this->tx->execute(function () use ($year, $data, $before, $context) {
            $id = $this->repo->savePlan($year, $data + ['updated_by' => $context->userId]);
            $this->audit->record($context, $before ? 'update' : 'create', 'manager_plan', $id, $before ? array_intersect_key($before, $data) : null, $data);
        });
        return $this->plan($year, $context);
    }

    /** Szkice celów : { year, objectives: { wskaźnik: liczba | null } } (null usuwa szkic). */
    public function saveObjectives(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['year', 'objectives'])->integer('year', true, 2020, 2100);
        $objectives = $body['objectives'] ?? null;
        if (!is_array($objectives) || !$objectives) $v->add('objectives', 'REQUIRED');
        foreach (is_array($objectives) ? $objectives : [] as $code => $target) {
            if (!isset(ManagerIndicators::INDICATORS[$code])) $v->add('objectives.' . $code, 'UNKNOWN_FIELD');
            elseif ($target !== null && !is_int($target) && !is_float($target) && !(is_string($target) && is_numeric($target))) $v->add('objectives.' . $code, 'NOT_NUMBER');
            elseif ($target !== null && abs((float) $target) > 1_000_000) $v->add('objectives.' . $code, 'OUT_OF_RANGE', ['min' => -1000000, 'max' => 1000000]);
        }
        $v->throwIfInvalid();
        $year = (int) $v->value('year');
        $this->tx->execute(function () use ($year, $objectives, $context) {
            $planId = ($this->repo->plan($year)['id'] ?? null) ?? $this->repo->savePlan($year, ['updated_by' => $context->userId]);
            foreach ($objectives as $code => $target) $this->repo->saveDraft((int) $planId, (string) $code, $target === null ? null : (float) $target, $context->userId);
            $this->audit->record($context, 'draft', 'manager_objectives', $planId, null, ['year' => $year, 'objectives' => $objectives]);
        });
        return $this->plan($year, $context);
    }

    /** Zatwierdza szkice : od teraz obowiązują w okresach zaczynających się po zatwierdzeniu. */
    public function validateObjectives(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['year'])->integer('year', true, 2020, 2100);
        $v->throwIfInvalid();
        $year = (int) $v->value('year');
        $plan = $this->repo->plan($year);
        if ($plan === null) throw new ApiException('MANAGER_NO_DRAFTS', 409);
        $count = $this->tx->execute(function () use ($plan, $context) {
            $n = $this->repo->validateDrafts((int) $plan['id'], $context->userId, date('Y-m-d H:i:s'));
            if ($n > 0) $this->audit->record($context, 'validate', 'manager_objectives', (int) $plan['id'], null, ['count' => $n]);
            return $n;
        });
        if ($count === 0) throw new ApiException('MANAGER_NO_DRAFTS', 409);
        return $this->plan($year, $context);
    }

    // --- Wpisy miesiąca ---------------------------------------------------------------------------------------

    /** Wartość ręczna wskaźnika w miesiącu (tylko wskaźniki z wpisem ręcznym) ; value null usuwa. */
    public function saveValue(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['month', 'indicator', 'value', 'note'])->string('month', 7)
            ->choice('indicator', array_keys(array_filter(ManagerIndicators::INDICATORS, fn($d) => $d['source'] !== 'erp')))
            ->decimal('value', true, 0, 1_000_000, true)->string('note', 255, false, true);
        $v->throwIfInvalid();
        $month = $this->checkMonth((string) $v->value('month'));
        $this->tx->execute(function () use ($v, $month, $context) {
            $this->repo->saveValue($month, (string) $v->value('indicator'), $v->value('value') !== null ? (float) $v->value('value') : null, $v->value('note'), $context->userId);
            $this->audit->record($context, 'value', 'manager_value', $month, null, ['indicator' => $v->value('indicator'), 'value' => $v->value('value')]);
        });
        return $this->report($month, $context);
    }

    public function addHaccp(array $body, ApiContext $context): array
    {
        $data = $this->haccpInput($body, false);
        $id = $this->tx->execute(function () use ($data, $context) {
            $id = $this->repo->insertHaccp($data + ['created_by' => $context->userId]);
            $this->audit->record($context, 'create', 'haccp_event', $id, null, $data);
            return $id;
        });
        return $this->presentHaccp($this->repo->haccpEvent($id), $this->repo->plan((int) substr($data['event_date'], 0, 4)));
    }

    public function updateHaccp(int $id, array $body, ApiContext $context): array
    {
        $event = $this->repo->haccpEvent($id) ?? throw ApiException::notFound('haccp_event', $id);
        $data = $this->haccpInput($body + ['kind' => $event['kind']], true);
        unset($data['kind']);
        $this->tx->execute(function () use ($id, $event, $data, $context) {
            $this->repo->updateHaccp($id, $data);
            $this->audit->record($context, 'update', 'haccp_event', $id, array_intersect_key($event, $data), $data);
        });
        $event = $this->repo->haccpEvent($id);
        return $this->presentHaccp($event, $this->repo->plan((int) substr($event['event_date'], 0, 4)));
    }

    public function deleteHaccp(int $id, ApiContext $context): array
    {
        $event = $this->repo->haccpEvent($id) ?? throw ApiException::notFound('haccp_event', $id);
        $this->tx->execute(function () use ($id, $event, $context) {
            $this->repo->deleteHaccp($id);
            $this->audit->record($context, 'delete', 'haccp_event', $id, $event, null);
        });
        return ['id' => $id, 'deleted' => true];
    }

    /** Cele wprowadzenia nowego produktu i decyzja (zatrzymać, poprawić, wycofać). */
    public function saveLaunch(int $productId, array $body, ApiContext $context): array
    {
        if (!$this->repo->productExists($productId)) throw ApiException::notFound('product', $productId);
        $v = (new Validator($body))->only(['launch_date', 'target_cost', 'target_price', 'min_margin_pct', 'expected_week_shop', 'decision', 'decision_note'])
            ->date('launch_date', true, false)->decimal('target_cost', false, 0, 1_000_000, true)->decimal('target_price', false, 0, 1_000_000, true)
            ->decimal('min_margin_pct', false, -100, 100, true)->decimal('expected_week_shop', false, 0, 1_000_000, true)
            ->choice('decision', ['keep', 'fix', 'stop'], false, true)->string('decision_note', 255, false, true);
        $v->throwIfInvalid();
        $data = $v->clean() + ['updated_by' => $context->userId];
        $this->tx->execute(function () use ($productId, $data, $context) {
            $this->repo->saveLaunch($productId, $data);
            $this->audit->record($context, 'save', 'product_launch', $productId, null, $data);
        });
        return ['product_id' => $productId] + $data;
    }

    /** Trzy działania priorytetowe na następny miesiąc (z raportu miesiąca). */
    public function saveActions(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['month', 'actions'])->string('month', 7);
        $actions = $body['actions'] ?? null;
        if (!is_array($actions) || !array_is_list($actions)) $v->add('actions', 'NOT_ARRAY');
        elseif (count($actions) > 3) $v->add('actions', 'TOO_MANY', ['max' => 3]);
        $clean = [];
        foreach (is_array($actions) && array_is_list($actions) ? $actions : [] as $i => $a) {
            $av = (new Validator(is_array($a) ? $a : []))->only(['text', 'owner', 'due_date', 'status'])->string('text', 500)->string('owner', 120, false, true)
                ->date('due_date')->choice('status', ['open', 'done'], false);
            foreach ($av->errors() as $e) $v->add("actions.{$i}.{$e['field']}", $e['code'], $e['params']);
            $clean[] = ['text' => $av->value('text'), 'owner' => $av->value('owner'), 'due_date' => $av->value('due_date'), 'status' => $av->value('status', 'open')];
        }
        $v->throwIfInvalid();
        $month = $this->checkMonth((string) $v->value('month'));
        $this->tx->execute(function () use ($month, $clean, $context) {
            $this->repo->saveActions($month, $clean, $context->userId);
            $this->audit->record($context, 'save', 'manager_actions', $month, null, ['actions' => $clean]);
        });
        return $this->report($month, $context);
    }

    // --- Raport miesięczny ------------------------------------------------------------------------------------

    /**
     * Raport miesiąca : 1. alerty (HACCP najpierw, potem wskaźniki < 90 %), 2. wskaźniki (cel, wynik, odchylenie,
     * status), 3. nowe produkty (marża i wolumen vs cele, decyzja zalecana), 4. dane brakujące i niezweryfikowane,
     * 5. trzy działania na następny miesiąc.
     */
    public function report(string $month, ApiContext $context): array
    {
        $month = $this->checkMonth($month);
        $this->measured = [];
        $from = $month . '-01';
        $today = date('Y-m-d');
        if ($from > $today) throw ApiException::validation([['field' => 'month', 'code' => 'IN_FUTURE', 'params' => []]]);
        $to = min(date('Y-m-t', strtotime($from)), $today);
        $plan = $this->repo->plan((int) substr($month, 0, 4));
        $versions = $plan ? $this->repo->objectives((int) $plan['id']) : [];
        $values = $this->measure($from, $to, $plan);
        $prevFrom = date('Y-m-01', strtotime($from . ' -1 month'));
        $previous = $this->measure($prevFrom, date('Y-m-t', strtotime($prevFrom)), $plan);

        $rows = [];
        $missing = [];
        foreach (ManagerIndicators::INDICATORS as $code => $def) {
            $objective = $this->objectiveAt($versions, $code, $to . ' 23:59:59');
            $m = $values[$code];
            $flags = $this->flags($code, $m, $previous[$code]);
            $att = ManagerIndicators::attainment($code, $objective['target'] ?? null, $m['value']);
            $rows[] = ['code' => $code, 'section' => $def['section'], 'direction' => $def['direction'], 'unit' => $def['unit'],
                'target' => $objective['target'] ?? null, 'validated_at' => $objective['validated_at'] ?? null, 'value' => $m['value'],
                'gap' => $m['value'] !== null && $objective !== null ? $m['value'] - $objective['target'] : null,
                'attainment' => $att, 'status' => ManagerIndicators::status($att), 'origin' => $m['origin'], 'unverified' => $m['unverified'],
                'missing' => $m['missing'], 'detail' => $m['detail'], 'flags' => $flags, 'previous' => $previous[$code]['value']];
            if ($objective === null) $missing[] = ['code' => 'NO_OBJECTIVE', 'indicator' => $code];
            if ($m['missing'] !== null) $missing[] = ['code' => 'NO_DATA', 'indicator' => $code, 'reason' => $m['missing']];
            if ($m['unverified']) $missing[] = ['code' => 'UNVERIFIED_VALUE', 'indicator' => $code];
            foreach ($flags as $f) $missing[] = ['code' => $f, 'indicator' => $code];
        }

        $haccp = $this->haccp($from, $to, $plan);
        $alerts = array_map(fn($b) => ['level' => 'haccp'] + $b, $haccp['blocking']);
        foreach ($haccp['unverified'] as $id) $missing[] = ['code' => 'UNVERIFIED_HACCP', 'event_id' => $id];
        // Audyt niezależny co kwartał : brak wpisu w kwartale miesiąca (od jego początku do końca miesiąca).
        $quarterFrom = sprintf('%s-%02d-01', substr($month, 0, 4), intdiv((int) substr($month, 5, 2) - 1, 3) * 3 + 1);
        if (!array_filter($this->repo->haccpEvents($quarterFrom, $to), fn($e) => $e['kind'] === 'independent_audit')) $missing[] = ['code' => 'NO_AUDIT_QUARTER', 'from' => $quarterFrom];
        foreach ($rows as $r) {
            if ($r['status'] === 'red') $alerts[] = ['level' => 'indicator', 'code' => 'BELOW_90', 'indicator' => $r['code'], 'attainment' => $r['attainment']];
        }
        foreach ($this->planGaps($plan) as $code) $missing[] = ['code' => $code];

        $products = $this->newProducts($from, $to, $plan);
        foreach ($products as $p) if ($p['recommendation'] === 'targets_missing') $missing[] = ['code' => 'LAUNCH_TARGETS_MISSING', 'product' => $p['name']];

        $actions = $this->repo->actions($month);
        $suggested = false;
        if (!$actions) {
            $suggested = true;
            $actions = $this->suggestActions($rows, $plan, $month);
        }
        return [
            'month' => $month, 'from' => $from, 'to' => $to, 'extracted_at' => date('Y-m-d H:i'), 'partial' => $to < date('Y-m-t', strtotime($from)),
            'plan' => $this->presentPlan($plan, (int) substr($month, 0, 4)),
            'haccp' => $haccp, 'alerts' => $alerts, 'indicators' => $rows, 'products' => $products, 'missing' => $missing,
            'actions' => array_map(fn($a) => ['text' => $a['text'], 'owner' => $a['owner'], 'due_date' => $a['due_date'], 'status' => $a['status'] ?? 'open',
                'indicator' => $a['indicator'] ?? null, 'kind' => $a['kind'] ?? null], $actions),
            'actions_suggested' => $suggested,
            'can_report' => $context->can('prod.manager.report'), 'can_write' => $context->can('prod.manager.write'),
        ];
    }

    // --- Premia -----------------------------------------------------------------------------------------------

    /**
     * Premia za kwartał 'YYYY-Qn' : sekcje kwartalne za kwartał, rozwój produktów za półrocze (w Q2 i Q4). Najpierw
     * warunek HACCP ; potem sekcja po sekcji : cele zatwierdzone przed początkiem okresu, realizacja, współczynnik,
     * kwota i uzasadnienie.
     */
    public function bonus(string $quarter, ApiContext $context): array
    {
        if (!preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $m)) throw ApiException::validation([['field' => 'period', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => 'YYYY-Q1…Q4']]]);
        $year = (int) $m[1];
        $this->measured = [];
        $plan = $this->repo->plan($year);
        $versions = $plan ? $this->repo->objectives((int) $plan['id']) : [];
        $p = $this->presentPlan($plan, $year);
        $envelope = $p['envelope_annual'];
        $today = date('Y-m-d');
        $sections = [];
        $total = 0.0;
        $blockedAll = false;
        foreach (ManagerIndicators::SECTIONS as $code => $def) {
            $period = ManagerIndicators::sectionPeriod($code, $quarter);
            if ($period === null) continue;
            $haccp = $this->haccp($period['from'], min($period['to'], $today), $plan);
            $section = ['code' => $code, 'weight' => $def['weight'], 'period' => $period, 'indicators' => [], 'attainment' => null, 'coefficient' => null,
                'presence' => null, 'share' => null, 'amount' => null, 'status' => 'ok', 'haccp_blocking' => $haccp['blocking'],
                'provisional' => $period['to'] > $today];
            $measured = $this->measure($period['from'], min($period['to'], $today), $plan);
            $atts = [];
            foreach ($def['indicators'] as $ind) {
                // Cel zatwierdzony przed początkiem okresu (wersja obowiązująca 00:00 pierwszego dnia).
                $objective = $this->objectiveAt($versions, $ind, $period['from'] . ' 00:00:00');
                $mv = $measured[$ind];
                $att = ManagerIndicators::attainment($ind, $objective['target'] ?? null, $mv['value']);
                $section['indicators'][] = ['code' => $ind, 'target' => $objective['target'] ?? null, 'validated_at' => $objective['validated_at'] ?? null,
                    'value' => $mv['value'], 'attainment' => $att, 'status' => ManagerIndicators::status($att), 'origin' => $mv['origin'],
                    'unverified' => $mv['unverified'], 'missing' => $mv['missing'], 'detail' => $mv['detail']];
                if ($objective === null && $section['status'] === 'ok') $section['status'] = 'no_objective';
                elseif ($mv['value'] === null && $section['status'] === 'ok') $section['status'] = 'missing_data';
                $atts[] = $att;
            }
            if ($section['status'] === 'ok' && in_array(null, $atts, true)) $section['status'] = 'missing_data';
            if ($section['status'] === 'ok') {
                $section['attainment'] = array_sum($atts) / count($atts);
                $section['coefficient'] = ManagerIndicators::coefficient($section['attainment']);
                $section['presence'] = $this->presence($plan, $period['from'], $period['to']);
                if ($envelope === null) $section['status'] = 'no_salary';
                else {
                    $b = ManagerIndicators::sectionBonus($envelope, $code, $section['presence'], $section['coefficient']);
                    $section['share'] = round($b['share'], 2);
                    $section['amount'] = round($b['amount'], 2);
                }
            }
            if ($haccp['blocking']) {
                $section['status'] = 'haccp_blocked';
                $section['amount'] = $section['amount'] === null ? null : 0.0;
                $blockedAll = true;
            }
            if ($section['amount'] !== null) $total += $section['amount'];
            $sections[] = $section;
        }
        $quarterPeriod = ManagerIndicators::sectionPeriod('production', $quarter);
        $haccp = $this->haccp($quarterPeriod['from'], min($quarterPeriod['to'], $today), $plan);
        return [
            'period' => $quarter, 'from' => $quarterPeriod['from'], 'to' => $quarterPeriod['to'], 'extracted_at' => date('Y-m-d H:i'),
            'plan' => $p, 'haccp' => $haccp + ['blocked' => $blockedAll], 'sections' => $sections, 'total' => round($total, 2),
            'missing' => array_map(fn($c) => ['code' => $c], $this->planGaps($plan)),
        ];
    }

    // --- Rozmowa ----------------------------------------------------------------------------------------------

    /**
     * Przygotowanie rozmowy : ostatnie 3 miesiące do $month włącznie — wyniki miesiąc po miesiącu, mocne strony
     * (zielone ≥ 2 z 3), odchylenia powtarzające się (pomarańczowe / czerwone ≥ 2 z 3), pytania, proponowane cele
     * (najlepszy miesiąc, jeśli lepszy od obecnego celu ; inaczej obecny cel), ostatni audyt kompetencji.
     */
    public function interview(string $month, ApiContext $context): array
    {
        $month = $this->checkMonth($month);
        $this->measured = [];
        $today = date('Y-m-d');
        if ($month . '-01' > $today) throw ApiException::validation([['field' => 'month', 'code' => 'IN_FUTURE', 'params' => []]]);
        $months = [];
        for ($i = 2; $i >= 0; $i--) $months[] = date('Y-m', strtotime($month . "-01 -{$i} months"));
        $plan = $this->repo->plan((int) substr($month, 0, 4));
        $versions = $plan ? $this->repo->objectives((int) $plan['id']) : [];
        $rows = [];
        foreach (ManagerIndicators::INDICATORS as $code => $def) {
            $series = [];
            foreach ($months as $ym) {
                $to = min(date('Y-m-t', strtotime($ym . '-01')), $today);
                $m = $this->measure($ym . '-01', $to, $plan)[$code];
                $objective = $this->objectiveAt($versions, $code, $to . ' 23:59:59');
                $att = ManagerIndicators::attainment($code, $objective['target'] ?? null, $m['value']);
                $series[] = ['month' => $ym, 'value' => $m['value'], 'target' => $objective['target'] ?? null, 'attainment' => $att,
                    'status' => ManagerIndicators::status($att), 'missing' => $m['missing']];
            }
            $statuses = array_column($series, 'status');
            $green = count(array_filter($statuses, fn($s) => $s === 'green'));
            $gaps = count(array_filter($statuses, fn($s) => $s === 'red' || $s === 'orange'));
            $current = $this->objectiveAt($versions, $code, date('Y-m-d H:i:s'));
            $valuesOk = array_values(array_filter(array_column($series, 'value'), fn($x) => $x !== null));
            $best = $valuesOk ? ($def['direction'] === 'higher' ? max($valuesOk) : min($valuesOk)) : null;
            $proposal = $current['target'] ?? null;
            $better = fn(float $a, float $b) => $def['direction'] === 'higher' ? $a > $b : $a < $b;
            if ($best !== null && ($proposal === null || $better($best, $proposal))) $proposal = $best;
            $rows[] = ['code' => $code, 'section' => $def['section'], 'unit' => $def['unit'], 'direction' => $def['direction'], 'series' => $series,
                'strength' => $green >= 2, 'recurring_gap' => $gaps >= 2, 'gap_months' => $gaps, 'missing_months' => count(array_filter(array_column($series, 'value'), fn($x) => $x === null)),
                'current_target' => $current['target'] ?? null, 'proposed_target' => $proposal, 'proposal_from' => $proposal === null ? null : ($proposal === ($current['target'] ?? null) ? 'current' : 'best_month')];
        }
        $audits = $this->repo->audits();
        return [
            'month' => $month, 'months' => $months, 'extracted_at' => date('Y-m-d H:i'), 'plan' => $this->presentPlan($plan, (int) substr($month, 0, 4)),
            'indicators' => $rows,
            'strengths' => array_values(array_map(fn($r) => $r['code'], array_filter($rows, fn($r) => $r['strength']))),
            'recurring_gaps' => array_values(array_map(fn($r) => ['code' => $r['code'], 'months' => $r['gap_months']], array_filter($rows, fn($r) => $r['recurring_gap']))),
            'no_data' => array_values(array_map(fn($r) => $r['code'], array_filter($rows, fn($r) => $r['missing_months'] >= 2))),
            'competency' => $audits ? $this->presentAudit($audits[0]) : null,
        ];
    }

    // --- Pulpit « Dzisiaj » ------------------------------------------------------------------------------------

    /**
     * « Moje wskaźniki » na stronie Dzisiaj osoby na stanowisku (plan roku wskazuje jej konto) : wskaźniki ostatniego
     * pełnego miesiąca (wynik, cel, status), blokady HACCP bieżącego kwartału, liczba wskaźników bez celu i bez danych.
     * Teksty po polsku z etykiet modułu (resources/lang/ui/pl/manager.json). null = użytkownik nie jest na stanowisku.
     */
    public function dashboard(int $userId): ?array
    {
        $month = date('Y-m', strtotime('first day of last month'));
        $plan = null;
        foreach (array_unique([(int) date('Y'), (int) substr($month, 0, 4)]) as $year) {
            $p = $this->repo->plan($year);
            if ($p && $p['holder_user_id'] !== null && (int) $p['holder_user_id'] === $userId) { $plan = $p; break; }
        }
        if ($plan === null) return null;
        $this->measured = [];
        $labels = $this->uiLabels('pl');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $versions = $this->repo->objectives((int) $plan['id']);
        $values = $this->measure($from, $to, $plan);
        $rows = [];
        foreach (ManagerIndicators::INDICATORS as $code => $def) {
            $objective = $this->objectiveAt($versions, $code, $to . ' 23:59:59');
            $m = $values[$code];
            $att = ManagerIndicators::attainment($code, $objective['target'] ?? null, $m['value']);
            $sign = $def['direction'] === 'higher' ? '≥ ' : '≤ ';
            $rows[] = ['code' => $code, 'section' => $labels['section.' . $def['section']] ?? $def['section'], 'name' => $labels['ind.' . $code] ?? $code,
                'value' => $m['value'] === null ? null : $this->valueText($labels, $def['unit'], $m['value']),
                'missing' => $m['missing'] === null ? null : ($labels['missing.' . $m['missing']] ?? $m['missing']),
                'target' => $objective === null ? null : $sign . $this->valueText($labels, $def['unit'], $objective['target']),
                'attainment' => $att, 'status' => ManagerIndicators::status($att), 'unverified' => $m['unverified']];
        }
        $quarterFrom = sprintf('%s-%02d-01', date('Y'), intdiv((int) date('n') - 1, 3) * 3 + 1);
        $haccp = $this->haccp($quarterFrom, date('Y-m-d'), $plan);
        $alerts = array_map(function ($b) use ($labels) {
            $text = $labels['alert.' . $b['code']] ?? $b['code'];
            foreach (['date' => $b['date'] ?? '', 'authority' => $b['authority'] ?? '—', 'score' => isset($b['score']) ? number_format($b['score'], 0, ',', ' ') : '',
                         'threshold' => isset($b['threshold']) ? number_format($b['threshold'], 0, ',', ' ') : ''] as $k => $v) $text = str_replace('{' . $k . '}', (string) $v, $text);
            return $text;
        }, $haccp['blocking']);
        return [
            'month' => $month, 'holder' => $plan['holder_name'], 'indicators' => $rows, 'haccp_alerts' => $alerts,
            'red' => count(array_filter($rows, fn($r) => $r['status'] === 'red')),
            'no_objective' => count(array_filter($rows, fn($r) => $r['target'] === null)),
            'no_data' => count(array_filter($rows, fn($r) => $r['value'] === null)),
        ];
    }

    private function valueText(array $labels, string $unit, float $v): string
    {
        $n = number_format($v, $unit === 'eur_carton' ? 2 : 1, ',', ' ');
        return $unit === '%' ? $n . ' %' : str_replace('{n}', $n, $labels['unit.' . $unit] ?? '{n}');
    }

    /** Etykiety widoku modułu (te same teksty co na stronie modułu). */
    private function uiLabels(string $lang): array
    {
        $file = dirname(__DIR__, 4) . "/public/resources/lang/ui/{$lang}/manager.json";
        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    // --- Audyt kompetencji ------------------------------------------------------------------------------------

    /** Kwestionariusz kompetencji stanowiska : obszary i pytania (teksty w etykietach widoku), historia audytów. */
    public function audits(ApiContext $context): array
    {
        return ['areas' => CompetencyQuestionnaire::AREAS, 'scale' => CompetencyQuestionnaire::SCALE,
            'expected' => array_combine(CompetencyQuestionnaire::questions(), array_map([CompetencyQuestionnaire::class, 'expected'], CompetencyQuestionnaire::questions())),
            'audits' => array_map(fn($a) => $this->presentAudit($a), $this->repo->audits()), 'can_report' => $context->can('prod.manager.report')];
    }

    /** Nowy audyt : { audit_date, assessed_name, assessed_user_id?, answers: { pytanie: { score 0–3, comment? } }, comment? }. */
    public function addAudit(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['audit_date', 'assessed_name', 'assessed_user_id', 'answers', 'comment'])
            ->date('audit_date', true, false)->string('assessed_name', 120)->integer('assessed_user_id', false, 1, null, true)->string('comment', 4000, false, true);
        $answers = $body['answers'] ?? null;
        $questions = CompetencyQuestionnaire::questions();
        $clean = [];
        if (!is_array($answers) || !$answers) $v->add('answers', 'REQUIRED');
        foreach (is_array($answers) ? $answers : [] as $q => $a) {
            if (!in_array($q, $questions, true)) { $v->add('answers.' . $q, 'UNKNOWN_FIELD'); continue; }
            $score = is_array($a) ? ($a['score'] ?? null) : null;
            if (!is_int($score) || $score < 0 || $score > 3) { $v->add('answers.' . $q, 'OUT_OF_RANGE', ['min' => 0, 'max' => 3]); continue; }
            $comment = is_array($a) && isset($a['comment']) && is_string($a['comment']) ? mb_substr(trim($a['comment']), 0, 500) : null;
            $clean[$q] = ['score' => $score, 'comment' => $comment !== '' ? $comment : null];
        }
        if (is_array($answers) && $answers && count($clean) === count($answers) && count($clean) < count($questions)) {
            $v->add('answers', 'INCOMPLETE', ['missing' => count($questions) - count($clean)]);
        }
        $v->throwIfInvalid();
        $id = $this->tx->execute(function () use ($v, $clean, $context) {
            $data = ['audit_date' => $v->value('audit_date'), 'assessed_name' => $v->value('assessed_name'), 'assessed_user_id' => $v->value('assessed_user_id'),
                'answers' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'comment' => $v->value('comment'), 'created_by' => $context->userId];
            $id = $this->repo->insertAudit($data);
            $this->audit->record($context, 'create', 'competency_audit', $id, null, ['assessed' => $data['assessed_name'], 'date' => $data['audit_date']]);
            return $id;
        });
        return $this->audits($context) + ['created_id' => $id];
    }

    // --- Pomiary ----------------------------------------------------------------------------------------------

    /**
     * Wskaźniki na [from, to] (pełne miesiące, do dzisiaj) : value, origin (erp / manual), unverified (wpis osoby
     * na stanowisku), missing (powód braku), detail (liczby źródłowe).
     */
    private function measure(string $from, string $to, ?array $plan): array
    {
        $key = $from . '|' . $to . '|' . ($plan['id'] ?? 0);
        if (isset($this->measured[$key])) return $this->measured[$key];
        $months = $this->monthsOf($from, $to);
        $rateRaw = $this->profitRepo->eurRate();
        $rate = $rateRaw > 0 ? $rateRaw : 1.0;
        $spec = ['key' => 'manager', 'months' => array_fill_keys($months, 1.0), 'from' => $from, 'to' => $to, 'end' => $to, 'current' => false, 'label' => '', 'elapsed' => 1.0];
        $c = $this->profit->compute($spec, $this->profit->sales($from, $to, $rate), [], $rate);
        $t = $c['totals'];
        $revenue = (float) $t['revenue'];
        $holder = $plan && $plan['holder_user_id'] !== null ? (int) $plan['holder_user_id'] : null;
        $manual = $this->repo->values($months[0], end($months));
        $erp = fn(?float $value, array $detail = []) => ['value' => $value, 'origin' => $value === null ? null : 'erp', 'unverified' => false, 'missing' => null, 'detail' => $detail];
        $none = fn(string $reason, array $detail = []) => ['value' => null, 'origin' => null, 'unverified' => false, 'missing' => $reason, 'detail' => $detail];
        // Wpis ręczny : średnia miesięcy okresu, tylko gdy każdy miesiąc ma wpis.
        $fromManual = function (string $code, string $reason) use ($manual, $months, $holder, $none) {
            $vals = [];
            $unverified = false;
            foreach ($months as $ym) {
                $m = $manual[$ym][$code] ?? null;
                if ($m === null) return $none($reason, ['manual_months' => count($vals), 'months' => count($months)]);
                $vals[] = $m['value'];
                if ($holder !== null && $m['entered_by'] === $holder) $unverified = true;
            }
            return ['value' => array_sum($vals) / count($vals), 'origin' => 'manual', 'unverified' => $unverified, 'missing' => null, 'detail' => ['months' => count($vals)]];
        };
        $out = [];
        $out['material_cost_pct'] = $revenue > 0 ? $erp((float) $t['materials_pct'], ['revenue' => round($revenue, 2), 'materials' => round((float) $t['materials'], 2)]) : $none('NO_SALES');
        $out['range_margin_pct'] = $revenue > 0 ? $erp((float) $t['gross_pct'], ['revenue' => round($revenue, 2), 'gross' => round((float) $t['gross'], 2)]) : $none('NO_SALES');

        $scrap = $this->repo->scrap($from, $to);
        $out['loss_pct'] = $scrap['operations'] > 0 && $scrap['good'] + $scrap['scrap'] > 0
            ? $erp($scrap['scrap'] / ($scrap['good'] + $scrap['scrap']) * 100, $scrap) : $fromManual('loss_pct', 'NO_TABLET_DATA');

        $threshold = $plan && $plan['margin_threshold_pct'] !== null ? (float) $plan['margin_threshold_pct'] : null;
        $production = $this->profit->production($from, $to, $rate);
        $byProduct = [];
        foreach ($production['groups'] as $g) foreach ($g['categories'] as $cat) foreach ($cat['products'] as $prod) $byProduct[$prod['id']] = $prod;
        if ($threshold === null) $out['below_threshold_pct'] = $none('NO_MARGIN_THRESHOLD');
        elseif ($production['total'] <= 0) $out['below_threshold_pct'] = $none('NO_SALES');
        else {
            $below = array_filter($byProduct, fn($x) => $x['margin_pct'] !== null && $x['margin_pct'] < $threshold);
            $out['below_threshold_pct'] = $erp(array_sum(array_column($below, 'revenue')) / $production['total'] * 100,
                ['products' => count($below), 'threshold' => $threshold, 'revenue_below' => round(array_sum(array_column($below, 'revenue')), 2)]);
        }

        // Nowe produkty rentowne po 6 miesiącach : wprowadzone 6 miesięcy przed okresem ; marża w okresie ≥ marża min.
        // z wprowadzenia (inaczej próg marży planu) ; bez sprzedaży w okresie = nierentowny.
        $cohortFrom = date('Y-m-d', strtotime($from . ' -' . self::NEW_PRODUCT_MONTHS . ' months'));
        $cohortTo = date('Y-m-d', strtotime($to . ' -' . self::NEW_PRODUCT_MONTHS . ' months'));
        $cohort = array_filter($this->repo->launches($cohortFrom), function ($l) use ($cohortFrom, $cohortTo) {
            $d = $l['launch_date'] ?? $l['created'];
            return $d !== null && $d >= $cohortFrom && $d <= $cohortTo;
        });
        if (!$cohort) $out['new_profitable_pct'] = $none('NO_NEW_PRODUCTS', ['from' => $cohortFrom, 'to' => $cohortTo]);
        else {
            $profitable = 0;
            $noThreshold = 0;
            foreach ($cohort as $l) {
                $min = $l['min_margin_pct'] !== null ? (float) $l['min_margin_pct'] : $threshold;
                if ($min === null) { $noThreshold++; continue; }
                $margin = $byProduct[(int) $l['product_id']]['margin_pct'] ?? null;
                if ($margin !== null && $margin >= $min) $profitable++;
            }
            $out['new_profitable_pct'] = $noThreshold > 0 ? $none('NO_MARGIN_THRESHOLD', ['products' => count($cohort)])
                : $erp($profitable / count($cohort) * 100, ['products' => count($cohort), 'profitable' => $profitable, 'from' => $cohortFrom, 'to' => $cohortTo]);
        }

        $reference = $plan['price_reference_date'] ?? null;
        $refDate = $reference ?? substr($from, 0, 4) . '-01-01';
        $index = $this->profit->priceIndex($from, $to, $refDate);
        $out['price_gap_pct'] = $index['gap_pct'] === null ? $none('NO_PRICES')
            : $erp($index['gap_pct'], ['reference_date' => $refDate, 'reference_default' => $reference === null, 'materials' => $index['materials']]);

        $out['stockouts'] = $fromManual('stockouts', 'MANUAL_ENTRY_MISSING');

        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $sold = $this->repo->cartonsSold($from, $to);
        $stock = $this->repo->cartonsInStock($to);
        $out['stock_days'] = $sold > 0 ? $erp($stock / ($sold / $days), ['stock_cartons' => $stock, 'sold_cartons' => $sold, 'days' => $days]) : $none('NO_SALES');

        // Koszt personelu na karton wyprodukowany : koszty z kalkulatora kosztów (bez miesięcy szacowanych).
        $made = $this->repo->cartonsMade($from, $to);
        $estimated = array_intersect_key($c['estimated_months'] ?? [], array_flip($months));
        $noCost = count(array_filter($c['warnings'], fn($w) => str_contains($w, 'Brak miesięcznych kosztów'))) > 0;
        if ($rateRaw <= 0) $out['staff_cost_per_carton'] = $none('NO_EUR_RATE');
        elseif ($noCost || $estimated) $out['staff_cost_per_carton'] = $none('NO_COST_MONTH', ['months' => array_keys($estimated)]);
        elseif ($made <= 0) $out['staff_cost_per_carton'] = $none('NO_PRODUCTION');
        else $out['staff_cost_per_carton'] = $erp((float) $t['staff'] / $made, ['staff' => round((float) $t['staff'], 2), 'cartons' => $made]);

        // Nadgodziny : godziny w tygodniu ISO na osobę ponad etat (week.fte_hours), średnio na miesiąc okresu.
        $clocks = $this->repo->clocks($from, $to);
        if (!$clocks) $out['overtime_hours'] = $fromManual('overtime_hours', 'NO_CLOCK_DATA');
        else {
            $fte = max(1.0, (float) $this->params->get('week.fte_hours', '40'));
            $weeks = [];
            foreach ($clocks as $k) {
                $w = $k['id_employee'] . '|' . date('o-W', strtotime($k['clock_in']));
                $weeks[$w] = ($weeks[$w] ?? 0.0) + max(0, strtotime($k['clock_out']) - strtotime($k['clock_in'])) / 3600;
            }
            $over = array_sum(array_map(fn($h) => max(0.0, $h - $fte), $weeks));
            $out['overtime_hours'] = $erp($over / count($months), ['overtime' => round($over, 2), 'person_weeks' => count($weeks), 'fte_hours' => $fte]);
        }

        $d = $this->repo->deliveries($from, $to);
        $out['otif_pct'] = $d['orders'] > 0 ? $erp($d['otif'] / $d['orders'] * 100, $d) : $none('NO_ORDERS');

        $costs = $this->repo->logisticsCosts($months);
        if ($rateRaw <= 0) $out['logistics_cost_pct'] = $none('NO_EUR_RATE');
        elseif (count($costs) < count($months)) $out['logistics_cost_pct'] = $none('NO_LOGISTICS_COST', ['months' => array_values(array_diff($months, array_keys($costs)))]);
        elseif ($revenue <= 0) $out['logistics_cost_pct'] = $none('NO_SALES');
        else $out['logistics_cost_pct'] = $erp(array_sum($costs) / $rate / $revenue * 100, ['cost_eur' => round(array_sum($costs) / $rate, 2), 'revenue' => round($revenue, 2)]);

        return $this->measured[$key] = $out;
    }

    /** Sygnały do sprawdzenia przed użyciem : zero przy wskaźniku kosztowym, zmiana ±50 % wobec poprzedniego miesiąca. */
    private function flags(string $code, array $m, array $previous): array
    {
        $flags = [];
        if ($m['value'] === null) return $flags;
        if (in_array($code, ['material_cost_pct', 'range_margin_pct', 'staff_cost_per_carton', 'logistics_cost_pct', 'otif_pct'], true) && abs($m['value']) < 1e-9) $flags[] = 'ZERO_VALUE';
        $p = $previous['value'];
        if ($p !== null && abs($p) > 1e-9 && abs($m['value'] - $p) / abs($p) > self::SHARP_CHANGE && $code !== 'price_gap_pct') $flags[] = 'SHARP_CHANGE';
        return $flags;
    }

    /** HACCP okresu : zdarzenia, blokady premii (kontrola niekorzystna, przerwanie nieobsłużone, audyt < progu), wpisy osoby na stanowisku. */
    private function haccp(string $from, string $to, ?array $plan): array
    {
        $threshold = $plan && $plan['audit_threshold'] !== null ? (float) $plan['audit_threshold'] : null;
        $events = $this->repo->haccpEvents($from, $to);
        $blocking = [];
        $unverified = [];
        foreach ($events as $e) {
            if ($e['kind'] === 'official_control' && $e['result'] === 'unfavorable') $blocking[] = ['code' => 'OFFICIAL_CONTROL_UNFAVORABLE', 'event_id' => (int) $e['id'], 'date' => $e['event_date'], 'authority' => $e['authority']];
            if ($e['kind'] === 'cold_chain_break' && !(int) $e['treated']) $blocking[] = ['code' => 'COLD_CHAIN_UNTREATED', 'event_id' => (int) $e['id'], 'date' => $e['event_date']];
            if ($e['kind'] === 'independent_audit' && $e['score'] !== null && $threshold !== null && (float) $e['score'] < $threshold) {
                $blocking[] = ['code' => 'AUDIT_BELOW_THRESHOLD', 'event_id' => (int) $e['id'], 'date' => $e['event_date'], 'score' => (float) $e['score'], 'threshold' => $threshold];
            }
            if ($plan && $plan['holder_user_id'] !== null && (int) $e['created_by'] === (int) $plan['holder_user_id']) $unverified[] = (int) $e['id'];
        }
        return ['events' => array_map(fn($e) => $this->presentHaccp($e, $plan), $events), 'blocking' => $blocking, 'unverified' => $unverified,
            'audit_threshold' => $threshold];
    }

    /** Nowe produkty miesiąca : wprowadzone w ostatnich 6 miesiącach — marża i wolumen na sklep vs cele, decyzja zalecana. */
    private function newProducts(string $from, string $to, ?array $plan): array
    {
        $since = date('Y-m-d', strtotime($to . ' -' . self::NEW_PRODUCT_MONTHS . ' months'));
        $rate = $this->profitRepo->eurRate();
        $production = $this->profit->production($from, $to, $rate > 0 ? $rate : 1.0);
        $byProduct = [];
        foreach ($production['groups'] as $g) foreach ($g['categories'] as $cat) foreach ($cat['products'] as $prod) $byProduct[$prod['id']] = $prod;
        $shops = $this->repo->productShops($from, $to);
        $weeks = ((strtotime($to) - strtotime($from)) / 86400 + 1) / 7;
        $out = [];
        foreach ($this->repo->launches($since) as $l) {
            $launch = $l['launch_date'] ?? $l['created'];
            if ($launch === null || $launch < $since || $launch > $to) continue;
            $id = (int) $l['product_id'];
            $p = $byProduct[$id] ?? null;
            $s = $shops[$id] ?? ['cartons' => 0.0, 'shops' => 0];
            $perShopWeek = $s['shops'] > 0 && $weeks > 0 ? $s['cartons'] / $s['shops'] / $weeks : 0.0;
            $age = (int) floor((strtotime($to) - strtotime($launch)) / 86400 / 7);
            $minMargin = $l['min_margin_pct'] !== null ? (float) $l['min_margin_pct'] : null;
            $expected = $l['expected_week_shop'] !== null ? (float) $l['expected_week_shop'] : null;
            $margin = $p['margin_pct'] ?? null;
            if ($minMargin === null && $expected === null) $recommendation = 'targets_missing';
            elseif ($age < self::TRACK_WEEKS_MIN) $recommendation = 'too_early';
            else {
                $marginOk = $minMargin === null || ($margin !== null && $margin >= $minMargin);
                $volumeOk = $expected === null || $perShopWeek >= $expected;
                $recommendation = $marginOk && $volumeOk ? 'keep' : (!$marginOk && !$volumeOk ? 'stop' : 'fix');
            }
            $out[] = ['product_id' => $id, 'name' => $l['name'], 'launch_date' => $launch, 'launch_from_erp' => $l['launch_date'] === null, 'weeks' => $age,
                'target_cost' => $l['target_cost'] !== null ? (float) $l['target_cost'] : null, 'target_price' => $l['target_price'] !== null ? (float) $l['target_price'] : null,
                'min_margin_pct' => $minMargin, 'expected_week_shop' => $expected,
                'margin_pct' => $margin, 'price_carton' => $p['price_carton'] ?? null, 'cost_carton' => $p && $p['cartons'] > 0 ? $p['materials'] / $p['cartons'] : null,
                'cartons' => $s['cartons'], 'shops' => $s['shops'], 'per_shop_week' => round($perShopWeek, 2),
                'recommendation' => $recommendation, 'decision' => $l['decision'], 'decision_note' => $l['decision_note']];
        }
        return $out;
    }

    /** Propozycja trzech działań : najsłabsze wskaźniki (czerwone, pomarańczowe, potem bez danych). */
    private function suggestActions(array $rows, ?array $plan, string $month): array
    {
        $rank = fn($r) => match ($r['status']) { 'red' => 0, 'orange' => 1, default => $r['value'] === null ? 2 : 3 };
        usort($rows, fn($a, $b) => [$rank($a), $a['attainment'] ?? 9] <=> [$rank($b), $b['attainment'] ?? 9]);
        $due = date('Y-m-t', strtotime($month . '-01 +1 month'));
        $owner = $plan['holder_name'] ?? null;
        $out = [];
        foreach ($rows as $r) {
            if ($rank($r) === 3 || count($out) === 3) break;
            $out[] = ['text' => '', 'owner' => $owner, 'due_date' => $due, 'status' => 'open', 'indicator' => $r['code'], 'kind' => $r['value'] === null ? 'data' : 'gap'];
        }
        return $out;
    }

    /** Braki planu, które blokują liczenie (premia, próg marży, audyt). */
    private function planGaps(?array $plan): array
    {
        if ($plan === null) return ['NO_PLAN'];
        $gaps = [];
        if ($plan['salary_monthly'] === null) $gaps[] = 'NO_SALARY';
        if ($plan['holder_start'] === null) $gaps[] = 'NO_START_DATE';
        if ($plan['margin_threshold_pct'] === null) $gaps[] = 'NO_MARGIN_THRESHOLD';
        if ($plan['audit_threshold'] === null) $gaps[] = 'NO_AUDIT_THRESHOLD';
        if ($plan['auditor'] === null) $gaps[] = 'NO_AUDITOR';
        if ($plan['price_reference_date'] === null) $gaps[] = 'NO_PRICE_REFERENCE';
        if ($plan['holder_user_id'] === null) $gaps[] = 'NO_HOLDER_ACCOUNT';
        return $gaps;
    }

    private function objectiveAt(array $versions, string $indicator, string $at): ?array
    {
        foreach ($versions as $v) {
            if ($v['indicator'] === $indicator && $v['validated_at'] !== null && $v['validated_at'] <= $at) return ['target' => (float) $v['target'], 'validated_at' => $v['validated_at']];
        }
        return null;
    }

    /** Obecność w okresie : od dnia rozpoczęcia pracy (brak daty = cały okres, sygnalizowane w brakach planu). */
    private function presence(?array $plan, string $from, string $to): float
    {
        $start = $plan['holder_start'] ?? null;
        if ($start === null || $start <= $from) return 1.0;
        if ($start > $to) return 0.0;
        return ((strtotime($to) - strtotime($start)) / 86400 + 1) / ((strtotime($to) - strtotime($from)) / 86400 + 1);
    }

    private function presentPlan(?array $plan, int $year): array
    {
        $hours = $plan && $plan['mission_hours'] !== null ? (json_decode($plan['mission_hours'], true) ?: []) : null;
        $salary = $plan && $plan['salary_monthly'] !== null ? (float) $plan['salary_monthly'] : null;
        $pct = $plan ? (float) $plan['envelope_pct'] : 20.0;
        $missionHours = [];
        foreach (ManagerIndicators::MISSIONS as $m) $missionHours[$m] = $hours !== null ? (isset($hours[$m]) ? (float) $hours[$m] : null) : (float) ManagerIndicators::DEFAULT_MISSION_HOURS[$m];
        $num = fn($k) => $plan && $plan[$k] !== null ? (float) $plan[$k] : null;
        return [
            'exists' => $plan !== null, 'year' => $year,
            'holder_user_id' => $plan && $plan['holder_user_id'] !== null ? (int) $plan['holder_user_id'] : null, 'holder_name' => $plan['holder_name'] ?? null,
            'holder_start' => $plan['holder_start'] ?? null, 'salary_monthly' => $salary, 'envelope_pct' => $pct, 'currency' => $plan['currency'] ?? 'PLN',
            'team_organisation' => $plan['team_organisation'] ?? null, 'auditor' => $plan['auditor'] ?? null, 'audit_threshold' => $num('audit_threshold'),
            'margin_threshold_pct' => $num('margin_threshold_pct'), 'price_reference_date' => $plan['price_reference_date'] ?? null,
            'workplace' => $plan['workplace'] ?? null, 'contract_type' => $plan['contract_type'] ?? null, 'working_time' => $plan['working_time'] ?? null,
            'monthly_hours' => $num('monthly_hours') ?? ($plan === null || $plan['mission_hours'] === null ? ManagerIndicators::DEFAULT_MONTHLY_HOURS : null),
            'mission_hours' => $missionHours, 'mission_hours_default' => $hours === null, 'mission_hours_total' => array_sum(array_filter($missionHours)),
            'envelope_annual' => $salary !== null ? round($salary * 12 * $pct / 100, 2) : null,
            'monthly_at_target' => $salary !== null ? round($salary * (1 + $pct / 100), 2) : null,
            'monthly_at_cap' => $salary !== null ? round($salary * (1 + $pct / 100 * ManagerIndicators::CAP), 2) : null,
            'updated_at' => $plan['updated_at'] ?? null,
        ];
    }

    private function presentHaccp(array $e, ?array $plan): array
    {
        return ['id' => (int) $e['id'], 'event_date' => $e['event_date'], 'kind' => $e['kind'], 'authority' => $e['authority'], 'result' => $e['result'],
            'score' => $e['score'] !== null ? (float) $e['score'] : null, 'treated' => (bool) $e['treated'], 'treated_at' => $e['treated_at'], 'description' => $e['description'],
            'unverified' => $plan && $plan['holder_user_id'] !== null && (int) $e['created_by'] === (int) $plan['holder_user_id']];
    }

    private function presentAudit(array $a): array
    {
        $answers = json_decode($a['answers'], true) ?: [];
        $areas = [];
        foreach (CompetencyQuestionnaire::AREAS as $area => $questions) {
            $scores = array_values(array_filter(array_map(fn($q) => $answers[$q]['score'] ?? null, $questions), fn($s) => $s !== null));
            $areas[$area] = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        }
        $all = array_values(array_filter(array_map(fn($x) => $x['score'] ?? null, $answers), fn($s) => $s !== null));
        // Odchylenia : pytania poniżej poziomu oczekiwanego (zwykle 2 — opanowane).
        $gaps = [];
        foreach ($answers as $q => $x) {
            if (isset($x['score']) && $x['score'] < CompetencyQuestionnaire::expected($q)) $gaps[] = ['question' => $q, 'score' => $x['score'], 'expected' => CompetencyQuestionnaire::expected($q)];
        }
        return ['id' => (int) $a['id'], 'audit_date' => $a['audit_date'], 'assessed_name' => $a['assessed_name'], 'assessed_user_id' => $a['assessed_user_id'] !== null ? (int) $a['assessed_user_id'] : null,
            'answers' => $answers, 'comment' => $a['comment'], 'areas' => $areas, 'score' => $all ? round(array_sum($all) / count($all), 2) : null, 'gaps' => $gaps];
    }

    private function haccpInput(array $body, bool $partial): array
    {
        $v = (new Validator($body, $partial))->only(['event_date', 'kind', 'authority', 'result', 'score', 'treated', 'treated_at', 'description'])
            ->date('event_date', true, false)->choice('kind', self::HACCP_KINDS)->string('authority', 80, false, true)
            ->choice('result', ['favorable', 'unfavorable'], false, true)->decimal('score', false, 0, 100, true)->boolean('treated')
            ->date('treated_at')->string('description', 2000, false, true);
        $kind = $v->value('kind', $body['kind'] ?? null);
        if (!$partial && $kind === 'official_control' && $v->value('result') === null) $v->add('result', 'REQUIRED');
        if (!$partial && $kind === 'independent_audit' && $v->value('score') === null) $v->add('score', 'REQUIRED');
        $v->throwIfInvalid();
        $data = $v->clean();
        if (array_key_exists('treated', $data)) $data['treated'] = (int) $data['treated'];
        return $data;
    }

    private function checkMonth(string $month): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw ApiException::validation([['field' => 'month', 'code' => 'INVALID_MONTH', 'params' => []]]);
        return $month;
    }

    private function checkYear(int $year): void
    {
        if ($year < 2020 || $year > 2100) throw ApiException::validation([['field' => 'year', 'code' => 'OUT_OF_RANGE', 'params' => ['min' => 2020, 'max' => 2100]]]);
    }

    /** @return list<string> miesiące 'YYYY-MM' okresu */
    private function monthsOf(string $from, string $to): array
    {
        $out = [];
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) $out[] = $m;
        return $out;
    }
}
