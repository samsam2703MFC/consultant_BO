<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\DeliveryDelaysRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsService;

/**
 * Asystent « Opóźnienia dostaw » [374] (Kierownik operacyjny, chaque jour ; obligation 3.7 : reagowanie na opóźnienia,
 * zmiany harmonogramów oraz problemy organizacyjne — LOGISTYKA) :
 *   1. Przegląd : livraisons clients (customer_order sans gratis, archivées ni statut 4) à expédier aujourd'hui et
 *      jusqu'au jour ouvré suivant (week.production_days), celles qui auraient dû partir (date d'expédition passée, sans
 *      WDT) et celles parties sans livraison confirmée (« W transporcie »), sur deliveries.delay_lookback_days jours ;
 *      pour chacune : préparation (palettes, liste de colisage), transport du jour (LogisticsService::day — même règle
 *      que Dzień wysyłki et « Transport dla zamówień » : véhicule, chauffeur, départ, remplissage), tablette chauffeur ;
 *   2. Do wyjaśnienia : signaux (voir flags()) ; une décision par livraison signalée (n_delivery_delay_check) :
 *      wyjaśnione — w drodze / OK, nowy termin (date + note), kontakt z klientem (note) ; SMS au client proposé
 *      (OrderSmsService, un clic, texte relu — rien ne part seul) ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 * Lecture seule hors de n_delivery_delay_check : « Nowy termin » note la date, la commande garde la sienne.
 */
final class DeliveryDelaysService
{
    public const CODE = 'deliveries_delays_checked';
    public const DECISIONS = ['ok' => 'Wyjaśnione — w drodze / OK', 'new_date' => 'Nowy termin', 'contact' => 'Kontakt z klientem'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['new_date', 'contact'];
    public const SECTIONS = ['overdue' => 'Po terminie — nie wyjechały', 'transit' => 'W transporcie — bez potwierdzenia dostawy',
        'today' => 'Wysyłka dziś', 'next' => 'Wysyłka do następnego dnia roboczego'];
    public const FLAGS = [
        'ship_overdue' => 'data wysyłki minęła, brak WDT', 'departure_passed' => 'godzina wyjazdu minęła, brak WDT', 'not_ready' => 'nic gotowego do wysyłki',
        'no_vehicle' => 'bez pojazdu', 'vehicle_over' => 'pojazd przeładowany', 'no_driver' => 'pojazd bez kierowcy',
        'in_transit_long' => 'za długo w transporcie', 'delivery_problem' => 'problem przy dostawie', 'date_changed' => 'data wysyłki zmieniana wiele razy',
    ];
    /** n_delivery_stop.reason (tablette chauffeur). */
    private const STOP_REASONS = ['closed' => 'klient zamknięty', 'refused' => 'odmowa przyjęcia', 'damaged' => 'towar uszkodzony', 'missing' => 'brak towaru',
        'late' => 'spóźnienie', 'other' => 'inny problem'];
    /** Bornes de lecture des réglages (mêmes valeurs que ParamRules) : [défaut, min, max]. */
    private const PARAMS = ['deliveries.delay_lookback_days' => [14, 1, 90], 'deliveries.transit_max_days' => [2, 1, 30],
        'deliveries.date_changes_max' => [2, 1, 10], 'deliveries.departure_grace_min' => [30, 0, 600]];
    /** Nouveau terme : au plus tant de jours après aujourd'hui. */
    private const NEW_DATE_MAX_DAYS = 60;
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private DeliveryDelaysRepository $repo, private LogisticsService $logistics, private LightWizardRepository $wizards,
        private ParamRepository $params)
    {
    }

    /**
     * Jours ouvrés (week.production_days) et seuils deliveries.* (hors bornes → valeur par défaut).
     * @return array{workdays:list<int>, lookback_days:int, transit_max_days:int, date_changes_max:int, departure_grace_min:int}
     */
    public function settings(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        $out = ['workdays' => array_values(array_unique($days))];
        foreach (self::PARAMS as $code => [$default, $min, $max]) {
            $raw = trim($this->params->get($code, (string) $default));
            $v = preg_match('/^-?\d+$/', $raw) ? (int) $raw : $default;
            $out[substr($code, strlen('deliveries.'))] = $v < $min || $v > $max ? $default : $v;
        }
        $out['lookback_days'] = $out['delay_lookback_days'];
        unset($out['delay_lookback_days']);
        return $out;
    }

