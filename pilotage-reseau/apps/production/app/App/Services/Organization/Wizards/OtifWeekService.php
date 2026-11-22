<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Core\Helpers\ComplaintReasonTranslator;
use App\Domain\Client\Order\CustomerOrderStatus;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OtifWeekRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Terminowość tygodnia (OTIF) » [375] (Kierownik operacyjny, chaque semaine ; obligation 4.3 : kontrolowanie
 * terminowości realizacji zamówień i dostaw — ZARZĄDZANIE) :
 *   1. Wynik tygodnia : commandes clients (sans gratis) dues la semaine écoulée (lundi → dimanche) — % à l'heure,
 *      % complètes, OTIF, objectif otif.target_pct, tendance des 4 semaines précédentes, par jour, par client, par cause ;
 *   2. Przyczyny : commandes non OTIF avec leurs causes et les décisions déjà prises dans les assistants n°1, 3 et 5
 *      (lecture seule) ; une action par cause principale : akceptowane (jednorazowe), działanie korygujące (note,
 *      responsable / poste optionnels), do obserwacji (n_otif_week_action) ;
 *   3. Podsumowanie : « Zakończ » fige le résultat (n_otif_week) et écrit n_wizard_done — la tâche du poste se coche.
 * Définitions (voir evaluate()) :
 *  - à l'heure (OT) : expédiée au plus tard à la date d'expédition (customer_order.expected_shipping_date), d'après le
 *    premier jour réel d'expédition connu — heure d'enregistrement des documents (shipment_log.insert_timestamp) ou
 *    passage « W transporcie » dans l'historique, sinon date du document (shipment_log.shipment_date, WDT
 *    invoiced_date), sinon livraison confirmée sur la tablette chauffeur (au plus otif.delivery_days jours après) ; et pas
 *    livrée en retard (livraison confirmée plus de otif.delivery_days jours après la date, retard signalé par le
 *    chauffeur) ni refusée / client fermé ; date déplacée hors de la semaine = pas à l'heure pour la semaine d'origine ;
 *  - complète (IF) : pour chaque produit de la commande, cartons de la WDT (document_wdt_product, construits à partir des
 *    palettes) ≥ cartons commandés par toutes les commandes de la même WDT (une WDT = un client et un jour) ; manque ou
 *    dommage signalé à la livraison = incomplète ; sans WDT ou WDT sans lignes = « brak danych » ;
 *  - OTIF : à l'heure ET complète ; une commande dont une des deux mesures manque (et l'autre n'est pas en échec) est
 *    hors du calcul (« brak danych »). customer_order_product.expected_quantity_carton n'est pas utilisé : l'ERP l'écrit
 *    égal à quantity_carton à chaque enregistrement (OrderService), il ne dit rien des quantités expédiées.
 * Lecture seule hors de n_otif_week_action et n_otif_week : aucun e-mail ni SMS, aucune commande modifiée. Pas de montants.
 */
