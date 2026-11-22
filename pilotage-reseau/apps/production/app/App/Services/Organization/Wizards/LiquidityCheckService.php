<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Finances\LiquidityCheckRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Payroll\PayrollService;

/**
 * Asystent « Płynność i gotówka » [373] (Kierownik operacyjny, chaque semaine ; obligations 2.1, 2.2 et 2.8 :
 * monitorowanie sytuacji finansowej, planowanie i zabezpieczanie środków, przeciwdziałanie brakowi środków) :
 *   1. Sytuacja : ce qui entre et ce qui sort pendant 4 semaines calendaires (la semaine en cours depuis aujourd'hui,
 *      puis 3 semaines lundi → dimanche), d'après l'ERP (voir data()) ; créances échues à part (non comptées) ;
 *   2. Plan : solde disponible saisi (banque zł, banque €, caisse ; autres dépenses connues par semaine), prévision
 *      recalculée, puis une décision par signal (voir signals()) : monitorować / działanie (note) / bez ryzyka ;
 *   3. Podsumowanie : solde, semaines à risque, décisions ; « Zakończ » écrit n_wizard_done — tâche cochée.
 * Montants en zł : les montants en € (proformas, commandes, banque €) sont convertis au cours des réglages de la société
 * (erp_company_other_settings.exchange_rate_euro, 1 s'il manque, comme les autres écrans). Montants visibles seulement
 * pour le profil Finanse et CEO (PageAccessService::seesOrderValues, [312]) : sinon ils sont retirés des données.
 * Lecture seule hors de n_liquidity_balance et n_liquidity_decision : aucun e-mail ni SMS.
 */
final class LiquidityCheckService
{
    public const CODE = 'liquidity_checked';
    public const WEEKS = 4;
    public const DECISIONS = ['monitor' => 'Monitorować', 'action' => 'Działanie', 'no_risk' => 'Bez ryzyka'];
    /** Décisions qui demandent une note (au moins 3 caractères : relance, report de paiement, crédit…). */
    public const NOTE_REQUIRED = ['action'];
    /** « Bientôt » pour un gros paiement fournisseur : dans les 14 jours. */
    public const BIG_PURCHASE_DAYS = 14;
    /** clé => [code n_prod_param, valeur par défaut, entier ?] */
    public const PARAMS = [
        'overdue_min' => ['liquidity.overdue_min', 5000, false],
        'big_purchase_min' => ['liquidity.big_purchase_min', 10000, false],
        'min_balance' => ['liquidity.min_balance', 0, false],
        'supplier_terms_days' => ['liquidity.supplier_terms_days', 14, true],
        'client_terms_days' => ['liquidity.client_terms_days', 14, true],
    ];
    private const MAX_AMOUNT = 100_000_000;
    /** Clés des montants retirés quand l'utilisateur ne voit pas les montants. */
    private const MONEY = ['amount', 'amount_orig', 'due_4w', 'overdue', 'later', 'open_value', 'in', 'out', 'net', 'end', 'opening', 'in_receivables',
        'in_orders', 'out_suppliers', 'out_payroll', 'out_other', 'in_4w', 'out_4w', 'overdue_total', 'min_end', 'end_last', 'bank_pln', 'bank_eur', 'cash_pln', 'other'];
    private const TYPES = ['invoice' => 'faktura zakupowa', 'po' => 'zamówienie u dostawcy', 'payroll' => 'wynagrodzenia i składki', 'other' => 'inne (wpisane)'];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private LiquidityCheckRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Seuils (zł) et délais (jours) de n_prod_param, bornés par ParamRules ; valeur invalide → défaut. */
    public function settings(): array
    {
        $out = [];
        foreach (self::PARAMS as $key => [$code, $default, $int]) {
            $raw = trim($this->params->get($code, (string) $default));
            $v = is_numeric(str_replace(',', '.', $raw)) ? (float) str_replace(',', '.', $raw) : (float) $default;
            if (isset(ParamRules::RANGES[$code])) {
                [$min, $max] = ParamRules::RANGES[$code];
                if ($v < $min || $v > $max) $v = (float) $default;
            }
            $out[$key] = $int ? (int) round($v) : round($v, 2);
        }
        return $out;
    }