    /** Jour ouvré suivant ($dir = 1) ou précédent ($dir = -1) d'un jour (week.production_days). */
    public static function workday(string $day, int $dir, array $workdays): string
    {
        $d = $day;
        for ($i = 0; $i < 10; $i++) {
            $d = self::addDays($d, $dir);
            if (in_array((int) date('N', strtotime($d)), $workdays, true)) return $d;
        }
        return self::addDays($day, $dir);
    }

    /**
     * @param string|null $now moment du contrôle (Y-m-d H:i:s) : départ passé, chargement commencé — par défaut maintenant
     *                         (aujourd'hui) ou la fin du jour contrôlé (autre jour)
     */
    public function data(?string $today = null, ?string $now = null): array
    {
        $today ??= date('Y-m-d');
        $now ??= $today === date('Y-m-d') ? date('Y-m-d H:i:s') : $today . ' 23:59:59';
        $set = $this->settings();
        $next = self::workday($today, 1, $set['workdays']);
        $from = self::addDays($today, -$set['lookback_days']);
        $rows = $this->repo->orders($from, $today, $next);
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $pallets = $this->repo->pallets($from, $next);
        $picking = $this->repo->pickingLists($ids, self::addDays($from, -21) . ' 00:00:00');
        $stops = $this->repo->stops($from, $next);
        $changes = $this->repo->changes($ids);
        $checks = $this->repo->checks($today);
        $previous = $this->repo->previous($ids, $today);

        // Transport des jours à venir (aujourd'hui → jour ouvré suivant) : la règle de Dzień wysyłki.
        $shipDays = array_values(array_unique(array_filter(array_map(fn($r) => self::date($r['shipping_date']), $rows), fn($d) => $d !== null && $d >= $today && $d <= $next)));
        sort($shipDays);
        [$transport, $fleet, $logSettings] = $this->transport($shipDays, $today, $next);

        $orders = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $cid = (int) $r['id_customer'];
            $ship = (string) self::date($r['shipping_date']);
            $open = (int) $r['is_open'] === 1;
            $shippedDay = self::date($r['shipped_at']);
            $stop = $stops[$cid . '|' . ($shippedDay ?? $ship)] ?? null;
            $delivered = (int) $r['raw_status'] === CustomerOrderStatus::COMPLETED || ($stop !== null && $stop['status'] === 'delivered');
            $inTransit = !$open && (int) $r['status'] === CustomerOrderStatus::IN_TRANSIT && !$delivered;
            $upcoming = $ship >= $today && $ship <= $next;
            if (!$upcoming && !$open && !$inTransit) continue; // parti et livré : plus rien à suivre
            $since = $inTransit ? ((int) $r['raw_status'] === CustomerOrderStatus::IN_TRANSIT && $r['update_status_date'] ? (string) $r['update_status_date']
                : ($r['shipped_at'] ?? $r['wdt_at'] ?? $ship)) : null;
            $prep = $pallets[$cid . '|' . $ship] ?? ['building' => 0, 'ready' => 0, 'shipped' => 0];
            $t = $upcoming ? ($transport[$cid . '|' . $ship] ?? null) : null;
            $dc = self::dateChanges($changes[$id] ?? []);
            $o = ['id' => $id, 'reference' => $r['reference'] !== null && trim((string) $r['reference']) !== '' ? (string) $r['reference'] : null,
                'customer' => (string) $r['company'], 'id_customer' => $cid, 'town' => $r['town'] !== null && trim((string) $r['town']) !== '' ? (string) $r['town'] : null,
                'supplement' => (string) $r['order_type'] === 'SUPPLEMENT', 'cartons' => round((float) $r['cartons'], 1),
                'shipping_date' => $ship, 'shipping_label' => self::label($ship), 'late_days' => $ship < $today ? self::days($ship, $today) : 0,
                'section' => $upcoming ? ($ship === $today ? 'today' : 'next') : ($open ? 'overdue' : 'transit'),
                'status' => (int) $r['status'], 'status_label' => $delivered ? 'Dostarczone' : (CustomerOrderStatus::labels()[(int) $r['status']] ?? (string) $r['status']),
                'open' => $open, 'delivered' => $delivered, 'in_transit' => $inTransit,
                'wdt' => $r['id_wdt'] ? ['id' => (int) $r['id_wdt'], 'num' => $r['wdt_num'] !== null ? (string) $r['wdt_num'] : null] : null,
                'shipped_label' => $shippedDay ? self::label($shippedDay) : null,
                'transit_since' => $since, 'transit_label' => $since ? self::label(substr($since, 0, 10)) . (strlen($since) >= 16 && substr($since, 11, 5) !== '00:00' ? ', ' . self::hm($since) : '') : null,
                'transit_days' => $since ? self::days(substr($since, 0, 10), $today) : null,
                'prep' => $prep + ['picking_at' => $picking[$id] ?? null, 'picking_label' => isset($picking[$id]) ? self::label(substr($picking[$id], 0, 10)) . ', ' . self::hm($picking[$id]) : null],
                'transport' => $t,
                'stop' => $stop ? ['status' => (string) $stop['status'], 'reason' => $stop['reason'] !== null ? (self::STOP_REASONS[$stop['reason']] ?? (string) $stop['reason']) : null,
                    'note' => $stop['note'] !== null && trim((string) $stop['note']) !== '' ? (string) $stop['note'] : null,
                    'at' => self::label(substr((string) $stop['recorded_at'], 0, 10)) . ', ' . self::hm((string) $stop['recorded_at'])] : null,
                'date_changes' => $dc];
            $o['flags'] = self::flags($o, ['today' => $today, 'now' => $now, 'next' => $next] + $set + $logSettings);
            $ck = $checks[$id] ?? null;
            $o['check'] = $ck ? ['decision' => (string) $ck['decision'], 'label' => self::DECISIONS[$ck['decision']] ?? (string) $ck['decision'],
                'new_date' => self::date($ck['new_date']), 'new_label' => self::date($ck['new_date']) ? self::label((string) self::date($ck['new_date'])) : null,
                'note' => $ck['note'], 'by' => $ck['by_name'], 'at' => self::hm((string) $ck['updated_at'])] : null;
            $pv = $previous[$id] ?? null;
            $o['previous'] = $pv ? ['day_label' => self::label((string) $pv['day']), 'label' => self::DECISIONS[$pv['decision']] ?? (string) $pv['decision'],
                'new_label' => self::date($pv['new_date']) ? self::label((string) self::date($pv['new_date'])) : null, 'note' => $pv['note'], 'by' => $pv['by_name']] : null;
            $orders[] = $o;
        }
        $rank = array_flip(array_keys(self::SECTIONS));
        usort($orders, fn($a, $b) => [$rank[$a['section']], $a['shipping_date'], $a['customer'], $a['id']] <=> [$rank[$b['section']], $b['shipping_date'], $b['customer'], $b['id']]);

