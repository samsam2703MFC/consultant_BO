<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PlanningRepository;
use App\Services\Logistics\LogisticsService;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * P3 — besoins et plan de production.
 *
 * MTO : commandes clients pas encore expédiées, en pièces (cartons × colisage), dues avant le
 * départ du camion (jour d'expédition à l'heure de la tournée du client — la plus tôt des clients de
 * la ligne —, sinon planning.default_departure_time, moins planning.loading_time_min). Commandes passées après la clôture (J − planning.default_cutoff_days_before
 * à planning.default_cutoff_time) signalées.
 * MTS : stock projeté (stock libre ERP + OF MTS en cours − commandes servies sur stock) remis à la
 * cible de couverture (consommation des planning.consumption_weeks dernières semaines × jours),
 * jamais au-delà du stock maximum ; plafond du surgélateur si cold.freezer_capacity_cartons > 0.
 * Un article « jamais congelé » n'est jamais MTS (contrôlé à la fiche). Semi-finis : nomenclature
 * des articles planifiés (grammes par carton × (1 + perte)). Le plan proposé est ajusté puis validé,
 * ce qui crée les OF (MTO reliés aux commandes, MTS, SEMI reliés à leur OF parent).
 */
final class PlanningService
{
    public const STATUSES = ['proposed', 'validated', 'frozen', 'closed'];

    public function __construct(
        private PlanningRepository $planning,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
        private LogisticsService $logistics,
    ) {
    }

    /** Besoins de la période, sans rien enregistrer. */
    public function demand(string $from, string $to, ApiContext $context): array
    {
        [$from, $to] = $this->period(['from' => $from, 'to' => $to]);
        return $this->present($this->compute($from, $to), $context);
    }

