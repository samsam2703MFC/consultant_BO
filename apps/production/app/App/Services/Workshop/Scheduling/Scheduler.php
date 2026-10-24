<?php
declare(strict_types=1);

namespace App\Services\Workshop\Scheduling;

use App\Services\Workshop\Product\StandardTime;

/**
 * Moteur d'ordonnancement à capacité finie (calcul pur, sans base).
 *
 * Chaque OF est placé à rebours depuis son échéance, au plus tard possible (juste-à-temps) :
 * dernière étape finie avant l'échéance, chaque étape finie avant le début de la suivante moins
 * l'attente après étape. Contraintes :
 * - un poste « unitaire » ne fait qu'une opération à la fois (nettoyage compris) et seulement dans
 *   ses fenêtres de disponibilité (shifts actifs ce jour, poste disponible sur ce shift) ;
 * - une chambre (pousse, froid, surgélation) utilisée comme poste d'attente a une capacité cumulée
 *   en unités (1 unité = 1 passage de l'étape qui y attend) jamais dépassée ;
 * - la nuit n'est ouverte qu'aux opérations autorisées par le service (robots autonomes, attentes
 *   passives, groupes avec équipe de nuit) : il fournit les fenêtres de chaque poste avec ou sans nuit ;
 * - un gros OF est découpé en lots qui traversent la gamme l'un après l'autre ;
 * - un OF qui ne tient pas avant son échéance n'est jamais placé après : il est signalé en conflit.
 * Les semi-finis passent après les produits : leur échéance est le début de leur OF parent.
 */
final class Scheduler
{
    /** @var array<int,array{sets:array<string,list<array{0:int,1:int}>>, cumulative:bool, capacity:float}> */
    private array $resources = [];
    /** @var array<int,list<array{0:int,1:int}>> poste unitaire => intervalles occupés triés */
    private array $busy = [];
    /** @var array<int,list<array{0:int,1:int,2:float}>> chambre => [début, fin, unités] */
    private array $load = [];

    public function __construct(private int $earliest, private int $maxOperationS = 14400)
    {
    }

    /**
     * @param list<array{0:int,1:int}> $windowsDay fenêtres hors nuit, $windowsAll avec la nuit
     * @param array<string,list<array{0:int,1:int}>> $extraSets autres jeux de fenêtres (ex. shifts où le groupe a l'effectif voulu),
     *        choisis par l'étape avec window_key
     */
    public function addResource(int $id, array $windowsDay, array $windowsAll, bool $cumulative, float $capacity, array $extraSets = []): void
    {
        $sets = ['day' => self::merge($windowsDay), 'all' => self::merge($windowsAll)];
        foreach ($extraSets as $key => $windows) $sets[$key] = self::merge($windows);
        $this->resources[$id] = ['sets' => $sets, 'cumulative' => $cumulative, 'capacity' => $capacity];
        $this->busy[$id] ??= [];
        $this->load[$id] ??= [];
    }

    /** Occupation existante (opérations figées ou d'autres plans). */
    public function reserve(int $resourceId, int $start, int $end, float $units = 1.0): void
    {
        if (!isset($this->resources[$resourceId])) return;
        if ($this->resources[$resourceId]['cumulative']) $this->load[$resourceId][] = [$start, $end, $units];
        else $this->insertBusy($resourceId, $start, $end);
    }

    /**
     * @param list<array{id:int, due:int, qty:float, parent:?int, steps:list<array>}> $orders
     *        étape : id, workstation_id, fixed_time_s, variable_time_s, capacity_per_pass, operators_required,
     *        delay_after_s, delay_workstation_id, cleaning_time_s, night_ok (bool), alternatives (même forme, sans délai)
     * @return array{operations:list<array>, conflicts:list<array>, starts:array<int,int>}
     */
    public function schedule(array $orders): array
    {
        $products = array_values(array_filter($orders, fn($o) => $o['parent'] === null));
        $semis = array_values(array_filter($orders, fn($o) => $o['parent'] !== null));
        usort($products, fn($a, $b) => [$a['due'], $a['id']] <=> [$b['due'], $b['id']]);
        $operations = [];
        $conflicts = [];
        $starts = [];
        foreach ([$products, $semis] as $pass => $list) {
            if ($pass === 1) {
                foreach ($list as &$o) {
                    if (isset($starts[$o['parent']])) $o['due'] = min($o['due'], $starts[$o['parent']]);
                }
                unset($o);
                usort($list, fn($a, $b) => [$a['due'], $a['id']] <=> [$b['due'], $b['id']]);
            }
            foreach ($list as $order) {
                if ($pass === 1 && $order['parent'] !== null && !isset($starts[$order['parent']]) && $this->parentFailed($order['parent'], $conflicts)) {
                    $conflicts[] = ['order_id' => $order['id'], 'step_id' => null, 'reason' => 'PARENT_NOT_SCHEDULED'];
                    continue;
                }
                $result = $this->scheduleOrder($order);
                if (isset($result['conflict'])) { $conflicts[] = $result['conflict']; continue; }
                array_push($operations, ...$result['operations']);
                $starts[$order['id']] = $result['start'];
            }
        }
        usort($operations, fn($a, $b) => [$a['start'], $a['workstation_id']] <=> [$b['start'], $b['workstation_id']]);
        return ['operations' => $operations, 'conflicts' => $conflicts, 'starts' => $starts];
    }