        $flagged = array_values(array_filter($orders, fn($o) => $o['flags']));
        $resolved = array_values(array_filter($flagged, fn($o) => $o['check'] !== null));
        $count = fn(string $k) => count(array_filter($resolved, fn($o) => $o['check']['decision'] === $k));
        $in = fn(string $s) => array_values(array_filter($orders, fn($o) => $o['section'] === $s));
        $todayOrders = $in('today');
        $totals = ['orders' => count($orders), 'today' => count($todayOrders), 'today_customers' => count(array_unique(array_column($todayOrders, 'id_customer'))),
            'today_shipped' => count(array_filter($todayOrders, fn($o) => !$o['open'])), 'next' => count($in('next')),
            'overdue' => count($in('overdue')), 'transit' => count($in('transit')),
            'vehicles' => count(array_filter($fleet, fn($v) => $v['date'] === $today)), 'vehicles_next' => count(array_filter($fleet, fn($v) => $v['date'] !== $today)),
            'flagged' => count($flagged), 'resolved' => count($resolved), 'waiting' => count($flagged) - count($resolved),
            'ok' => $count('ok'), 'new_date' => $count('new_date'), 'contact' => $count('contact')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'now' => self::hm($now), 'next_workday' => $next, 'next_label' => self::label($next),
            'from' => $from, 'from_label' => self::label($from),
            'settings' => $set + ['workdays_label' => implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $set['workdays'])), 'default_departure' => $logSettings['default_departure']],
            'orders' => $orders, 'fleet' => $fleet, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'sections' => self::SECTIONS, 'flag_labels' => self::FLAGS,
            'new_date_max' => self::addDays($today, self::NEW_DATE_MAX_DAYS),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * Transport par client et jour (LogisticsService::day) et pojazdy des jours : chauffeur = nom sur le véhicule
     * (carrier_vehicle, CMR), sinon employé du véhicule (n_vehicle.id_driver), sinon chauffeur d'une tournée de ses clients
     * (n_tour.id_driver) ; départ (tournée, véhicule ou planning.default_departure_time) et début du chargement ;
     * départ et retour déclarés sur la tablette chauffeur (n_delivery_run).
     * @return array{0: array<string, array>, 1: list<array>, 2: array{default_departure:string, loading_min:int}}
     */
    private function transport(array $days, string $today, string $next): array
    {
        $map = [];
        $fleet = [];
        $settings = ['default_departure' => '20:00', 'loading_min' => 60];
        if (!$days) return [$map, $fleet, $settings];
        $runs = $this->repo->runs($today, $next);
        $tourDrivers = $this->repo->tourDrivers();
        $loaded = [];
        foreach ($days as $d) $loaded[$d] = $this->logistics->day($d);
        $employees = [];
        foreach ($loaded as $day) foreach ($day['all_vehicles'] as $v) if ($v['id_driver'] !== null) $employees[] = (int) $v['id_driver'];
        $names = $this->repo->employeeNames([...$employees, ...array_values($tourDrivers)]);
        foreach ($loaded as $d => $day) {
            $settings = ['default_departure' => (string) $day['settings']['default_departure'], 'loading_min' => (int) $day['settings']['loading_min']];
            $vehicles = [];
            foreach ($day['fleet'] as $v) {
                if (!$v['customers']) continue;
                $all = $day['all_vehicles'][$v['id']] ?? [];
                $driver = trim((string) ($v['driver'] ?? '')) ?: (isset($all['id_driver']) && $all['id_driver'] !== null ? ($names[(int) $all['id_driver']] ?? null) : null);
                if ($driver === null) {
                    foreach ($v['customers'] as $cid) {
                        $tid = $day['by_id'][$cid]['tour_id'] ?? null;
                        if ($tid !== null && isset($tourDrivers[$tid], $names[$tourDrivers[$tid]])) { $driver = $names[$tourDrivers[$tid]]; break; }
                    }
                }
                $run = $runs[$v['id'] . '|' . $d] ?? null;
                $tours = [];
                foreach ($v['customers'] as $cid) {
                    $tid = $day['by_id'][$cid]['tour_id'] ?? null;
                    foreach ($day['tours'] as $t) if ($t['id'] === $tid) $tours[$t['name']] = true;
                }
                $vehicles[$v['id']] = ['id' => (int) $v['id'], 'date' => $d, 'date_label' => self::label($d), 'label' => (string) $v['label'], 'carrier' => $v['carrier'],
                    'driver' => $driver, 'departure' => (string) $v['departure'], 'loading_from' => (string) $v['loading_from'],
                    'pallets' => (int) $v['pallets'], 'places' => $v['places'], 'fill_pct' => $v['fill_pct'] !== null ? round((float) $v['fill_pct']) : null,
                    'weight_kg' => $v['weight_kg'], 'max_kg' => $v['max_kg'], 'over' => (bool) $v['over'], 'tours' => array_keys($tours),
                    'customers' => array_values(array_map(fn($cid) => (string) ($day['by_id'][$cid]['name'] ?? ('#' . $cid)), $v['customers'])),
                    'run' => $run ? ['started' => $run['started_at'] ? self::hm((string) $run['started_at']) : null, 'finished' => $run['finished_at'] ? self::hm((string) $run['finished_at']) : null] : null];
                $fleet[] = $vehicles[$v['id']];
            }
            $loadingFrom = date('H:i', strtotime($d . ' ' . $settings['default_departure']) - $settings['loading_min'] * 60);
            foreach ($day['customers'] as $c) {
                $v = $c['vehicle_id'] !== null ? ($vehicles[$c['vehicle_id']] ?? null) : null;
                $map[(int) $c['id'] . '|' . $d] = ['vehicle' => $v ? ['id' => $v['id'], 'label' => $v['label'], 'driver' => $v['driver'], 'over' => $v['over'],
                        'pallets' => $v['pallets'], 'places' => $v['places'], 'max_kg' => $v['max_kg'], 'weight_kg' => $v['weight_kg'], 'run' => $v['run']] : null,
                    'tour' => self::tourName($day, $c['tour_id']), 'pallets' => (int) $c['pallets'],
                    'departure' => $v['departure'] ?? $settings['default_departure'], 'loading_from' => $v['loading_from'] ?? $loadingFrom];
            }
        }
        return [$map, $fleet, $settings];
    }

    private static function tourName(array $day, ?int $tourId): ?string
    {
        if ($tourId === null) return null;
        foreach ($day['tour_options'] as $t) if ((int) $t['id'] === $tourId) return (string) $t['name'];
        return null;
    }

    /**
     * Changements de la date d'expédition dans l'historique (entrées avant / après dont la date diffère).
     * @param list<array{changed_fields:string, created_at:string}> $changes
     * @return array{count:int, last:?string}
     */
    public static function dateChanges(array $changes): array
    {
        $n = 0;
        $last = null;
        $date = fn($v) => $v ? substr((string) $v, 0, 10) : null;
        foreach ($changes as $c) {
            $j = json_decode((string) $c['changed_fields'], true);
            if (!is_array($j) || !is_array($j['before'] ?? null) || !is_array($j['after'] ?? null) || !array_key_exists('expected_shipping_date', $j['after'])) continue;
            $b = $date($j['before']['expected_shipping_date'] ?? null);
            $a = $date($j['after']['expected_shipping_date']);
            if ($b === $a) continue;
            $n++;
            $last = ($b ? self::label($b) : '—') . ' → ' . ($a ? self::label($a) : '—') . ' (' . self::label(substr((string) $c['created_at'], 0, 10)) . ')';
        }
        return ['count' => $n, 'last' => $last];
    }

    /**
     * Signaux d'une livraison, chacun avec une raison courte (pl) :
     *  - ship_overdue : commande ouverte (sans WDT) dont la date d'expédition est passée ;
     *  - departure_passed : commande du jour sans WDT, heure de départ (+ deliveries.departure_grace_min) passée ;
     *  - not_ready : commande du jour sans WDT ni palette prête (palets_delivery statut ≥ 2), et sans liste de colisage
     *    (production pas lancée) ou chargement déjà commencé (départ − planning.loading_time_min) ;
     *  - no_vehicle / vehicle_over / no_driver : commande ouverte à expédier d'ici le jour ouvré suivant, client sans
     *    véhicule, véhicule au-delà de ses places ou de sa charge, véhicule sans chauffeur ;
     *  - in_transit_long : « W transporcie » sans livraison confirmée depuis au moins deliveries.transit_max_days jours ;
     *  - delivery_problem : la tablette chauffeur a signalé un problème à l'arrêt et la commande n'est pas « Zrealizowane » ;
     *  - date_changed : commande ouverte dont la date d'expédition a changé au moins deliveries.date_changes_max fois.
     * @param array{today:string, now:string, transit_max_days:int, date_changes_max:int, departure_grace_min:int, default_departure:string, loading_min:int} $ctx
     * @return list<array{code:string, reason:string}>
     */
    public static function flags(array $o, array $ctx): array
    {
        $out = [];
        $today = $ctx['today'];
        $ship = $o['shipping_date'];
        $t = $o['transport'] ?? null;
        if (!empty($o['open'])) {
            if ($ship < $today) {
                $n = self::days($ship, $today);
                $out[] = ['code' => 'ship_overdue', 'reason' => 'data wysyłki ' . self::label($ship) . ' minęła (' . $n . ' ' . self::pl($n, 'dzień', 'dni', 'dni') . ' temu) — brak WDT, nie wyjechało'];
            } elseif ($ship === $today) {
                $dep = (string) ($t['departure'] ?? $ctx['default_departure']);
                $depAt = strtotime($today . ' ' . $dep) + (int) $ctx['departure_grace_min'] * 60;
                $loadAt = strtotime($today . ' ' . ($t['loading_from'] ?? date('H:i', strtotime($today . ' ' . $dep) - (int) $ctx['loading_min'] * 60)));
                $now = strtotime($ctx['now']);
                $prep = $o['prep'] ?? [];
                if ($now >= $depAt) {
                    $out[] = ['code' => 'departure_passed', 'reason' => 'wyjazd ' . $dep . ' minął — brak WDT (dokumentów wysyłki)'
                        . (!empty($t['vehicle']['run']['started']) ? ', choć kierowca zgłosił wyjazd ' . $t['vehicle']['run']['started'] : '')];
                } elseif ((int) ($prep['ready'] ?? 0) === 0 && (empty($prep['picking_at']) || $now >= $loadAt)) {
                    $out[] = ['code' => 'not_ready', 'reason' => empty($prep['picking_at'])
                        ? 'brak listy kompletacyjnej i gotowych palet — produkcja nie ruszyła (wyjazd ' . $dep . ')'
                        : 'załadunek od ' . date('H:i', $loadAt) . ' — żadna paleta nie jest gotowa' . ((int) ($prep['building'] ?? 0) > 0 ? ' (' . (int) $prep['building'] . ' w budowie)' : '')];
                }
            }
            if ($ship >= $today && $t !== null) {
                $v = $t['vehicle'];
                if ($v === null) $out[] = ['code' => 'no_vehicle', 'reason' => 'brak pojazdu na ' . self::label($ship) . ($t['tour'] ? ' (trasa ' . $t['tour'] . ' bez pojazdu w użyciu)' : '') . ' — Transport dla zamówień'];
                else {
                    if (!empty($v['over'])) {
                        $parts = [];
                        if ($v['places'] !== null && $v['pallets'] > $v['places']) $parts[] = $v['pallets'] . ' palet na ' . $v['places'] . ' miejsc';
                        if ($v['max_kg'] !== null && $v['weight_kg'] > $v['max_kg']) $parts[] = number_format((float) $v['weight_kg'], 0, ',', "\u{00A0}") . ' kg na ' . number_format((float) $v['max_kg'], 0, ',', "\u{00A0}") . ' kg';
                        $out[] = ['code' => 'vehicle_over', 'reason' => 'pojazd ' . $v['label'] . ' przeładowany' . ($parts ? ' : ' . implode(', ', $parts) : '')];
                    }
                    if ($v['driver'] === null) $out[] = ['code' => 'no_driver', 'reason' => 'pojazd ' . $v['label'] . ' bez kierowcy (pojazd, trasa)'];
                }
            }
            $dc = $o['date_changes'] ?? ['count' => 0];
            if ($dc['count'] >= (int) $ctx['date_changes_max']) {
                $out[] = ['code' => 'date_changed', 'reason' => 'data wysyłki zmieniana ' . $dc['count'] . ' ' . self::pl($dc['count'], 'raz', 'razy', 'razy') . ($dc['last'] ? ' — ostatnio ' . $dc['last'] : '')];
            }
        }
        if (!empty($o['in_transit']) && $o['transit_days'] !== null && $o['transit_days'] >= (int) $ctx['transit_max_days']) {
            $out[] = ['code' => 'in_transit_long', 'reason' => 'W transporcie od ' . $o['transit_label'] . ' (' . $o['transit_days'] . ' ' . self::pl($o['transit_days'], 'dzień', 'dni', 'dni') . ') — brak potwierdzenia dostawy'];
        }
        $stop = $o['stop'] ?? null;
        if ($stop !== null && $stop['status'] === 'problem' && (int) ($o['status'] ?? 0) !== CustomerOrderStatus::COMPLETED && empty($o['delivered'])) {
            $out[] = ['code' => 'delivery_problem', 'reason' => 'problem przy dostawie ' . $stop['at'] . ($stop['reason'] ? ' : ' . $stop['reason'] : '') . ($stop['note'] ? ' — ' . $stop['note'] : '')];
        }
        return $out;
    }

    /** Krok 2 : décision pour une livraison signalée (une ligne par commande et par jour, la dernière compte). */
    public function saveDecision(array $input, ?int $userId, ?string $today = null, ?string $now = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($input['id_order'] ?? 0);
        $decision = (string) ($input['decision'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        $newDate = trim((string) ($input['new_date'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : wyjaśnione (w drodze / OK), nowy termin albo kontakt z klientem.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($decision === 'new_date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || date('Y-m-d', (int) strtotime($newDate)) !== $newDate) throw new CustomException('Nowy termin : podaj datę (RRRR-MM-DD).', 'errors', 422);
            if ($newDate < $today) throw new CustomException('Nowy termin nie może być w przeszłości.', 'errors', 422);
            if ($newDate > self::addDays($today, self::NEW_DATE_MAX_DAYS)) throw new CustomException('Nowy termin : najwyżej ' . self::NEW_DATE_MAX_DAYS . ' dni od dziś.', 'errors', 422);
        } else {
            $newDate = '';
        }
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException($decision === 'contact' ? 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).' : 'Nowy termin : wpisz notatkę (dlaczego, kto ustalił).', 'errors', 422);
        }
        $d = $this->data($today, $now);
        $order = array_values(array_filter($d['orders'], fn($o) => $o['id'] === $id))[0] ?? null;
        if ($order === null) throw new CustomException('Tej dostawy nie ma na liście dnia.', 'errors', 422);
        if (!$order['flags']) throw new CustomException('Ta dostawa nie ma nic do wyjaśnienia.', 'errors', 422);
        $this->repo->saveCheck($id, $today, $decision, $newDate !== '' ? $newDate : null, $note !== '' ? $note : null,
            implode(',', array_column($order['flags'], 'code')), $order['shipping_date'], $userId);
        return $this->data($today, $now);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każda dostawa z sygnałem ma decyzję ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null, ?string $now = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today, $now);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wyjaśnij ' . $w . ' ' . self::pl($w, 'dostawę', 'dostawy', 'dostaw') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today, $now)];
    }

    /** « 9 dostaw · 5 do wyjaśnienia · 2 w drodze / OK · 2 nowe terminy · 1 kontakt » (résumé sur la carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['orders'] === 0) return 'brak dostaw do sprawdzenia';
        $s = $t['orders'] . ' ' . self::pl($t['orders'], 'dostawa', 'dostawy', 'dostaw');
        if ($t['flagged'] === 0) return $s . ' · bez opóźnień';
        $s .= ' · ' . $t['flagged'] . ' do wyjaśnienia';
        if ($t['ok']) $s .= ' · ' . $t['ok'] . ' w drodze / OK';
        if ($t['new_date']) $s .= ' · ' . $t['new_date'] . ' ' . self::pl($t['new_date'], 'nowy termin', 'nowe terminy', 'nowych terminów');
        if ($t['contact']) $s .= ' · ' . $t['contact'] . ' ' . self::pl($t['contact'], 'kontakt', 'kontakty', 'kontaktów');
        return $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
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