    public function compute(string $from, string $to): array
    {
        $items = $this->planning->items();
        $byProduct = [];
        $byId = [];
        foreach ($items as $it) {
            $byId[(int) $it['id']] = $it;
            if ($it['id_final_product'] !== null) $byProduct[(int) $it['id_final_product']] = $it;
        }
        $routings = $this->planning->routings();
        $departure = $this->params->get('planning.default_departure_time', '20:00');
        $loadingMin = (int) $this->params->get('planning.loading_time_min', '60');
        $cutoffDays = (int) $this->params->get('planning.default_cutoff_days_before', '2');
        $cutoffTime = $this->params->get('planning.default_cutoff_time', '12:00');
        $roundBatches = $this->params->get('planning.round_mto_to_batch', '1') === '1';
        $capacity = (int) $this->params->get('cold.freezer_capacity_cartons', '0');

        $warnings = [];
        $lines = [];
        $line = function (array $item, string $date) use (&$lines): string {
            $key = $item['id'] . '|' . $date;
            $lines[$key] ??= ['item' => $item, 'due_date' => $date, 'qty_mto' => 0.0, 'qty_mts' => 0.0, 'qty_semi' => 0.0, 'qty_fixed' => 0.0,
                'orders' => [], 'late_orders' => 0, 'mts' => null, 'parents' => []];
            return $key;
        };

        // --- MTO (et commandes servies sur stock pour les articles MTS)
        $committed = [];
        $unplanned = [];
        foreach ($this->planning->openOrderLines($from, $to) as $o) {
            $item = $byProduct[(int) $o['id_product']] ?? null;
            if ($item === null) { $unplanned[(int) $o['id_product']] = true; continue; }
            $pieces = (float) $o['cartons'] * max(1, (int) $item['ppc']);
            if ($item['mode'] === 'MTS') {
                $committed[(int) $item['id']] = ($committed[(int) $item['id']] ?? 0) + $pieces;
                continue;
            }
            $key = $line($item, $o['expected_shipping_date']);
            $lines[$key]['qty_mto'] += $pieces;
            $lines[$key]['orders'][] = ['customer_order_id' => (int) $o['customer_order_id'], 'final_product_id' => (int) $o['id_product'], 'quantity' => $pieces];
            $lines[$key]['customers'][(int) $o['id_customer']] = true;
            $cutoff = date('Y-m-d', strtotime($o['expected_shipping_date'] . " -{$cutoffDays} days")) . ' ' . $cutoffTime . ':00';
            if ($o['create_order_date'] !== null && $o['create_order_date'] > $cutoff) $lines[$key]['late_orders']++;
        }
        if ($unplanned) $warnings[] = ['code' => 'UNPLANNED_PRODUCTS', 'count' => count($unplanned)];

        // --- MTS (prosta reguła) : gdy dostępne < stan min., uzupełnienie do stanu maks. (pierwszy dzień okresu) ;
        // dostępne = stan wolny + zlecenia MTS w toku − zamówienia obsłużone z magazynu. Kartony, które weszły na
        // magazyn od początku okresu (partie tego tygodnia), nie zmniejszają potrzeby okresu : zrobione partie
        // zostają w tygodniu jako zrobione, a potrzeba nie znika, gdy stan przekroczy min. w trakcie tygodnia.
        $stock = $this->planning->freeStockCartons();
        $today = date('Y-m-d');
        $added = $from <= $today ? $this->planning->cartonsAdded($from, min($to, $today)) : [];
        $openMts = $this->planning->openMtsOrders();
        $freezerLines = [];
        $stockInfo = [];
        foreach ($items as $it) {
            if ($it['mode'] !== 'MTS' || $it['item_type'] !== 'product') continue;
            if ($it['stock_max'] === null) { $warnings[] = ['code' => 'MTS_WITHOUT_MAX', 'item_id' => (int) $it['id']]; continue; }
            $ppc = max(1, (int) $it['ppc']);
            $free = ($stock[(int) $it['id_final_product']] ?? 0) * $ppc;
            $made = ($added[(int) $it['id_final_product']] ?? 0) * $ppc;
            $inProduction = $openMts[(int) $it['id']] ?? 0.0;
            $fromStock = $committed[(int) $it['id']] ?? 0.0;
            $r = DemandCalculator::refill($free - $made + $inProduction - $fromStock, $it['stock_min'] !== null ? (float) $it['stock_min'] : null, (float) $it['stock_max']);
            $stockInfo[(int) $it['id']] = ['free' => $free, 'made' => $made, 'in_production' => $inProduction, 'orders_from_stock' => $fromStock] + $r;
            if ($r['qty'] <= 0) continue;
            $key = $line($it, $from);
            $lines[$key]['qty_mts'] = $r['qty'];
            $lines[$key]['mts'] = $r + ['free_stock' => $free, 'made' => $made];
            $freezerLines[] = ['key' => $key, 'qty' => $r['qty'], 'ppc' => $ppc, 'multiple' => $this->num($it['batch_multiple'])];
        }
        $cap = DemandCalculator::capFreezer($freezerLines, $capacity, $capacity > 0 ? $this->planning->freezerOccupancy() : 0);
        foreach ($cap['lines'] as $key => $qty) $lines[$key]['qty_mts'] = $qty;
        if ($capacity <= 0) $warnings[] = ['code' => 'FREEZER_CAPACITY_NOT_SET'];
        elseif ($cap['limited']) $warnings[] = ['code' => 'FREEZER_CAPACITY_LIMITED', 'free_cartons' => $cap['free_cartons']];

        // --- Quantités proposées des produits, puis semi-finis (jusqu'à 3 niveaux)
        foreach ($lines as $key => &$l) {
            $l['qty_proposed'] = $this->proposed($l);
        }
        unset($l);

        // --- Partie : ilość tygodnia produktu (zamówienia + uzupełnienie) w górę do pełnych partii
        // (planning.round_mto_to_batch) ; nadwyżka idzie na magazyn — do linii uzupełnienia, inaczej najwcześniejszej.
        // Stałe partie (📌) nie dokładają produkcji : to dni, na które partie tygodnia wchodzą najpierw (WeekService).
        if ($roundBatches) {
            $keysOf = [];
            foreach ($lines as $key => $l) if ($l['item']['item_type'] === 'product' && ($l['qty_proposed'] ?? 0.0) > 0) $keysOf[(int) $l['item']['id']][] = $key;
            foreach ($keysOf as $keys) {
                $multiple = $this->num($lines[$keys[0]]['item']['batch_multiple']);
                if (!$multiple) continue;
                $total = array_sum(array_map(fn($k) => $lines[$k]['qty_proposed'], $keys));
                $extra = DemandCalculator::roundBatch($total, null, $multiple) - $total;
                if ($extra <= 0.0005) continue;
                usort($keys, fn($a, $b) => [$lines[$b]['qty_mts'] > 0, $lines[$a]['due_date']] <=> [$lines[$a]['qty_mts'] > 0, $lines[$b]['due_date']]);
                $lines[$keys[0]]['qty_batch'] = round($extra, 3);
                $lines[$keys[0]]['qty_proposed'] += $extra;
            }
        }
        $graph = $this->planning->recipeGraph();
        $semiByRecipe = [];
        foreach ($items as $it) if ($it['item_type'] === 'semi' && $it['id_recipe'] !== null) $semiByRecipe[(int) $it['id_recipe']] = $it;
        $pending = array_keys($lines);
        for ($depth = 0; $depth < 4 && $pending; $depth++) {
            $next = [];
            foreach ($pending as $key) {
                $parent = $lines[$key];
                if ($parent['qty_proposed'] <= 0) continue;
                foreach ($this->semiRequirements($parent['item'], $parent['qty_proposed'], $graph, $semiByRecipe) as $semiId => $qty) {
                    $semi = $byId[$semiId];
                    $semiKey = $line($semi, $parent['due_date']);
                    $lines[$semiKey]['qty_semi'] += $qty;
                    $lines[$semiKey]['parents'][] = ['item_id' => (int) $parent['item']['id'], 'due_date' => $parent['due_date'], 'qty' => round($qty, 3)];
                    $next[$semiKey] = true;
                }
            }
            foreach (array_keys($next) as $semiKey) $lines[$semiKey]['qty_proposed'] = $this->proposed($lines[$semiKey]);
            $pending = array_keys($next);
        }

        // --- Heures standard et jalons (les lignes ramenées à zéro, ex. par le surgélateur, disparaissent)
        $lines = array_filter($lines, fn($l) => $l['qty_proposed'] > 0);
        $leavesAt = $this->logistics->departures();
        foreach ($lines as &$l) {
            $steps = $routings[(int) $l['item']['id']] ?? [];
            $calc = StandardTime::routing($steps, $l['qty_proposed']);
            $l['standard_hours'] = round($calc['totals']['labour_s'] / 3600, 3);
            // Czas partii (osoby × min) z arkusza tygodnia : zastępuje roboczogodziny z marszruty.
            $batchHours = self::batchHours($l['item']);
            if ($batchHours !== null) $l['standard_hours'] = round($l['qty_proposed'] / $batchHours[0] * $batchHours[1], 3);
            $l['elapsed_s'] = $calc['totals']['elapsed_s'];
            $multiple = $this->num($l['item']['batch_multiple']);
            $l['batches'] = $l['qty_proposed'] <= 0 ? 0 : ($multiple ? (int) ceil(round($l['qty_proposed'] / $multiple, 6)) : 1);
            $leaves = $leavesAt($l['due_date'], array_keys($l['customers'] ?? []), $departure);
            $l['due_at'] = date('Y-m-d H:i:s', strtotime($l['due_date'] . ' ' . $leaves) - $loadingMin * 60);
            if (!$steps) $warnings[] = ['code' => 'NO_ROUTING', 'item_id' => (int) $l['item']['id']];
            if ($l['item']['status'] !== 'validated') $warnings[] = ['code' => 'SHEET_NOT_VALIDATED', 'item_id' => (int) $l['item']['id']];
        }
        unset($l);
        uasort($lines, fn($a, $b) => [$a['due_date'], $a['item']['id_primary_group'], $a['item']['name']] <=> [$b['due_date'], $b['item']['id_primary_group'], $b['item']['name']]);
        return ['from' => $from, 'to' => $to, 'lines' => array_values($lines), 'warnings' => $this->uniqueWarnings($warnings), 'stock' => $stockInfo];
    }