    /** Lundi de la semaine d'un jour. */
    public static function monday(string $day): string
    {
        return self::addDays($day, 1 - (int) date('N', strtotime($day)));
    }

    /**
     * Les 4 semaines : la semaine en cours depuis aujourd'hui (jusqu'au dimanche), puis 3 semaines lundi → dimanche.
     * @return list<array{no:int, monday:string, from:string, to:string, label:string}>
     */
    public static function weeks(string $today): array
    {
        $monday = self::monday($today);
        $out = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $m = self::addDays($monday, 7 * $i);
            $from = $i === 0 ? $today : $m;
            $to = self::addDays($m, 6);
            $out[] = ['no' => $i + 1, 'monday' => $m, 'from' => $from, 'to' => $to, 'label' => self::dm($from) . ' – ' . self::dm($to)];
        }
        return $out;
    }

    /** Semaine (0–3) d'un jour, ou null hors de [aujourd'hui, dernier dimanche]. */
    public static function weekIndex(?string $day, array $weeks): ?int
    {
        if ($day === null) return null;
        foreach ($weeks as $i => $w) if ($day >= $w['from'] && $day <= $w['to']) return $i;
        return null;
    }

    /**
     * Données des trois étapes. Sources (LiquidityCheckRepository) et règles :
     *  - entrées : proformas non payées, échéance = date de la proforma + customer.payment_termin (sinon
     *    liquidity.client_terms_days) ; échéance passée = créance échue, montrée à part et NON comptée dans les entrées
     *    (prudence) ; commandes ouvertes sans proforma active, expédiées d'ici la fin de l'horizon : encaissement prévu
     *    à l'expédition + délai du client (« przewidywane ») ;
     *  - sorties : factures d'achat (n_inv, sauf rejetées) à échéance = émission + liquidity.supplier_terms_days (l'ERP
     *    n'enregistre ni échéance ni paiement fournisseur : une facture dont cette échéance est passée est supposée
     *    payée) ; commandes fournisseurs sans facture : livraison + même délai, valeur nette attendue ; liste de paie :
     *    net à la date d'exécution des virements (sinon le 10 du mois suivant, PayrollService::payBy), ZUS le 15 et PIT
     *    le 20 du mois suivant — totaux du mois approuvé, sinon du dernier mois approuvé (« szacunek ») ; autres
     *    dépenses saisies par semaine (étape 2) ;
     *  - prévision : saldo de départ (saisi) + cumul des flux nets ; semaine à risque = saldo de fin < liquidity.min_balance.
     */
    public function data(?string $today = null, bool $values = true): array
    {
        $today ??= date('Y-m-d');
        $set = $this->settings();
        $weeks = self::weeks($today);
        $monday = $weeks[0]['monday'];
        $last = $weeks[self::WEEKS - 1]['to'];
        $rateRaw = $this->repo->eurRate();
        $rate = $rateRaw > 0 ? $rateRaw : 1.0;
        $pln = fn(float $v, ?string $cur) => round(strtoupper((string) $cur) === 'EUR' ? $v * $rate : $v, 2);
        $amt = fn(?float $v) => $values && $v !== null ? ' ' . self::zl($v) : '';

        // --- Entrées : créances (proformas non payées) par client
        $rows = $this->repo->unpaidProformas();
        $reminders = $this->repo->reminderLevels(array_column($rows, 'id'));
        $clients = [];
        $inflows = [];
        $client = function (array $r) use (&$clients): int {
            $id = (int) $r['id_customer'];
            $clients[$id] ??= ['id' => $id, 'name' => (string) $r['company'], 'proformas' => 0, 'due_4w' => 0.0, 'overdue' => 0.0, 'overdue_count' => 0, 'max_days_over' => 0,
                'later' => 0.0, 'reminder' => null, 'open_orders' => 0, 'open_value' => 0.0, 'next_ship' => null, 'terms' => null];
            return $id;
        };
        foreach ($rows as $r) {
            $id = $client($r);
            $terms = $r['payment_termin'] !== null && $r['payment_termin'] !== '' ? (int) $r['payment_termin'] : $set['client_terms_days'];
            $issued = substr((string) $r['established_date'], 0, 10);
            $due = self::addDays($issued, $terms);
            $amount = $pln((float) $r['amount'], $r['currency']);
            $week = self::weekIndex($due, $weeks);
            $c = &$clients[$id];
            $c['proformas']++;
            $c['terms'] = $terms;
            if ($due < $today) {
                $c['overdue'] += $amount;
                $c['overdue_count']++;
                $c['max_days_over'] = max($c['max_days_over'], self::diff($due, $today));
            } elseif ($week !== null) {
                $c['due_4w'] += $amount;
                $inflows[] = ['type' => 'proforma', 'id' => (int) $r['id'], 'label' => 'proforma ' . ($r['doc_num'] ?: '#' . $r['id']), 'client' => $c['name'], 'date' => $due, 'week' => $week, 'amount' => $amount];
            } else {
                $c['later'] += $amount;
            }
            $rem = $reminders[(int) $r['id']] ?? null;
            if ($rem && ($c['reminder'] === null || $rem['level'] > $c['reminder']['level'])) $c['reminder'] = ['level' => $rem['level'], 'at' => self::dm(substr($rem['last_at'], 0, 10))];
            unset($c);
        }
        // Commandes ouvertes : client qui continue de commander ; sans proforma active = encaissement prévu.
        $ordersForecast = 0;
        foreach ($this->repo->openOrders() as $o) {
            $id = $client($o);
            $value = $pln((float) $o['value'], $o['currency']);
            $ship = self::date($o['shipping_date']);
            $c = &$clients[$id];
            $c['open_orders']++;
            $c['open_value'] += $value;
            if ($ship !== null && $ship >= $today && ($c['next_ship'] === null || $ship < $c['next_ship'])) $c['next_ship'] = $ship;
            unset($c);
            if ($o['id_proforma'] !== null || $ship === null || $ship < $today || $value <= 0) continue;
            $terms = $o['payment_termin'] !== null && $o['payment_termin'] !== '' ? (int) $o['payment_termin'] : $set['client_terms_days'];
            $expected = self::addDays($ship, $terms);
            $week = self::weekIndex($expected, $weeks);
            if ($week === null) continue;
            $ordersForecast++;
            $inflows[] = ['type' => 'order', 'id' => (int) $o['id'], 'label' => 'zamówienie #' . $o['id'] . ' (bez proformy, wysyłka ' . self::dm($ship) . ')',
                'client' => (string) $o['company'], 'date' => $expected, 'week' => $week, 'amount' => $value];
        }

        // --- Sorties
        $terms = $set['supplier_terms_days'];
        $outflows = [];
        foreach ($this->repo->supplierInvoices(self::addDays($today, -$terms), self::addDays($last, -$terms)) as $i) {
            $due = self::addDays(substr((string) $i['issue_date'], 0, 10), $terms);
            $week = self::weekIndex($due, $weeks);
            if ($week === null) continue;
            $outflows[] = ['type' => 'invoice', 'id' => (int) $i['id'], 'label' => 'faktura ' . ($i['number'] ?: '#' . $i['id']) . ($i['kind'] === 'service' ? ' (usługa)' : ''),
                'supplier' => (string) $i['supplier'], 'supplier_key' => self::supplierKey($i), 'date' => $due, 'week' => $week, 'amount' => $pln((float) $i['amount'], $i['currency'])];
        }
        foreach ($this->repo->purchaseOrdersWithoutInvoice(self::addDays($today, -$terms), self::addDays($last, -$terms)) as $p) {
            $due = self::addDays(substr((string) $p['delivery_date'], 0, 10), $terms);
            $week = self::weekIndex($due, $weeks);
            if ($week === null || (float) $p['amount'] <= 0) continue;
            $outflows[] = ['type' => 'po', 'id' => (int) $p['id'], 'label' => 'zamówienie ' . $p['code'] . ' (dostawa ' . self::dm(substr((string) $p['delivery_date'], 0, 10)) . ', bez faktury, netto)',
                'supplier' => (string) $p['supplier'], 'supplier_key' => 's' . (int) $p['id_supplier'], 'date' => $due, 'week' => $week, 'amount' => round((float) $p['amount'], 2)];
        }
        $payroll = $this->payroll($today, $weeks);
        foreach ($payroll['items'] as $p) $outflows[] = $p;
        $bal = $this->repo->balance($monday);
        $other = [0.0, 0.0, 0.0, 0.0];
        if ($bal) {
            foreach ($weeks as $k => $w) {
                $other[$k] = round((float) $bal['other_out_' . ($k + 1)], 2);
                if ($other[$k] > 0) $outflows[] = ['type' => 'other', 'id' => null, 'label' => 'inne wydatki (wpisane w kroku 2)', 'supplier' => '—', 'supplier_key' => null,
                    'date' => $w['from'], 'week' => $k, 'amount' => $other[$k]];
            }
        }
        usort($outflows, fn($a, $b) => [$a['date'], -$a['amount']] <=> [$b['date'], -$b['amount']]);
        usort($inflows, fn($a, $b) => [$a['date'], -$a['amount']] <=> [$b['date'], -$b['amount']]);

        // --- Prévision par semaine
        $opening = $bal ? round((float) $bal['bank_pln'] + (float) $bal['bank_eur'] * $rate + (float) $bal['cash_pln'], 2) : null;
        $forecast = [];
        $cum = 0.0;
        foreach ($weeks as $k => $w) {
            $sum = fn(array $list, array $types) => round(array_sum(array_map(fn($x) => $x['amount'], array_filter($list, fn($x) => $x['week'] === $k && in_array($x['type'], $types, true)))), 2);
            $f = $w + ['in_receivables' => $sum($inflows, ['proforma']), 'in_orders' => $sum($inflows, ['order']), 'out_suppliers' => $sum($outflows, ['invoice', 'po']),
                'out_payroll' => $sum($outflows, ['payroll']), 'out_other' => $sum($outflows, ['other'])];
            $f['in'] = round($f['in_receivables'] + $f['in_orders'], 2);
            $f['out'] = round($f['out_suppliers'] + $f['out_payroll'] + $f['out_other'], 2);
            $f['net'] = round($f['in'] - $f['out'], 2);
            $cum += $f['net'];
            $f['end'] = $opening !== null ? round($opening + $cum, 2) : null;
            $f['risk'] = $f['end'] !== null && $f['end'] < $set['min_balance'];
            $f['negative_flow'] = $f['net'] < 0;
            $forecast[] = $f;
        }

        $signals = $this->signals($clients, $outflows, $forecast, $opening, $set, $today, $amt);
        $decided = $this->repo->decisions($monday);
        foreach ($signals as &$s) {
            $d = $decided[$s['key']] ?? null;
            $s['decision'] = $d ? ['decision' => $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? $d['decision'], 'note' => $d['note'], 'by' => $d['by_name'],
                'at' => self::dm(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])] : null;
        }
        unset($s);

        $clientList = array_values(array_filter($clients, fn($c) => $c['proformas'] > 0));
        foreach ($clientList as &$c) foreach (['due_4w', 'overdue', 'later', 'open_value'] as $k) $c[$k] = round($c[$k], 2);
        unset($c);
        usort($clientList, fn($a, $b) => [$b['overdue'], $b['due_4w'], $a['name']] <=> [$a['overdue'], $a['due_4w'], $b['name']]);
        $count = fn(string $dec) => count(array_filter($signals, fn($s) => ($s['decision']['decision'] ?? null) === $dec));
        $ends = array_values(array_filter(array_column($forecast, 'end'), fn($v) => $v !== null));
        $totals = ['in_4w' => round(array_sum(array_column($forecast, 'in')), 2), 'out_4w' => round(array_sum(array_column($forecast, 'out')), 2),
            'proformas_4w' => count(array_filter($inflows, fn($x) => $x['type'] === 'proforma')), 'orders_4w' => $ordersForecast,
            'payables_4w' => count(array_filter($outflows, fn($x) => in_array($x['type'], ['invoice', 'po'], true))),
            'overdue_total' => round(array_sum(array_column($clientList, 'overdue')), 2), 'overdue_clients' => count(array_filter($clientList, fn($c) => $c['overdue'] > 0)),
            'opening' => $opening, 'end_last' => $ends ? end($ends) : null, 'min_end' => $ends ? min($ends) : null,
            'risk_weeks' => count(array_filter($forecast, fn($f) => $f['risk'])), 'negative_weeks' => count(array_filter($forecast, fn($f) => $f['negative_flow'])),
            'signals' => count($signals), 'decided' => count(array_filter($signals, fn($s) => $s['decision'] !== null)),
            'monitor' => $count('monitor'), 'action' => $count('action'), 'no_risk' => $count('no_risk')];
        $totals['waiting'] = $totals['signals'] - $totals['decided'];
        $done = $this->wizards->done(self::CODE, $monday, self::addDays($monday, 7));
        $out = ['today' => $today, 'label' => self::label($today), 'week_start' => $monday, 'weeks' => $forecast, 'horizon' => self::dm($today) . ' – ' . self::dm($last),
            'settings' => $set, 'rate' => $rateRaw > 0 ? $rateRaw : null, 'rate_missing' => $rateRaw <= 0, 'values' => $values,
            'clients' => $clientList, 'inflows' => $inflows, 'outflows' => array_map(fn($x) => $x + ['type_label' => self::TYPES[$x['type']] ?? $x['type']], $outflows),
            'payroll' => ['missing' => $payroll['missing'], 'estimate' => $payroll['estimate']],
            'balance' => $bal ? ['saved' => true, 'date' => (string) $bal['balance_date'], 'date_label' => self::label((string) $bal['balance_date']),
                'bank_pln' => (float) $bal['bank_pln'], 'bank_eur' => (float) $bal['bank_eur'], 'cash_pln' => (float) $bal['cash_pln'], 'other' => $other, 'note' => $bal['note'],
                'by' => $bal['by_name'], 'at' => self::dm(substr((string) $bal['updated_at'], 0, 10)) . ', ' . self::hm((string) $bal['updated_at'])] : ['saved' => false],
            'signals' => $signals, 'totals' => $totals, 'can_finish' => $bal !== null && $totals['waiting'] === 0, 'summary' => self::summary($totals, $bal !== null),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'big_purchase_days' => self::BIG_PURCHASE_DAYS,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
        return $values ? $out : self::mask($out);
    }

    /**
     * Liste de paie des mois dont une échéance tombe dans l'horizon : net (exécution des virements, sinon
     * PayrollService::payBy), ZUS le 15 et PIT le 20 du mois suivant (comme PayrollService::month). Totaux du mois
     * approuvé, sinon du dernier mois approuvé (estimation) ; aucun mois approuvé = échéance non chiffrée (missing).
     * Seuls les totaux de la liste (jamais une personne) sont lus.
     * @return array{items: list<array>, missing: list<string>, estimate: list<string>}
     */
    private function payroll(string $today, array $weeks): array
    {
        $last = $weeks[self::WEEKS - 1]['to'];
        $from = substr(self::addDays($today, -45), 0, 7);
        $to = substr($last, 0, 7);
        $runs = $this->repo->payRuns($from, $to);
        $items = [];
        $missing = [];
        $estimate = [];
        for ($m = $from; $m <= $to; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $next = date('Y-m', strtotime($m . '-01 +1 month'));
            $run = $runs[$m] ?? null;
            $approved = $run !== null && $run['status'] === 'approved' && $run['totals'] !== null;
            $dates = [['net', 'Wynagrodzenia netto', $approved && $run['exec_date'] ? substr((string) $run['exec_date'], 0, 10) : PayrollService::payBy($m)],
                ['zus_all', 'ZUS (składki)', $next . '-15'], ['pit', 'Zaliczka PIT', $next . '-20']];
            $due = array_values(array_filter($dates, fn($d) => self::weekIndex($d[2], $weeks) !== null));
            if (!$due) continue;
            $totals = $approved ? $run['totals'] : ($this->repo->lastApprovedRun($m)['totals'] ?? null);
            $label = self::MONTHS[(int) substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
            if ($totals === null) { $missing[] = $label; continue; }
            if (!$approved) $estimate[] = $label;
            foreach ($due as [$key, $name, $date]) {
                $v = round((float) ($totals[$key] ?? 0), 2);
                if ($v <= 0) continue;
                $items[] = ['type' => 'payroll', 'id' => null, 'label' => $name . ' — ' . $label . ($approved ? '' : ' (szacunek z ostatniej zatwierdzonej listy)'),
                    'supplier' => 'pracownicy, ZUS, US', 'supplier_key' => null, 'date' => $date, 'week' => self::weekIndex($date, $weeks), 'amount' => $v];
            }
        }
        return ['items' => $items, 'missing' => $missing, 'estimate' => array_values(array_unique($estimate))];
    }

    /**
     * Signaux (un par client, fournisseur ou semaine), du plus grave au moins grave :
     *  - week:<lundi> (week_gap) : saldo prévu en fin de semaine sous liquidity.min_balance — seulement quand le solde
     *    de la semaine est saisi (avant, la prévision n'a pas de point de départ) ;
     *  - client:<id> : overdue = créances échues ≥ liquidity.overdue_min ; ordering = créances échues (tout montant) et
     *    commandes ouvertes (le client continue de commander) ;
     *  - supplier:<clé> (big_purchase) : factures et commandes du même fournisseur à payer dans les 14 jours, somme ≥
     *    liquidity.big_purchase_min.
     * @return list<array{key:string, type:string, title:string, codes:list<string>, reasons:list<string>, amount:?float}>
     */
    private function signals(array $clients, array $outflows, array $forecast, ?float $opening, array $set, string $today, callable $amt): array
    {
        $out = [];
        if ($opening !== null) {
            foreach ($forecast as $f) {
                if (!$f['risk']) continue;
                $main = array_filter(['dostawcy' . $amt($f['out_suppliers']) => $f['out_suppliers'], 'wynagrodzenia, ZUS, PIT' . $amt($f['out_payroll']) => $f['out_payroll'],
                    'inne' . $amt($f['out_other']) => $f['out_other']], fn($v) => $v > 0);
                arsort($main);
                $out[] = ['key' => 'week:' . $f['monday'], 'type' => 'week', 'title' => 'Tydzień ' . $f['no'] . ' (' . $f['label'] . ')', 'codes' => ['week_gap'], 'amount' => $f['end'],
                    'reasons' => [($f['end'] < 0 ? 'saldo ujemne na koniec tygodnia' : 'saldo na koniec tygodnia poniżej poduszki' . $amt($set['min_balance'])) . $amt($f['end']),
                        'wydatki' . $amt($f['out']) . ($main ? ' (' . implode(', ', array_keys($main)) . ')' : '') . ' · wpływy' . $amt($f['in'])]];
            }
        }
        $cl = [];
        foreach ($clients as $c) {
            $codes = [];
            if ($c['overdue'] > 0 && $c['overdue'] >= $set['overdue_min']) $codes[] = 'overdue';
            if ($c['overdue'] > 0 && $c['open_orders'] > 0) $codes[] = 'ordering';
            if (!$codes) continue;
            $reasons = ['zaległe' . $amt(round($c['overdue'], 2)) . ' : ' . $c['overdue_count'] . ' ' . self::pl($c['overdue_count'], 'proforma', 'proformy', 'proform')
                . ', najstarsza ' . $c['max_days_over'] . ' ' . self::pl($c['max_days_over'], 'dzień', 'dni', 'dni') . ' po terminie'
                . ($c['reminder'] ? ' · ostatnie przypomnienie : poziom ' . $c['reminder']['level'] . ' (' . $c['reminder']['at'] . ')' : ' · bez przypomnienia')];
            if (in_array('ordering', $codes, true)) {
                $reasons[] = 'nadal zamawia : ' . $c['open_orders'] . ' ' . self::pl($c['open_orders'], 'zamówienie otwarte', 'zamówienia otwarte', 'zamówień otwartych')
                    . $amt(round($c['open_value'], 2)) . ($c['next_ship'] ? ', najbliższa wysyłka ' . self::label($c['next_ship']) : '');
            }
            $cl[] = ['key' => 'client:' . $c['id'], 'type' => 'client', 'title' => $c['name'], 'codes' => $codes, 'reasons' => $reasons, 'amount' => round($c['overdue'], 2)];
        }
        usort($cl, fn($a, $b) => [$b['amount'], $a['title']] <=> [$a['amount'], $b['title']]);
        $soon = self::addDays($today, self::BIG_PURCHASE_DAYS);
        $sup = [];
        foreach ($outflows as $o) {
            if (!in_array($o['type'], ['invoice', 'po'], true) || $o['date'] >= $soon || $o['supplier_key'] === null) continue;
            $s = &$sup[$o['supplier_key']];
            $s ??= ['name' => $o['supplier'], 'sum' => 0.0, 'docs' => []];
            $s['sum'] += $o['amount'];
            $s['docs'][] = $o;
            unset($s);
        }
        $sp = [];
        foreach ($sup as $key => $s) {
            if ($s['sum'] < $set['big_purchase_min']) continue;
            $docs = array_map(fn($d) => $d['label'] . ' — termin ' . self::dm($d['date']) . $amt($d['amount']), array_slice($s['docs'], 0, 3));
            $sp[] = ['key' => 'supplier:' . $key, 'type' => 'supplier', 'title' => $s['name'], 'codes' => ['big_purchase'], 'amount' => round($s['sum'], 2),
                'reasons' => ['do zapłaty w ' . self::BIG_PURCHASE_DAYS . ' dni' . $amt(round($s['sum'], 2)) . ' : ' . count($s['docs']) . ' ' . self::pl(count($s['docs']), 'dokument', 'dokumenty', 'dokumentów'),
                    implode(' · ', $docs) . (count($s['docs']) > 3 ? ' · …' : '')]];
        }
        usort($sp, fn($a, $b) => [$b['amount'], $a['title']] <=> [$a['amount'], $b['title']]);
        return [...$out, ...$cl, ...$sp];
    }

    /** Étape 2 : solde disponible (banque zł, banque €, caisse) et autres dépenses connues des semaines 1 à 4. */
    public function saveBalance(array $in, ?int $userId, bool $values = true, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $monday = self::monday($today);
        if (trim((string) ($in['bank_pln'] ?? '')) === '') throw new CustomException('Wpisz saldo rachunku w zł (0, jeśli brak).', 'errors', 422);
        $v = ['bank_pln' => self::amount($in['bank_pln'] ?? null, 'Saldo rachunku (zł)', true), 'bank_eur' => self::amount($in['bank_eur'] ?? null, 'Saldo rachunku (€)', true),
            'cash_pln' => self::amount($in['cash_pln'] ?? null, 'Kasa', false), 'other' => []];
        $other = is_array($in['other'] ?? null) ? array_values($in['other']) : [];
        for ($i = 0; $i < self::WEEKS; $i++) $v['other'][] = self::amount($other[$i] ?? null, 'Inne wydatki, tydzień ' . ($i + 1), false);
        $date = is_string($in['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date']) ? $in['date'] : $today;
        if ($date > $today || $date < $monday) throw new CustomException('Data salda : od poniedziałku tego tygodnia do dziś.', 'errors', 422);
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $this->repo->saveBalance($monday, $date, $v, $note !== '' ? $note : null, $userId);
        return $this->data($today, $values);
    }

    /** Étape 2 : décision pour un signal (une par signal et par semaine, la dernière compte). */
    public function saveDecision(array $in, ?int $userId, bool $values = true, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : monitorować, działanie albo bez ryzyka.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException('Działanie : wpisz notatkę (przypomnienie, przesunięcie płatności, kredyt…).', 'errors', 422);
        }
        $signal = array_values(array_filter($this->data($today)['signals'], fn($s) => $s['key'] === $key))[0] ?? null;
        if ($signal === null) throw new CustomException('Tego sygnału nie ma na liście tygodnia.', 'errors', 422);
        $this->repo->saveDecision(self::monday($today), $signal, $decision, $note !== '' ? $note : null, $userId);
        return $this->data($today, $values);
    }

    /** Étape 3 : « Zakończ » — solde saisi et une décision pour chaque signal ; la tâche du poste se coche seule. */
    public function finish(?int $userId, bool $values = true, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        if (!$d['balance']['saved']) throw new CustomException('Najpierw wpisz saldo dostępne (krok 2).', 'errors', 422);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw podejmij decyzję dla ' . $w . ' ' . self::pl($w, 'sygnału', 'sygnałów', 'sygnałów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today, $values)];
    }

    /** Résumé sans montant (carte de la tâche, visible hors profil Finanse) : « 4 tyg. · 1 tydzień z ryzykiem · 3 sygnały : … ». */
    public static function summary(array $t, bool $balance): string
    {
        $s = '4 tyg.' . ($balance ? '' : ' · saldo niewpisane');
        $s .= ' · ' . ($t['risk_weeks'] ? $t['risk_weeks'] . ' ' . self::pl($t['risk_weeks'], 'tydzień', 'tygodnie', 'tygodni') . ' z ryzykiem' : 'bez tygodnia z ryzykiem');
        if ($t['signals'] === 0) return $s . ' · brak sygnałów';
        $s .= ' · ' . $t['signals'] . ' ' . self::pl($t['signals'], 'sygnał', 'sygnały', 'sygnałów');
        $parts = [];
        if ($t['action']) $parts[] = $t['action'] . ' ' . self::pl($t['action'], 'działanie', 'działania', 'działań');
        if ($t['monitor']) $parts[] = $t['monitor'] . ' monitorować';
        if ($t['no_risk']) $parts[] = $t['no_risk'] . ' bez ryzyka';
        return $s . ($parts ? ' : ' . implode(' · ', $parts) : '');
    }

    /** Retire les montants (profil sans accès aux montants). */
    public static function mask(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY, true)) $a[$k] = is_array($v) ? array_map(fn() => null, $v) : null;
            elseif (is_array($v)) $a[$k] = self::mask($v);
        }
        return $a;
    }

    /** Montant saisi (« 12 500,50 », nombre) ; vide = 0 ; négatif seulement pour un compte (découvert). */
    private static function amount(mixed $v, string $label, bool $negative): float
    {
        if ($v === null || (is_string($v) && trim($v) === '')) return 0.0;
        if (is_string($v)) $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($v));
        if (!is_numeric($v)) throw new CustomException($label . ' : wpisz liczbę.', 'errors', 422);
        $f = round((float) $v, 2);
        if (!$negative && $f < 0) throw new CustomException($label . ' : nie może być ujemne.', 'errors', 422);
        if (abs($f) > self::MAX_AMOUNT) throw new CustomException($label . ' : kwota za duża.', 'errors', 422);
        return $f;
    }

    private static function supplierKey(array $i): string
    {
        if ((int) ($i['id_supplier'] ?? 0) > 0) return 's' . (int) $i['id_supplier'];
        if (trim((string) ($i['seller_nip'] ?? '')) !== '') return 'n' . preg_replace('/\D/', '', (string) $i['seller_nip']);
        return 'x' . substr(md5(mb_strtolower(trim((string) $i['supplier']))), 0, 12);
    }

    public static function zl(float $v): string
    {
        return ($v < 0 && round(abs($v)) > 0 ? '−' : '') . number_format(abs(round($v)), 0, ',', "\u{00A0}") . "\u{00A0}zł";
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function dm(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : date('j.m', $t);
    }

    private static function date(mixed $v): ?string
    {
        $v = $v === null ? '' : substr((string) $v, 0, 10);
        return $v === '' || $v === '0000-00-00' ? null : $v;
    }

    private static function diff(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }

    private static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
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