    private function scheduleOrder(array $order): array
    {
        $steps = $order['steps'];
        if (!$steps) return ['conflict' => ['order_id' => $order['id'], 'step_id' => null, 'reason' => 'NO_ROUTING']];
        if ($order['due'] <= $this->earliest) return ['conflict' => ['order_id' => $order['id'], 'step_id' => null, 'reason' => 'DUE_IN_PAST']];
        $chunks = $this->chunks($order);
        $chunkQty = $order['qty'] / $chunks;
        $snapshot = [$this->busy, $this->load];
        $ops = [];
        $orderStart = $order['due'];
        $nextLimit = $order['due'];
        for ($c = $chunks - 1; $c >= 0; $c--) {
            $nextStart = $nextLimit;
            for ($s = count($steps) - 1; $s >= 0; $s--) {
                $step = $steps[$s];
                $placed = $this->placeStep($step, $chunkQty, $nextStart - (int) $step['delay_after_s']);
                if ($placed === null) {
                    [$this->busy, $this->load] = $snapshot;
                    return ['conflict' => ['order_id' => $order['id'], 'step_id' => (int) $step['id'], 'reason' => 'NO_CAPACITY_BEFORE_DUE']];
                }
                foreach ($placed['ops'] as $op) $ops[] = $op + ['order_id' => $order['id'], 'step_id' => (int) $step['id'], 'chunk' => $c + 1, 'qty' => $chunkQty];
                $nextStart = $placed['start'];
            }
            $orderStart = min($orderStart, $nextStart);
            // Les lots se suivent sur la gamme : le lot précédent finit sa première étape avant celui-ci.
            $nextLimit = $order['due'];
        }
        return ['operations' => $ops, 'start' => $orderStart];
    }

    /** Lots : aucune opération plus longue que maxOperationS, aucune attente plus grande que la chambre. */
    private function chunks(array $order): int
    {
        $k = 1;
        foreach ($order['steps'] as $step) {
            $work = StandardTime::step($step, $order['qty'])['work_s'];
            $k = max($k, (int) ceil($work / max(60, $this->maxOperationS)));
            $delayWs = $step['delay_workstation_id'] ?? null;
            if ($delayWs !== null && isset($this->resources[(int) $delayWs]) && $this->resources[(int) $delayWs]['capacity'] > 0) {
                $units = StandardTime::passes($order['qty'], (float) $step['capacity_per_pass']);
                $k = max($k, (int) ceil($units / $this->resources[(int) $delayWs]['capacity']));
            }
        }
        return min($k, 200);
    }