    /**
     * Czas partii z arkusza tygodnia : [wielkość partii, roboczogodziny na partię = osoby × minuty ÷ 60] albo null,
     * gdy brakuje partii, osób albo czasu (wtedy godziny z marszruty).
     * @return array{0:float, 1:float}|null
     */
    public static function batchHours(array $item): ?array
    {
        $batch = (float) ($item['batch_multiple'] ?? 0);
        $people = (float) ($item['batch_people'] ?? 0);
        $minutes = (float) ($item['batch_minutes'] ?? 0);
        return $batch > 0 && $people > 0 && $minutes > 0 ? [$batch, $people * $minutes / 60] : null;
    }

    public function createPlan(array $input, ApiContext $context): array
    {
        [$from, $to] = $this->period($input, true);
        $v = (new Validator($input))->only(['from', 'to', 'notes'])->string('notes', 1000, false, true);
        $v->throwIfInvalid();
        $demand = $this->compute($from, $to);
        $id = $this->tx->execute(function () use ($from, $to, $demand, $v, $context) {
            $id = $this->planning->insert(['period_start' => $from, 'period_end' => $to, 'status' => 'proposed',
                'version' => $this->planning->nextVersion($from, $to), 'created_by' => $context->userId, 'notes' => $v->value('notes')]);
            foreach ($demand['lines'] as $l) {
                if ($l['qty_proposed'] <= 0) continue;
                $this->planning->insertLine($id, [
                    'id_item' => (int) $l['item']['id'], 'due_date' => $l['due_date'],
                    'qty_mto' => round($l['qty_mto'], 3), 'qty_mts' => round($l['qty_mts'], 3), 'qty_semi' => round($l['qty_semi'], 3),
                    'qty_fixed' => round($l['qty_fixed'], 3),
                    'qty_proposed' => round($l['qty_proposed'], 3), 'qty_final' => round($l['qty_proposed'], 3),
                    'batches' => $l['batches'], 'standard_hours' => $l['standard_hours'],
                ]);
            }
            $this->audit->record($context, 'create', 'plan', $id, null, ['from' => $from, 'to' => $to, 'lines' => count($demand['lines'])]);
            return $id;
        });
        return $this->get($id, $context) + ['warnings' => $demand['warnings']];
    }