final class OtifWeekService
{
    public const CODE = 'otif_week_reviewed';
    public const TREND_WEEKS = 4;
    public const DECISIONS = ['accepted' => 'Akceptowane (jednorazowe)', 'corrective' => 'Działanie korygujące', 'watch' => 'Do obserwacji'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['corrective'];
    /** Causes, dans l'ordre de priorité (la première d'une commande est sa cause principale). */
    public const CAUSES = [
        'moved' => 'Termin przesunięty poza tydzień',
        'not_shipped' => 'Nie wysłane',
        'shipped_late' => 'Wysłane po terminie',
        'delivery_failed' => 'Dostawa nieudana (odmowa, klient zamknięty)',
        'delivered_late' => 'Dostarczone z opóźnieniem',
        'short_shipped' => 'Niekompletne (WDT mniej niż zamówienie)',
        'delivery_shortage' => 'Brak lub uszkodzenie przy dostawie',
    ];
    /** clé => [code n_prod_param, défaut, entier ?] */
    public const PARAMS = ['target_pct' => ['otif.target_pct', 95, false], 'delivery_days' => ['otif.delivery_days', 1, true]];
    private const SOURCES = ['log' => 'dokumenty wysyłki', 'status' => 'oznaczone « W transporcie »', 'document' => 'data dokumentu WDT', 'delivery' => 'dostawa potwierdzona'];
    private const PRIOR = ['orders_day' => 'Zamówienia dnia', 'supplements_day' => 'Domówienia dnia', 'delivery_delays' => 'Opóźnienia dostaw'];
    private const STOP_REASONS = ['closed' => 'klient zamknięty', 'refused' => 'odmowa przyjęcia', 'damaged' => 'towar uszkodzony', 'missing' => 'brak towaru',
        'late' => 'spóźnienie', 'other' => 'inny problem'];
    /** Recherche des dates déplacées : changements enregistrés jusqu'à tant de jours avant la fenêtre. */
    private const MOVED_LOOKBACK_DAYS = 60;
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private OtifWeekRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Jours ouvrés (week.production_days) et réglages otif.* bornés par ParamRules (valeur invalide → défaut). */
    public function settings(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        $out = ['workdays' => array_values(array_unique($days))];
        foreach (self::PARAMS as $key => [$code, $default, $int]) {
            $raw = trim($this->params->get($code, (string) $default));
            $v = is_numeric(str_replace(',', '.', $raw)) ? (float) str_replace(',', '.', $raw) : (float) $default;
            if (isset(ParamRules::RANGES[$code])) {
                [$min, $max] = ParamRules::RANGES[$code];
                if ($v < $min || $v > $max) $v = (float) $default;
            }
            $out[$key] = $int ? (int) round($v) : round($v, 1);
        }
        $out['workdays_label'] = implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $out['workdays']));
        return $out;
    }

    /** Lundi de la semaine d'un jour. */
    public static function monday(string $day): string
    {
        return self::addDays($day, 1 - (int) date('N', strtotime($day)));
    }

    /** Semaine examinée : celle qui précède la semaine de $today (lundi). */
    public static function reviewedWeek(string $today): string
    {
        return self::addDays(self::monday($today), -7);
    }

    /**
     * Données des trois étapes pour la semaine écoulée (et les 4 précédentes, totaux seulement).
     * @param string|null $today jour du contrôle (Y-m-d), par défaut aujourd'hui
     */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $set = $this->settings();
        $week = self::reviewedWeek($today);
        $weekEnd = self::addDays($week, 6);
        $starts = [];
        for ($i = self::TREND_WEEKS; $i >= 0; $i--) $starts[] = self::addDays($week, -7 * $i);
        $from = $starts[0];

        // Commandes dues dans la fenêtre, et celles dont la date y tombait puis a été déplacée plus tard.
        $rows = array_column($this->repo->orders($from, $weekEnd), null, 'id');
        $movedOut = []; // id => [semaine d'origine => [avant, après, le]]
        foreach ($this->repo->dateChangesSince(self::addDays($from, -self::MOVED_LOOKBACK_DAYS) . ' 00:00:00') as $c) {
            [$b, $a] = self::dateChange((string) $c['changed_fields']);
            if ($b === null || $a === null || $a <= $b || $b < $from || $b > $weekEnd) continue;
            $movedOut[(int) $c['id_order']][self::monday($b)] = ['before' => $b, 'after' => $a, 'at' => substr((string) $c['created_at'], 0, 10)];
        }
        $missing = array_diff(array_keys($movedOut), array_keys($rows));
        foreach ($this->repo->ordersByIds($missing) as $r) $rows[(int) $r['id']] = $r;

        $ids = array_keys($rows);
        $wdts = array_values(array_unique(array_filter(array_map(fn($r) => (int) $r['id_wdt'], $rows))));
        $ctx = ['today' => $today, 'delivery_days' => $set['delivery_days'],
            'changes' => $this->repo->changes($ids), 'lines' => $this->repo->orderLines($ids), 'ordered' => $this->repo->orderedByWdt($wdts),
            'shipped' => $this->repo->shippedByWdt($wdts), 'stops' => $this->repo->stops(self::addDays($from, -14), self::addDays($today, 14))];

        // Entrées par semaine : chaque commande dans la semaine de sa date, plus « przesunięte » dans sa semaine d'origine.
        $byWeek = array_fill_keys($starts, []);
        $cancelled = array_fill_keys($starts, 0);
        foreach ($rows as $id => $r) {
            $ship = (string) self::date($r['shipping_date']);
            $isCancelled = (int) $r['raw_status'] === CustomerOrderStatus::ARCHIVED && !$r['id_wdt'];
            $w = $ship !== '' ? self::monday($ship) : null;
            if ($w !== null && isset($byWeek[$w])) {
                if ($isCancelled) $cancelled[$w]++;
                else $byWeek[$w][$id] = self::evaluate($r, $ctx);
            }
            if ($isCancelled) continue;
            foreach ($movedOut[$id] ?? [] as $origin => $m) {
                if (!isset($byWeek[$origin]) || $origin === $w || $ship <= self::addDays($origin, 6)) continue;
                $e = self::evaluate($r, $ctx);
                // Dans la semaine d'origine : jour d'origine, pas à l'heure (promesse non tenue), complétude sans objet.
                $byWeek[$origin][$id] = array_merge($e, ['shipping_date' => $m['before'], 'shipping_label' => self::label($m['before']),
                    'on_time' => false, 'in_full' => null, 'otif' => false, 'short' => [], 'no_data' => [], 'moved' => $m + ['before_label' => self::label($m['before']),
                    'after_label' => self::label($m['after']), 'at_label' => self::label($m['at'])],
                    'causes' => [['code' => 'moved', 'reason' => 'termin ' . self::label($m['before']) . ' przesunięty na ' . self::label($m['after']) . ' (zmiana ' . self::label($m['at']) . ')']]]);
            }
        }

        $saved = $this->repo->weeks($starts);
        $trend = [];
        foreach ($starts as $s) {
            $t = self::totals(array_values($byWeek[$s]));
            $trend[] = ['week_start' => $s, 'label' => self::dm($s) . ' – ' . self::dm(self::addDays($s, 6)), 'current' => $s === $week, 'cancelled' => $cancelled[$s],
                'saved_pct' => isset($saved[$s]) && $saved[$s]['otif_pct'] !== null ? (float) $saved[$s]['otif_pct'] : null] + $t;
        }
        $orders = array_values($byWeek[$week]);
        usort($orders, fn($a, $b) => [$a['shipping_date'], $a['customer'], $a['id']] <=> [$b['shipping_date'], $b['customer'], $b['id']]);
        $totals = self::totals($orders);
        $prev = array_values(array_filter(array_slice($trend, 0, self::TREND_WEEKS), fn($t) => $t['otif_pct'] !== null));
        $prevAvg = $prev ? round(array_sum(array_column($prev, 'otif_pct')) / count($prev), 1) : null;
        $totals['prev_avg_pct'] = $prevAvg;
        $totals['delta_pts'] = $prevAvg !== null && $totals['otif_pct'] !== null ? round($totals['otif_pct'] - $prevAvg, 1) : null;
        $totals['target_pct'] = $set['target_pct'];
        $totals['below_target'] = $totals['otif_pct'] !== null && $totals['otif_pct'] < $set['target_pct'];
        $totals['cancelled'] = $cancelled[$week];

        // Étape 2 : commandes non OTIF, causes regroupées par cause principale, décisions des assistants n°1, 3 et 5.
        $late = array_values(array_filter($orders, fn($o) => $o['otif'] === false));
        $prior = $this->repo->priorChecks(array_column($late, 'id'));
        $complaints = $this->repo->complaints(array_column($orders, 'id'));
        foreach ($orders as &$o) {
            $o['complaints'] = array_map(fn($c) => ['reason' => ComplaintReasonTranslator::translate($c['complaint_reason'] ?? null), 'product' => $c['product'],
                'status' => (string) ($c['acceptance_status'] ?? 'NEW'), 'at' => $c['reported_at'] ? self::label(substr((string) $c['reported_at'], 0, 10)) : null], $complaints[$o['id']] ?? []);
        }
        unset($o);
        $rank = array_flip(array_keys(self::CAUSES));
        $late = [];
        foreach ($orders as $o) {
            if ($o['otif'] !== false) continue;
            $o['primary'] = $o['causes'][0]['code'];
            $o['prior'] = array_map(fn($p) => $p + ['source_label' => self::PRIOR[$p['source']], 'day_label' => self::label($p['day']),
                'label' => self::priorLabel($p['source'], $p['value']), 'new_label' => $p['new_date'] ? self::label($p['new_date']) : null], $prior[$o['id']] ?? []);
            $late[] = $o;
        }
        usort($late, fn($a, $b) => [$rank[$a['primary']], $a['shipping_date'], $a['customer'], $a['id']] <=> [$rank[$b['primary']], $b['shipping_date'], $b['customer'], $b['id']]);
        $actions = $this->repo->actions($week);
        $groups = [];
        foreach ($late as $o) {
            $g = &$groups[$o['primary']];
            $g ??= ['cause' => $o['primary'], 'label' => self::CAUSES[$o['primary']], 'orders' => 0, 'customers' => [], 'ids' => []];
            $g['orders']++;
            $g['customers'][$o['customer']] = true;
            $g['ids'][] = $o['id'];
            unset($g);
        }
        uksort($groups, fn($a, $b) => $rank[$a] <=> $rank[$b]);
        foreach ($groups as $code => &$g) {
            $g['customers'] = array_keys($g['customers']);
            $a = $actions[$code] ?? null;
            $g['action'] = $a ? ['decision' => (string) $a['decision'], 'label' => self::DECISIONS[$a['decision']] ?? (string) $a['decision'], 'note' => $a['note'],
                'id_responsible' => $a['id_responsible'] !== null ? (int) $a['id_responsible'] : null, 'responsible' => $a['responsible_name'],
                'id_position' => $a['id_position'] !== null ? (int) $a['id_position'] : null, 'position' => $a['position_name'],
                'by' => $a['by_name'], 'at' => self::label(substr((string) $a['updated_at'], 0, 10)) . ', ' . self::hm((string) $a['updated_at'])] : null;
        }
        unset($g);
        $groups = array_values($groups);
        $decided = array_filter($groups, fn($g) => $g['action'] !== null);
        $count = fn(string $k) => count(array_filter($decided, fn($g) => $g['action']['decision'] === $k));
        $totals += ['groups' => count($groups), 'decided' => count($decided), 'waiting' => count($groups) - count($decided),
            'accepted' => $count('accepted'), 'corrective' => $count('corrective'), 'watch' => $count('watch')];

        $done = $this->wizards->done(self::CODE, self::monday($today), self::addDays(self::monday($today), 7));
        return ['today' => $today, 'label' => self::label($today), 'week_start' => $week, 'week_end' => $weekEnd,
            'week_label' => self::dm($week) . ' – ' . self::dm($weekEnd) . '.' . substr($weekEnd, 0, 4), 'settings' => $set,
            'totals' => $totals, 'trend' => $trend, 'days' => self::byDay($orders, $week, $set['workdays']), 'customers' => self::byCustomer($orders),
            'causes' => self::byCause($orders), 'orders' => $orders, 'late' => $late, 'no_data' => array_values(array_filter($orders, fn($o) => $o['otif'] === null)),
            'groups' => $groups, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'cause_labels' => self::CAUSES,
            'users' => $this->wizards->users(), 'positions' => $this->repo->positions(),
            'saved' => isset($saved[$week]) ? ['otif_pct' => $saved[$week]['otif_pct'] !== null ? (float) $saved[$week]['otif_pct'] : null, 'summary' => $saved[$week]['summary']] : null,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * Mesure d'une commande (voir la définition en tête de classe).
     * @param array{today:string, delivery_days:int, changes:array, lines:array, ordered:array, shipped:array, stops:array} $ctx
     */
    public static function evaluate(array $r, array $ctx): array
    {
        $id = (int) $r['id'];
        $cid = (int) $r['id_customer'];
        $expected = (string) self::date($r['shipping_date']);
        $wdt = $r['id_wdt'] ? (int) $r['id_wdt'] : null;
        $status = (int) $r['raw_status'];
        $limit = self::addDays($expected, (int) $ctx['delivery_days']);

        // Premier passage « W transporcie » et changements de date dans l'historique.
        $statusAt = null;
        $dateChanges = 0;
        foreach ($ctx['changes'][$id] ?? [] as $c) {
            $j = json_decode((string) $c['changed_fields'], true);
            if (!is_array($j)) continue;
            if ($statusAt === null && is_array($j['after'] ?? null) && isset($j['after']['status']) && (int) $j['after']['status'] === CustomerOrderStatus::IN_TRANSIT) {
                $statusAt = substr((string) $c['created_at'], 0, 10);
            }
            [$b, $a] = self::dateChange((string) $c['changed_fields']);
            if ($a !== null && $b !== $a) $dateChanges++;
        }
        $logged = self::date($r['shipment_logged_at'] ?? null);
        $document = self::date($r['shipment_date'] ?? null) ?? ($wdt ? self::date($r['wdt_at'] ?? null) : null);
        $stop = $ctx['stops'][$cid . '|' . (self::date($r['shipment_date'] ?? null) ?? $expected)] ?? null;
        $deliveredOn = $stop !== null && $stop['status'] === 'delivered' ? self::date($stop['recorded_at']) : null;
        $shipped = $wdt !== null || in_array($status, [CustomerOrderStatus::COMPLETED, CustomerOrderStatus::IN_TRANSIT], true) || $logged !== null || $statusAt !== null || $deliveredOn !== null;

        $real = array_values(array_filter([$logged, $statusAt]));
        $shipDay = null;
        $source = null;
        if ($real) { $shipDay = min($real); $source = $logged !== null && $logged === $shipDay ? 'log' : 'status'; }
        elseif ($document !== null) { $shipDay = $document; $source = 'document'; }
        elseif ($deliveredOn !== null) { $shipDay = $deliveredOn; $source = 'delivery'; }

        $causes = [];
        $onTime = null;
        if (!$shipped) {
            $onTime = false;
            $n = self::days($expected, $ctx['today']);
            $causes[] = ['code' => 'not_shipped', 'reason' => 'brak WDT i wysyłki — termin ' . self::label($expected) . ' minął ' . $n . ' ' . self::pl($n, 'dzień', 'dni', 'dni') . ' temu'];
        } elseif ($shipDay !== null) {
            $onTime = $source === 'delivery' ? $shipDay <= $limit : $shipDay <= $expected;
            if (!$onTime) {
                $n = self::days($source === 'delivery' ? $limit : $expected, $shipDay);
                $causes[] = ['code' => 'shipped_late', 'reason' => ($source === 'delivery' ? 'dostarczone ' : 'wysłane ') . self::label($shipDay) . ' (' . self::SOURCES[$source] . ') — '
                    . $n . ' ' . self::pl($n, 'dzień', 'dni', 'dni') . ' po terminie ' . self::label($expected)];
            }
        }
        $reason = $stop['reason'] ?? null;
        if ($stop !== null && $stop['status'] === 'problem' && in_array($reason, ['closed', 'refused'], true)) {
            $onTime = false;
            $causes[] = ['code' => 'delivery_failed', 'reason' => 'tablet kierowcy : ' . self::STOP_REASONS[$reason] . self::stopNote($stop)];
        }
        if ($onTime === true && $source !== 'delivery' && (($deliveredOn !== null && $deliveredOn > $limit) || ($stop !== null && $stop['status'] === 'problem' && $reason === 'late'))) {
            $onTime = false;
            $causes[] = ['code' => 'delivered_late', 'reason' => $deliveredOn !== null && $deliveredOn > $limit
                ? 'dostawa potwierdzona ' . self::label($deliveredOn) . ' (wysyłka ' . self::label($expected) . ', dopuszczalne ' . $ctx['delivery_days'] . ' ' . self::pl((int) $ctx['delivery_days'], 'dzień', 'dni', 'dni') . ')'
                : 'tablet kierowcy : spóźnienie' . self::stopNote($stop)];
        }

        // Kompletność : WDT (palety) wobec zamówień tej samej WDT.
        $inFull = null;
        $short = [];
        $lines = $ctx['lines'][$id] ?? [];
        $shippedLines = $wdt !== null ? ($ctx['shipped'][$wdt] ?? []) : [];
        if ($shipped && $wdt !== null && $shippedLines) {
            $inFull = true;
            foreach ($lines as $l) {
                if ($l['qty'] <= 0) continue;
                $want = (float) ($ctx['ordered'][$wdt][$l['id_product']] ?? $l['qty']);
                $got = (float) ($shippedLines[$l['id_product']] ?? 0);
                if ($got + 0.001 < $want) { $inFull = false; $short[] = ['product' => $l['name'], 'ordered' => round($want, 1), 'shipped' => round($got, 1)]; }
            }
            if ($short) {
                $causes[] = ['code' => 'short_shipped', 'reason' => 'WDT ' . ($r['wdt_num'] ?? '#' . $wdt) . ' : ' . implode(', ', array_map(fn($s) => $s['product'] . ' ' . self::num($s['shipped']) . ' z ' . self::num($s['ordered']) . ' kart.', $short))];
            }
        }
        if ($stop !== null && $stop['status'] === 'problem' && in_array($reason, ['missing', 'damaged'], true)) {
            $inFull = false;
            $causes[] = ['code' => 'delivery_shortage', 'reason' => 'tablet kierowcy : ' . self::STOP_REASONS[$reason] . self::stopNote($stop)];
        }
        $rank = array_flip(array_keys(self::CAUSES));
        usort($causes, fn($a, $b) => $rank[$a['code']] <=> $rank[$b['code']]);
        $otif = $onTime === false || $inFull === false ? false : ($onTime === true && $inFull === true ? true : null);
        $noData = [];
        if ($shipped && $onTime === null) $noData[] = 'brak daty wysyłki';
        if ($shipped && $inFull === null && $otif !== false) $noData[] = $wdt === null ? 'wysłane bez WDT — brak ilości wysłanych' : 'WDT bez pozycji — brak ilości wysłanych';
        return ['id' => $id, 'reference' => $r['reference'] !== null && trim((string) $r['reference']) !== '' ? (string) $r['reference'] : null,
            'customer' => (string) $r['company'], 'id_customer' => $cid, 'supplement' => (string) $r['order_type'] === 'SUPPLEMENT',
            'cartons' => round((float) $r['cartons'], 1), 'shipping_date' => $expected, 'shipping_label' => self::label($expected),
            'status' => $status, 'status_label' => CustomerOrderStatus::labels()[$status] ?? (string) $status,
            'wdt' => $wdt !== null ? ['id' => $wdt, 'num' => $r['wdt_num'] !== null ? (string) $r['wdt_num'] : null] : null,
            'shipped' => $shipped, 'ship_day' => $shipDay, 'ship_label' => $shipDay ? self::label($shipDay) : null, 'ship_source' => $source,
            'ship_source_label' => $source ? self::SOURCES[$source] : null, 'delivered_label' => $deliveredOn ? self::label($deliveredOn) : null,
            'stop' => $stop ? ['status' => (string) $stop['status'], 'reason' => $reason !== null ? (self::STOP_REASONS[$reason] ?? (string) $reason) : null] : null,
            'date_changes' => $dateChanges, 'on_time' => $onTime, 'in_full' => $inFull, 'otif' => $otif, 'short' => $short, 'no_data' => $noData,
            'moved' => null, 'causes' => $causes];
    }

    /** Comptes d'une liste de commandes mesurées. */
    public static function totals(array $orders): array
    {
        $n = count($orders);
        $ot = array_filter($orders, fn($o) => $o['on_time'] !== null);
        $if = array_filter($orders, fn($o) => $o['in_full'] !== null);
        $ox = array_filter($orders, fn($o) => $o['otif'] !== null);
        $t = ['orders' => $n, 'measured_ot' => count($ot), 'on_time' => count(array_filter($ot, fn($o) => $o['on_time'])),
            'measured_full' => count($if), 'in_full' => count(array_filter($if, fn($o) => $o['in_full'])),
            'measured_otif' => count($ox), 'otif' => count(array_filter($ox, fn($o) => $o['otif'])), 'non_otif' => count(array_filter($ox, fn($o) => !$o['otif'])),
            'no_data' => $n - count($ox), 'customers' => count(array_unique(array_column($orders, 'id_customer')))];
        $t['on_time_pct'] = self::pct($t['on_time'], $t['measured_ot']);
        $t['in_full_pct'] = self::pct($t['in_full'], $t['measured_full']);
        $t['otif_pct'] = self::pct($t['otif'], $t['measured_otif']);
        return $t;
    }

    /** Par jour de la semaine : jours ouvrés (week.production_days) et autres jours avec des commandes. */
    private static function byDay(array $orders, string $week, array $workdays): array
    {
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $d = self::addDays($week, $i);
            $list = array_values(array_filter($orders, fn($o) => $o['shipping_date'] === $d));
            $work = in_array($i + 1, $workdays, true);
            if (!$work && !$list) continue;
            $out[] = ['date' => $d, 'label' => self::label($d), 'workday' => $work] + self::totals($list);
        }
        return $out;
    }

    /** Par client : le moins bon OTIF d'abord. */
    private static function byCustomer(array $orders): array
    {
        $by = [];
        foreach ($orders as $o) $by[$o['id_customer']][] = $o;
        $out = [];
        foreach ($by as $cid => $list) $out[] = ['id' => (int) $cid, 'name' => $list[0]['customer']] + self::totals($list);
        usort($out, fn($a, $b) => [-$a['non_otif'], $a['otif_pct'] ?? 101, $a['name']] <=> [-$b['non_otif'], $b['otif_pct'] ?? 101, $b['name']]);
        return $out;
    }

    /** Par cause : toutes les causes d'une commande, et la principale. */
    private static function byCause(array $orders): array
    {
        $out = [];
        foreach (self::CAUSES as $code => $label) {
            $all = array_filter($orders, fn($o) => in_array($code, array_column($o['causes'], 'code'), true));
            if (!$all) continue;
            $out[] = ['cause' => $code, 'label' => $label, 'orders' => count($all), 'primary' => count(array_filter($all, fn($o) => $o['causes'][0]['code'] === $code)),
                'customers' => count(array_unique(array_column($all, 'id_customer')))];
        }
        return $out;
    }

    /** Krok 2 : action pour une cause principale de la semaine (une par cause, la dernière compte). */
    public function saveAction(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $cause = (string) ($in['cause'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz działanie : akceptowane (jednorazowe), działanie korygujące albo do obserwacji.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException('Działanie korygujące : wpisz, co zostanie zmienione (kto, co, do kiedy).', 'errors', 422);
        }
        $responsible = (int) ($in['id_responsible'] ?? 0) ?: null;
        $position = (int) ($in['id_position'] ?? 0) ?: null;
        if ($decision !== 'corrective') { $responsible = null; $position = null; }
        if ($responsible !== null && !in_array($responsible, array_column($this->wizards->users(), 'id'), true)) throw new CustomException('Nieznana osoba odpowiedzialna.', 'errors', 422);
        if ($position !== null && !in_array($position, array_column($this->repo->positions(), 'id'), true)) throw new CustomException('Nieznane stanowisko.', 'errors', 422);
        $d = $this->data($today);
        $group = array_values(array_filter($d['groups'], fn($g) => $g['cause'] === $cause))[0] ?? null;
        if ($group === null) throw new CustomException('Tej przyczyny nie ma w ocenianym tygodniu.', 'errors', 422);
        $this->repo->saveAction($d['week_start'], $cause, $decision, $note !== '' ? $note : null, $responsible, $position, $group['orders'], $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque cause principale a son action ; résultat figé, tâche de la semaine cochée. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wybierz działanie dla ' . $w . ' ' . self::pl($w, 'przyczyny', 'przyczyn', 'przyczyn') . ' z kroku 2.', 'errors', 422);
        $this->repo->saveWeek($d['week_start'], $d['totals'], (float) $d['settings']['target_pct'], $d['summary'], $userId);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « OTIF 80 % (cel 95 %) · 10 zamówień · 2 przyczyny : 1 działanie korygujące · 1 do obserwacji » (carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['orders'] === 0) return 'brak zamówień z terminem w tygodniu';
        $s = 'OTIF ' . ($t['otif_pct'] !== null ? self::num($t['otif_pct']) . ' %' : 'brak danych') . ' (cel ' . self::num((float) $t['target_pct']) . ' %)';
        if ($t['delta_pts'] !== null) $s .= ' · ' . ($t['delta_pts'] > 0 ? '+' : ($t['delta_pts'] < 0 ? '−' : '±')) . self::num(abs($t['delta_pts'])) . ' pkt';
        $s .= ' · ' . $t['orders'] . ' ' . self::pl($t['orders'], 'zamówienie', 'zamówienia', 'zamówień');
        if (($t['groups'] ?? 0) === 0) return $s . ' · bez przyczyn do wyjaśnienia';
        $s .= ' · ' . $t['groups'] . ' ' . self::pl($t['groups'], 'przyczyna', 'przyczyny', 'przyczyn');
        $parts = [];
        if ($t['corrective']) $parts[] = $t['corrective'] . ' ' . self::pl($t['corrective'], 'działanie korygujące', 'działania korygujące', 'działań korygujących');
        if ($t['watch']) $parts[] = $t['watch'] . ' do obserwacji';
        if ($t['accepted']) $parts[] = $t['accepted'] . ' ' . self::pl($t['accepted'], 'akceptowana', 'akceptowane', 'akceptowanych');
        return $s . ($parts ? ' : ' . implode(' · ', $parts) : '');
    }

    /** Date d'expédition avant / après d'une entrée de l'historique. @return array{0:?string, 1:?string} */
    private static function dateChange(string $json): array
    {
        $j = json_decode($json, true);
        if (!is_array($j) || !is_array($j['before'] ?? null) || !is_array($j['after'] ?? null) || !array_key_exists('expected_shipping_date', $j['after'])) return [null, null];
        return [self::date($j['before']['expected_shipping_date'] ?? null), self::date($j['after']['expected_shipping_date'])];
    }

    private static function priorLabel(string $source, string $value): string
    {
        $map = match ($source) { 'orders_day' => OrdersDayCheckService::RESULTS, 'supplements_day' => SupplementsDayCheckService::RESULTS, default => DeliveryDelaysService::DECISIONS };
        return $map[$value] ?? $value;
    }

    private static function stopNote(array $stop): string
    {
        return $stop['note'] !== null && trim((string) $stop['note']) !== '' ? ' — ' . trim((string) $stop['note']) : '';
    }

    private static function pct(int $n, int $d): ?float
    {
        return $d > 0 ? round(100 * $n / $d, 1) : null;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function dm(string $day): string
    {
        return date('j.m', strtotime($day));
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', "\u{00A0}"), '0'), ',');
    }

    /** Jours calendaires de $from à $to. */
    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    }

    private static function date(mixed $v): ?string
    {
        $v = $v === null ? '' : substr((string) $v, 0, 10);
        return $v === '' || $v === '0000-00-00' ? null : $v;
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