    /**
     * Place une étape (poste principal ou alternatif) au plus tard, fin ≤ $latestEnd, avec son
     * nettoyage et son attente en chambre. Renvoie les opérations et le début du travail.
     */
    private function placeStep(array $step, float $qty, int $latestEnd): ?array
    {
        $best = null;
        $candidates = [$step];
        foreach ($step['alternatives'] ?? [] as $alt) {
            $candidates[] = $alt + ['operators_required' => $step['operators_required'], 'cleaning_time_s' => $step['cleaning_time_s'] ?? 0,
                'night_ok' => $alt['night_ok'] ?? ($step['night_ok'] ?? false), 'delay_after_s' => $step['delay_after_s'], 'delay_workstation_id' => $step['delay_workstation_id'] ?? null];
        }
        foreach ($candidates as $cand) {
            $wsId = (int) $cand['workstation_id'];
            if (!isset($this->resources[$wsId])) continue;
            $work = (int) (ceil(StandardTime::step($cand, $qty)['work_s'] / 60) * 60);
            $cleaning = (int) ($cand['cleaning_time_s'] ?? 0);
            $delay = (int) ($cand['delay_after_s'] ?? 0);
            $delayWs = $cand['delay_workstation_id'] ?? null;
            $units = (float) StandardTime::passes($qty, (float) $cand['capacity_per_pass']);
            $limit = $latestEnd;
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $key = $cand['window_key'] ?? (!empty($cand['night_ok']) ? 'all' : 'day');
                $slot = $this->latestSlot($wsId, $key, $work + $cleaning, $limit + $cleaning);
                if ($slot === null) break;
                $end = $slot[0] + $work;
                if ($delayWs !== null && $delay > 0 && isset($this->resources[(int) $delayWs])) {
                    $conflictStart = $this->cumulativeConflict((int) $delayWs, $end, $end + $delay, $units);
                    if ($conflictStart !== null) { $limit = min($end - 60, $conflictStart); continue; }
                }
                if ($best === null || $end > $best['end']) $best = ['ws' => $wsId, 'start' => $slot[0], 'end' => $end, 'cleaning' => $cleaning, 'delay' => $delay, 'delay_ws' => $delayWs, 'units' => $units, 'operators' => (float) $cand['operators_required']];
                break;
            }
        }
        if ($best === null) return null;
        $this->insertBusy($best['ws'], $best['start'], $best['end'] + $best['cleaning']);
        $ops = [['kind' => 'work', 'workstation_id' => $best['ws'], 'start' => $best['start'], 'end' => $best['end'], 'operators' => $best['operators']]];
        if ($best['cleaning'] > 0) $ops[] = ['kind' => 'cleaning', 'workstation_id' => $best['ws'], 'start' => $best['end'], 'end' => $best['end'] + $best['cleaning'], 'operators' => 1.0];
        if ($best['delay_ws'] !== null && $best['delay'] > 0 && isset($this->resources[(int) $best['delay_ws']])) {
            $this->load[(int) $best['delay_ws']][] = [$best['end'], $best['end'] + $best['delay'], $best['units']];
            $ops[] = ['kind' => 'delay', 'workstation_id' => (int) $best['delay_ws'], 'start' => $best['end'], 'end' => $best['end'] + $best['delay'], 'operators' => 0.0, 'units' => $best['units']];
        }
        return ['start' => $best['start'], 'ops' => $ops];
    }

    /** Créneau libre le plus tard de longueur $length finissant au plus tard à $latestEnd, dans les fenêtres. */
    private function latestSlot(int $wsId, string $key, int $length, int $latestEnd): ?array
    {
        $windows = $this->resources[$wsId]['sets'][$key] ?? [];
        $busy = $this->busy[$wsId];
        for ($w = count($windows) - 1; $w >= 0; $w--) {
            [$a, $b] = $windows[$w];
            if ($b <= $this->earliest) return null;
            if ($a >= $latestEnd) continue;
            $hi = min($b, $latestEnd);
            $gaps = [];
            $cursor = $a;
            foreach ($busy as [$bs, $be]) {
                if ($be <= $a || $bs >= $hi) continue;
                if ($bs > $cursor) $gaps[] = [$cursor, $bs];
                $cursor = max($cursor, $be);
                if ($cursor >= $hi) break;
            }
            if ($cursor < $hi) $gaps[] = [$cursor, $hi];
            for ($g = count($gaps) - 1; $g >= 0; $g--) {
                [$g0, $g1] = $gaps[$g];
                if ($g1 - $length >= max($g0, $this->earliest)) return [$g1 - $length, $g1];
            }
        }
        return null;
    }

    /** Début de la première occupation qui ferait dépasser la capacité de la chambre, sinon null. */
    private function cumulativeConflict(int $wsId, int $start, int $end, float $units): ?int
    {
        $capacity = $this->resources[$wsId]['capacity'];
        if ($capacity <= 0) return null;
        $relevant = array_filter($this->load[$wsId], fn($l) => $l[0] < $end && $l[1] > $start);
        if (!$relevant) return $units <= $capacity ? null : $start;
        $points = [$start];
        foreach ($relevant as $l) if ($l[0] > $start) $points[] = $l[0];
        sort($points);
        foreach ($points as $p) {
            $sum = $units;
            foreach ($relevant as $l) if ($l[0] <= $p && $l[1] > $p) $sum += $l[2];
            if ($sum > $capacity + 1e-9) {
                $starts = array_map(fn($l) => $l[0], array_filter($relevant, fn($l) => $l[0] <= $p && $l[1] > $p));
                return min($starts ?: [$start]);
            }
        }
        return null;
    }

    private function insertBusy(int $wsId, int $start, int $end): void
    {
        $this->busy[$wsId][] = [$start, $end];
        usort($this->busy[$wsId], fn($x, $y) => $x[0] <=> $y[0]);
    }

    private function parentFailed(int $parentId, array $conflicts): bool
    {
        foreach ($conflicts as $c) if ($c['order_id'] === $parentId) return true;
        return false;
    }

    /** Fusionne des fenêtres qui se touchent ou se chevauchent. @param list<array{0:int,1:int}> $windows */
    public static function merge(array $windows): array
    {
        usort($windows, fn($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($windows as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            else $out[] = [$a, $b];
        }
        return $out;
    }

    /** Charge maximale simultanée d'une chambre (contrôle). */
    public static function peakUnits(array $operations, int $wsId): float
    {
        $peak = 0.0;
        $delays = array_values(array_filter($operations, fn($o) => $o['kind'] === 'delay' && $o['workstation_id'] === $wsId));
        foreach ($delays as $d) {
            $sum = 0.0;
            foreach ($delays as $e) if ($e['start'] <= $d['start'] && $e['end'] > $d['start']) $sum += $e['units'] ?? 1;
            $peak = max($peak, $sum);
        }
        return $peak;
    }
}