    public function list(?string $status): array
    {
        return array_map(fn($p) => $this->presentPlan($p), $this->planning->plans($status));
    }

    public function get(int $id, ApiContext $context): array
    {
        $plan = $this->load($id);
        $lines = $this->planning->lines($id);
        $hoursByGroup = [];
        foreach ($lines as $l) {
            $g = $l['id_primary_group'] !== null ? (int) $l['id_primary_group'] : 0;
            $hoursByGroup[$g] = ($hoursByGroup[$g] ?? 0) + (float) $l['standard_hours'];
        }
        return $this->presentPlan($plan + ['line_count' => count($lines), 'standard_hours' => array_sum($hoursByGroup)]) + [
            'lines' => array_map(fn($l) => [
                'id' => (int) $l['id'], 'item_id' => (int) $l['id_item'], 'name' => $l['name'], 'item_type' => $l['item_type'],
                'mode' => $l['mode'], 'group_id' => $l['id_primary_group'] !== null ? (int) $l['id_primary_group'] : null,
                'due_date' => $l['due_date'], 'unit' => $l['base_unit'], 'ppc' => $l['ppc'] !== null ? (int) $l['ppc'] : null,
                'qty_mto' => (float) $l['qty_mto'], 'qty_mts' => (float) $l['qty_mts'], 'qty_semi' => (float) $l['qty_semi'], 'qty_fixed' => (float) $l['qty_fixed'],
                'qty_proposed' => (float) $l['qty_proposed'], 'qty_final' => (float) $l['qty_final'], 'batches' => (int) $l['batches'],
                'batch_size' => $this->num($l['batch_multiple']),
                'standard_hours' => (float) $l['standard_hours'], 'adjusted' => $l['adjusted_at'] !== null, 'adjust_reason' => $l['adjust_reason'],
            ], $lines),
            'hours_by_group' => array_map(fn($g, $h) => ['group_id' => $g ?: null, 'standard_hours' => round($h, 2)], array_keys($hoursByGroup), array_values($hoursByGroup)),
            // Klient zamawia kartony → sztuki do produkcji → czas (marszruta) i surowce (receptura na karton).
            'materials' => array_map(fn($m) => [
                'material_id' => (int) $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'packaging' => (bool) $m['packaging'],
                'quantity' => round((float) $m['quantity'], 3), 'price_per_unit' => $m['price_per_unit'] !== null ? (float) $m['price_per_unit'] : null,
                'value' => $m['value'] !== null ? round((float) $m['value'], 2) : null, 'products' => (int) $m['products'],
            ], $this->planning->materials($id)),
            'orders' => array_map(fn($o) => [
                'id' => (int) $o['id'], 'code' => $o['code'], 'item_id' => (int) $o['id_item'], 'name' => $o['name'], 'quantity' => (float) $o['quantity'],
                'unit' => $o['base_unit'], 'origin' => $o['origin'], 'parent_order_id' => $o['id_parent_order'] !== null ? (int) $o['id_parent_order'] : null,
                'due_at' => $o['due_at'], 'priority' => (int) $o['priority'], 'status' => $o['status'], 'customer_orders' => (int) $o['demand_count'],
            ], $this->planning->orders($id)),
        ];
    }

    /** Ajustement d'une ligne d'un plan proposé : jamais sous les commandes, jamais au-dessus du stock max (MTS). */
    public function adjustLine(int $planId, int $lineId, array $input, ApiContext $context): array
    {
        $plan = $this->load($planId);
        if ($plan['status'] !== 'proposed') throw new ApiException('PLAN_NOT_EDITABLE', 409, [], ['status' => $plan['status']]);
        $line = $this->planning->line($planId, $lineId) ?? throw ApiException::notFound('plan_line', $lineId);
        $v = (new Validator($input))->only(['qty_final', 'adjust_reason'])
            ->decimal('qty_final', true, 0, 10_000_000)
            ->string('adjust_reason', 255, false, true);
        $qty = (float) $v->value('qty_final', 0);
        if (!$v->hasError('qty_final')) {
            if ($qty < (float) $line['qty_mto'] + (float) $line['qty_semi'] - 1e-6) {
                $v->add('qty_final', 'BELOW_ORDERS', ['min' => round((float) $line['qty_mto'] + (float) $line['qty_semi'], 3)]);
            }
            if ((float) $line['qty_mts'] > 0 || $qty > (float) $line['qty_mto'] + (float) $line['qty_semi']) {
                $max = $this->mtsRoom((int) $line['id_item'], $plan['period_start'], $plan['period_end']);
                if ($max !== null && $qty - (float) $line['qty_mto'] - (float) $line['qty_semi'] > $max + 1e-6) {
                    $v->add('qty_final', 'MTS_ABOVE_STOCK_MAX', ['max' => round($max + (float) $line['qty_mto'] + (float) $line['qty_semi'], 3)]);
                }
            }
            if (abs($qty - (float) $line['qty_proposed']) > 1e-6 && trim((string) $v->value('adjust_reason')) === '') {
                $v->add('adjust_reason', 'REQUIRED');
            }
        }
        $v->throwIfInvalid();
        $item = null;
        foreach ($this->planning->items() as $it) if ((int) $it['id'] === (int) $line['id_item']) $item = $it;
        $steps = $this->planning->routings()[(int) $line['id_item']] ?? [];
        $hours = round(StandardTime::routing($steps, $qty)['totals']['labour_s'] / 3600, 3);
        $multiple = $item ? $this->num($item['batch_multiple']) : null;
        $batches = $qty <= 0 ? 0 : ($multiple ? (int) ceil(round($qty / $multiple, 6)) : 1);
        $this->tx->execute(function () use ($lineId, $qty, $batches, $hours, $v, $line, $context, $planId) {
            $this->planning->updateLine($lineId, $qty, $batches, $hours, $context->userId, $v->value('adjust_reason'));
            $this->audit->record($context, 'adjust', 'plan_line', $lineId, ['qty_final' => $line['qty_final']], ['qty_final' => $qty, 'plan_id' => $planId, 'reason' => $v->value('adjust_reason')]);
        });
        return $this->get($planId, $context);
    }

    /** Validation : un seul plan validé par période ; création des OF. */
    public function validate(int $id, ApiContext $context): array
    {
        $plan = $this->load($id);
        if ($plan['status'] !== 'proposed') throw new ApiException('PLAN_NOT_EDITABLE', 409, [], ['status' => $plan['status']]);
        $overlap = $this->planning->overlappingActive($plan['period_start'], $plan['period_end'], $id);
        if ($overlap) throw new ApiException('PLAN_OVERLAP', 409, [], ['id' => (int) $overlap[0]['id']]);

        $departure = $this->params->get('planning.default_departure_time', '20:00');
        $loadingMin = (int) $this->params->get('planning.loading_time_min', '60');
        $lines = $this->planning->lines($id);
        $routings = $this->planning->routings();
        $graph = $this->planning->recipeGraph();
        $items = [];
        foreach ($this->planning->items() as $it) $items[(int) $it['id']] = $it;
        $semiByRecipe = [];
        foreach ($items as $it) if ($it['item_type'] === 'semi' && $it['id_recipe'] !== null) $semiByRecipe[(int) $it['id_recipe']] = $it;
        $customerLines = $this->planning->openOrderLines($plan['period_start'], $plan['period_end']);
        $customersByKey = [];
        foreach ($customerLines as $o) $customersByKey[$o['id_product'] . '|' . substr($o['expected_shipping_date'], 0, 10)][(int) $o['id_customer']] = true;
        $leaves = [];
        $leavesAt = $this->logistics->departures();
        foreach ($lines as $l) {
            $item = $items[(int) $l['id_item']] ?? null;
            $customers = $item && $item['id_final_product'] !== null && (float) $l['qty_mto'] > 0 ? array_keys($customersByKey[$item['id_final_product'] . '|' . $l['due_date']] ?? []) : [];
            $leaves[(int) $l['id']] = $leavesAt($l['due_date'], $customers, $departure);
        }

        $this->tx->execute(function () use ($id, $plan, $lines, $routings, $graph, $semiByRecipe, $items, $customerLines, $leaves, $loadingMin, $context) {
            $orderByKey = [];
            // Produits d'abord (MTO, MTS), puis semi-finis : leur échéance = début au plus tard du parent.
            usort($lines, fn($a, $b) => ($a['item_type'] === 'semi') <=> ($b['item_type'] === 'semi'));
            $startBy = [];
            foreach ($lines as $l) {
                if ((float) $l['qty_final'] <= 0) continue;
                $item = $items[(int) $l['id_item']] ?? null;
                $dueAt = strtotime($l['due_date'] . ' ' . $leaves[(int) $l['id']]) - $loadingMin * 60;
                $origin = $l['item_type'] === 'semi' ? 'SEMI' : ((float) $l['qty_mts'] > 0 && (float) $l['qty_mto'] == 0.0 ? 'MTS' : 'MTO');
                $parentId = null;
                if ($origin === 'SEMI') {
                    // Parent : l'OF du même jour dont la recette utilise ce semi-fini et qui démarre le plus tôt.
                    foreach ($startBy as $parentKey => [$parentOrder, $start, $parentItem, $parentQty]) {
                        if (substr($parentKey, strpos($parentKey, '|') + 1) !== $l['due_date'] || $parentItem === null) continue;
                        if (!isset($this->semiRequirements($parentItem, $parentQty, $graph, $semiByRecipe)[(int) $l['id_item']])) continue;
                        if ($parentId === null || $start < $dueAt) { $parentId = $parentOrder; $dueAt = min($dueAt, $start); }
                    }
                }
                $orderId = $this->planning->insertOrder([
                    'code' => $this->planning->nextOrderCode($l['due_date']), 'id_plan' => $id, 'id_item' => (int) $l['id_item'],
                    'quantity' => (float) $l['qty_final'], 'origin' => $origin, 'id_parent_order' => $parentId,
                    'due_at' => date('Y-m-d H:i:s', $dueAt), 'priority' => ['SEMI' => 50, 'MTO' => 100, 'MTS' => 200][$origin],
                ]);
                $elapsed = StandardTime::routing($routings[(int) $l['id_item']] ?? [], (float) $l['qty_final'])['totals']['elapsed_s'];
                $startBy[$l['id_item'] . '|' . $l['due_date']] = [$orderId, $dueAt - (int) $elapsed, $item, (float) $l['qty_final']];
                if ($origin === 'MTO' && $item) {
                    foreach ($customerLines as $c) {
                        if ((int) $c['id_product'] === (int) $item['id_final_product'] && $c['expected_shipping_date'] === $l['due_date']) {
                            $this->planning->insertOrderDemand($orderId, (int) $c['customer_order_id'], (int) $c['id_product'], (float) $c['cartons'] * max(1, (int) $item['ppc']));
                        }
                    }
                }
                $orderByKey[$l['id_item'] . '|' . $l['due_date']] = $orderId;
            }
            $this->planning->update($id, ['status' => 'validated', 'validated_by' => $context->userId, 'validated_at' => date('Y-m-d H:i:s')]);
            $this->audit->record($context, 'validate', 'plan', $id, ['status' => $plan['status']], ['status' => 'validated', 'orders' => count($orderByKey)]);
        });
        return $this->get($id, $context);
    }

    /**
     * Semi-finis planifiables contenus dans un article, pour une quantité de l'article (unité de base) :
     * parcours de l'arbre des recettes, quantités dans l'unité de l'enfant, mises à l'échelle par le
     * rendement des recettes intermédiaires, pertes comprises ; on s'arrête aux semi-finis planifiables.
     * @return array<int,float> article semi-fini => quantité dans son unité de base
     */
    public function semiRequirements(array $item, float $qty, array $graph, array $semiByRecipe): array
    {
        $recipeId = $item['recipe_id'] !== null ? (int) $item['recipe_id'] : null;
        if ($recipeId === null || $qty <= 0) return [];
        $meta = $graph['meta'][$recipeId] ?? ['unit' => '', 'yield' => 1.0];
        // Quantité de l'article exprimée dans l'unité de sa recette : cartons pour un produit fini
        // (la recette d'un produit = 1 carton), unité de la recette pour un semi-fini.
        $inRecipeUnit = $item['item_type'] === 'product' ? $qty / max(1, (int) $item['ppc']) : self::convert($qty, (string) $item['base_unit'], $meta['unit']);
        $out = [];
        $walk = function (int $recipe, float $batches, int $depth) use (&$walk, &$out, $graph, $semiByRecipe): void {
            if ($depth > 6) return;
            foreach ($graph['children'][$recipe] ?? [] as [$child, $quantity]) {
                $need = $quantity * $batches * (1 + ($graph['loss'][$recipe . ':' . $child] ?? 0) / 100);
                $childMeta = $graph['meta'][$child] ?? ['unit' => '', 'yield' => 1.0];
                if (isset($semiByRecipe[$child])) {
                    $semi = $semiByRecipe[$child];
                    $out[(int) $semi['id']] = ($out[(int) $semi['id']] ?? 0) + self::convert($need, $childMeta['unit'], (string) $semi['base_unit']);
                    continue;
                }
                $walk($child, $need / ($childMeta['yield'] > 0 ? $childMeta['yield'] : 1), $depth + 1);
            }
        };
        $walk($recipeId, $inRecipeUnit / ($meta['yield'] > 0 ? $meta['yield'] : 1), 0);
        return $out;
    }

    /** Conversion entre g/kg et ml/l ; autres unités inchangées. */
    public static function convert(float $q, string $from, string $to): float
    {
        $factors = ['g' => ['kg', 0.001], 'kg' => ['g', 1000], 'ml' => ['l', 0.001], 'l' => ['ml', 1000]];
        return isset($factors[$from]) && $factors[$from][0] === $to ? $q * $factors[$from][1] : $q;
    }

    /**
     * Place restante sous le stock max pour un article MTS (null si l'article n'est pas MTS), arrondie au lot entier
     * supérieur (le réassort se fait en lots entiers, la dernière fournée peut dépasser un peu le max.).
     */
    private function mtsRoom(int $itemId, string $from, string $to): ?float
    {
        $demand = $this->compute($from, $to);
        foreach ($this->planning->items() as $it) {
            if ((int) $it['id'] !== $itemId) continue;
            if ($it['mode'] !== 'MTS' || $it['stock_max'] === null || $it['item_type'] !== 'product') return null;
            $available = $demand['stock'][$itemId]['available'] ?? null;
            if ($available === null) {
                $ppc = max(1, (int) $it['ppc']);
                $available = ($this->planning->freeStockCartons()[(int) $it['id_final_product']] ?? 0) * $ppc + ($this->planning->openMtsOrders()[$itemId] ?? 0.0);
            }
            $room = max(0.0, (float) $it['stock_max'] - $available);
            return DemandCalculator::roundBatch($room, null, $this->num($it['batch_multiple']));
        }
        return null;
    }

    /** Produkt : zamówienia + uzupełnienie (partie liczone dla całego tygodnia produktu) ; półprodukt : w górę do partii linii. */
    private function proposed(array $l): float
    {
        $item = $l['item'];
        $total = $l['qty_mto'] + $l['qty_mts'] + $l['qty_semi'];
        if ($item['item_type'] === 'product') return $total;
        return DemandCalculator::roundBatch($total, $this->num($item['batch_min']), $this->num($item['batch_multiple']));
    }

    private function present(array $demand, ApiContext $context): array
    {
        return [
            'from' => $demand['from'],
            'to' => $demand['to'],
            'lines' => array_map(fn($l) => [
                'item_id' => (int) $l['item']['id'], 'name' => $l['item']['name'], 'item_type' => $l['item']['item_type'], 'mode' => $l['item']['mode'],
                'group_id' => $l['item']['id_primary_group'] !== null ? (int) $l['item']['id_primary_group'] : null,
                'due_date' => $l['due_date'], 'due_at' => $l['due_at'], 'unit' => $l['item']['base_unit'],
                'ppc' => $l['item']['item_type'] === 'product' && (int) $l['item']['ppc'] > 0 ? (int) $l['item']['ppc'] : null,
                'batch_size' => $this->num($l['item']['batch_multiple']),
                'qty_mto' => round($l['qty_mto'], 3), 'qty_mts' => round($l['qty_mts'], 3), 'qty_semi' => round($l['qty_semi'], 3),
                'qty_fixed' => round($l['qty_fixed'], 3), 'qty_batch' => round($l['qty_batch'] ?? 0.0, 3),
                'qty_proposed' => round($l['qty_proposed'], 3), 'batches' => $l['batches'], 'standard_hours' => $l['standard_hours'],
                'customer_orders' => count($l['orders']), 'late_orders' => $l['late_orders'],
                'mts' => $l['mts'] === null ? null : array_map(fn($x) => is_float($x) ? round($x, 3) : $x, $l['mts']),
                'parents' => $l['parents'],
            ], $demand['lines']),
            'warnings' => array_map(fn($w) => $w + ['message' => $context->translator->error('PLAN_WARNING_' . $w['code'], $w)], $demand['warnings']),
        ];
    }

    private function presentPlan(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end'], 'status' => $p['status'],
            'version' => (int) $p['version'], 'notes' => $p['notes'], 'created_at' => $p['created_at'], 'created_by' => $p['created_by_name'] ?? null,
            'validated_at' => $p['validated_at'], 'validated_by' => $p['validated_by_name'] ?? null,
            'line_count' => (int) ($p['line_count'] ?? 0), 'standard_hours' => round((float) ($p['standard_hours'] ?? 0), 2),
        ];
    }

    /** @return array{0:string,1:string} */
    private function period(array $input, bool $required = false): array
    {
        $v = (new Validator(array_intersect_key($input, ['from' => 1, 'to' => 1])))->date('from', true, false)->date('to', true, false);
        $v->throwIfInvalid();
        $from = $v->value('from');
        $to = $v->value('to');
        $max = max(1, (int) $this->params->get('planning.max_period_days', '28'));
        if ($to < $from) throw ApiException::validation([['field' => 'to', 'code' => 'DATE_ORDER', 'params' => []]]);
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > $max) {
            throw ApiException::validation([['field' => 'to', 'code' => 'PERIOD_TOO_LONG', 'params' => ['max' => $max]]]);
        }
        return [$from, $to];
    }

    private function uniqueWarnings(array $warnings): array
    {
        $out = [];
        foreach ($warnings as $w) $out[json_encode($w)] = $w;
        return array_values($out);
    }

    private function load(int $id): array
    {
        $plan = $this->planning->find($id) ?? throw ApiException::notFound('plan', $id);
        foreach ($this->planning->plans(null) as $p) if ((int) $p['id'] === $id) return $p;
        return $plan;
    }

    private function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
