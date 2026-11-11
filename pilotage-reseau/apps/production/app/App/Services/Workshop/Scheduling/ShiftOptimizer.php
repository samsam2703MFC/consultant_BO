<?php
declare(strict_types=1);

namespace App\Services\Workshop\Scheduling;

/**
 * P6 — optymalizator zmian (calcul pur, sans base).
 *
 * Wejście : jedna pozycja na grupę, dzień i zmianę (godziny wymagane przez zaplanowane operacje,
 * obsada, długość zmiany, nadgodziny, największa liczba operatorów jednej operacji) oraz artykuły MTS
 * z wolnym miejscem pod stanem maks. Cel : obciążenie load.target_pct (95 %) w zielonym przedziale
 * [green_min, green_max], bez ruszania istniejących operacji — MTO nigdy nie jest opóźnione.
 *
 * Dźwignie w kolejności optimizer.levers_order, na każdy dzień i zmianę :
 *   advance_mts          zmiana niedociążona : produkcja MTS przyspieszona do celu, w granicach
 *                        stanu maks. (i surgelatora), w wielokrotnościach partii, z gamą mieszczącą
 *                        się w zmianie ;
 *   reassign_polyvalent  nadwyżka osób jednej grupy przechodzi do grupy, której brakuje ludzi ;
 *   open_close_shift     zmiana bez pracy zamknięta, nadwyżka osób zdjęta (nigdy poniżej operatorów
 *                        jednej operacji), brak osób dołożony gdy nadgodziny nie wystarczą ;
 *   overtime             reszta przeciążenia w nadgodzinach (maks. optimizer.max_overtime_h na osobę) ;
 *   alternative_workstation  działa na poziomie stanowisk (uszeregowanie) — tu bez skutku.
 */
final class ShiftOptimizer
{
    public const LEVERS = ['advance_mts', 'alternative_workstation', 'reassign_polyvalent', 'open_close_shift', 'overtime'];

    /**
     * @param list<array{key:string, date:string, shift_id:int, group_id:int, shift_h:float, headcount:int, overtime_h?:float, required_h:float, max_operators?:int}> $rows
     * @param list<array{item_id:int, group_id:int, name:string, unit_h:float, fixed_h?:float, room:float, batch_min?:?float, batch_multiple?:?float, ppc?:int, operators?:int, elapsed_a?:float, elapsed_b?:float}> $candidates
     * @param list<string> $levers
     * @param array{target:float, green_min:float, green_max:float, max_overtime_h:float, freezer_room?:?float} $cfg pourcentages
     * @return list<array> lignes avec before, after, actions
     */
    public static function optimize(array $rows, array $candidates, array $levers, array $cfg): array
    {
        $target = $cfg['target'] / 100;
        $min = $cfg['green_min'] / 100;
        $max = $cfg['green_max'] / 100;
        $maxOt = max(0.0, (float) $cfg['max_overtime_h']);
        $freezer = $cfg['freezer_room'] ?? null;
        $levers = array_values(array_intersect($levers, self::LEVERS));

        foreach ($rows as &$r) {
            $r['overtime_h'] = (float) ($r['overtime_h'] ?? 0);
            $r['max_operators'] = (int) ($r['max_operators'] ?? 0);
            $r['before'] = self::state($r['headcount'], $r['overtime_h'], $r['required_h'], $r['shift_h'], $min, $max);
            $r['after'] = ['headcount' => $r['headcount'], 'overtime_h' => $r['overtime_h'], 'required_h' => $r['required_h']];
            $r['actions'] = [];
        }
        unset($r);

        $rooms = [];
        foreach ($candidates as $c) $rooms[$c['item_id']] = min($rooms[$c['item_id']] ?? INF, (float) $c['room']);
        $slots = [];
        foreach ($rows as $i => $r) $slots[$r['date'] . '|' . $r['shift_id']][] = $i;
        ksort($slots);
        foreach ($slots as $indexes) {
            foreach ($levers as $position => $lever) {
                $overtimeLater = in_array('overtime', array_slice($levers, $position + 1), true);
                foreach ($indexes as $i) {
                    if ($lever === 'advance_mts') self::advanceMts($rows, $indexes, $i, $candidates, $rooms, $freezer, $target, $min, $max);
                    if ($lever === 'open_close_shift') self::openClose($rows[$i], $min, $max, $maxOt, $overtimeLater);
                    if ($lever === 'overtime') self::overtime($rows[$i], $max, $maxOt);
                }
                if ($lever === 'reassign_polyvalent') self::reassign($rows, $indexes, $max);
            }
        }

        foreach ($rows as &$r) {
            $a = $r['after'];
            $r['after'] += self::state($a['headcount'], $a['overtime_h'], $a['required_h'], $r['shift_h'], $min, $max);
        }
        unset($r);
        return $rows;
    }

    /** Résumé : obciążenie całości i liczba zmian w zieleni, przed i po. */
    public static function summary(array $rows): array
    {
        $out = [];
        foreach (['before', 'after'] as $k) {
            $required = 0.0;
            $available = 0.0;
            $green = 0;
            $counted = 0;
            foreach ($rows as $r) {
                if ($r[$k]['available_h'] <= 0 && $r[$k]['required_h'] <= 0) continue;
                $counted++;
                $required += $r[$k]['required_h'];
                $available += $r[$k]['available_h'];
                if ($r[$k]['status'] === 'ok') $green++;
            }
            $out[$k] = ['required_h' => round($required, 2), 'available_h' => round($available, 2),
                'load_pct' => $available > 0 ? round($required / $available * 100, 1) : null, 'green' => $green, 'shifts' => $counted];
        }
        return $out;
    }

    public static function state(int $headcount, float $overtime, float $required, float $shiftH, float $min, float $max): array
    {
        $available = $headcount * $shiftH + $overtime;
        $pct = $available > 0 ? $required / $available : null;
        return [
            'headcount' => $headcount, 'overtime_h' => round($overtime, 2), 'required_h' => round($required, 2), 'available_h' => round($available, 2),
            'load_pct' => $pct === null ? null : round($pct * 100, 1),
            'status' => $pct === null ? ($required > 0 ? 'no_team' : 'closed') : ($pct < $min - 1e-9 ? 'under' : ($pct > $max + 1e-9 ? 'over' : 'ok')),
        ];
    }

    /** Osoby potrzebne, żeby nie przekroczyć green_max, i nie mniej niż operatorów jednej operacji. */
    private static function need(array $r, float $max): int
    {
        $required = $r['after']['required_h'];
        if ($required <= 0) return 0;
        return max($r['max_operators'], (int) ceil(round($required / ($r['shift_h'] * $max), 6)));
    }

    private static function available(array $r): float
    {
        return $r['after']['headcount'] * $r['shift_h'] + $r['after']['overtime_h'];
    }

    /**
     * Zmiana niedociążona : MTS przyspieszone. Produkt przechodzi przez kilka grup (np. pakowanie) :
     * każda musi mieć na tej zmianie dość osób, a jej godziny dochodzą do jej pozycji. Miejsce pod
     * stanem maks. jest wspólne dla wszystkich grup produktu (i całego tygodnia).
     */
    private static function advanceMts(array &$rows, array $slot, int $i, array &$candidates, array &$rooms, ?float &$freezer, float $target, float $min, float $max): void
    {
        $r = &$rows[$i];
        $available = self::available($r);
        if ($available <= 0 || $r['after']['required_h'] >= $min * $available - 1e-9) return;
        $fill = $target * $available - $r['after']['required_h'];
        $byGroup = [];
        foreach ($slot as $j) $byGroup[(int) $rows[$j]['group_id']] = $j;
        $parts = [];
        foreach ($candidates as $c) $parts[$c['item_id']][(int) $c['group_id']] = $c;
        $own = array_values(array_filter($candidates, fn($c) => (int) $c['group_id'] === (int) $r['group_id'] && $c['unit_h'] > 0));
        usort($own, fn($a, $b) => $rooms[$b['item_id']] * $b['unit_h'] <=> $rooms[$a['item_id']] * $a['unit_h']);
        foreach ($own as $c) {
            if ($fill <= 0.01) break;
            $room = $rooms[$c['item_id']];
            if ($room <= 0) continue;
            // Toutes les grupy produktu muszą mieć ludzi na tej zmianie.
            $staffed = true;
            foreach ($parts[$c['item_id']] as $group => $part) {
                $j = $byGroup[$group] ?? null;
                if ($j === null || $rows[$j]['after']['headcount'] < (int) ($part['operators'] ?? 1)) { $staffed = false; break; }
            }
            if (!$staffed) continue;
            $multiple = (float) ($c['batch_multiple'] ?? 0);
            // Całą gamę (etapy po kolei, czekanie) trzeba zmieścić w zmianie : czas ≈ a + b × ilość ≤ 95 % zmiany.
            $cap = $room;
            if (($c['elapsed_b'] ?? 0) > 0) $cap = min($cap, (0.95 * $r['shift_h'] * 3600 - (float) ($c['elapsed_a'] ?? 0)) / $c['elapsed_b']);
            if ($freezer !== null) $cap = min($cap, $freezer * max(1.0, (float) ($c['ppc'] ?? 1)));
            // Les autres groupes du produit ne dépassent pas green_max.
            foreach ($parts[$c['item_id']] as $group => $part) {
                if ($group === (int) $r['group_id'] || $part['unit_h'] <= 0) continue;
                $other = $rows[$byGroup[$group]];
                $cap = min($cap, ($max * self::available($other) - $other['after']['required_h'] - (float) ($part['fixed_h'] ?? 0)) / $part['unit_h']);
            }
            $fixed = (float) ($c['fixed_h'] ?? 0);
            $limit = min($cap, ($fill - $fixed) / $c['unit_h']);
            $qty = $multiple > 0 ? floor(round($limit / $multiple, 6)) * $multiple : floor($limit);
            // Une partie de plus quand on resterait sous le vert et qu'elle tient sous green_max.
            $step = $multiple > 0 ? $multiple : 1.0;
            $after = $r['after']['required_h'] + $fixed + $qty * $c['unit_h'];
            if ($after < $min * $available && $qty + $step <= $cap + 1e-9 && $after + $step * $c['unit_h'] <= $max * $available + 1e-9) $qty += $step;
            $batchMin = (float) ($c['batch_min'] ?? 0);
            if ($qty <= 0 || ($batchMin > 0 && $qty < $batchMin)) continue;
            $hours = $fixed + $qty * $c['unit_h'];
            $r['after']['required_h'] += $hours;
            $rooms[$c['item_id']] -= $qty;
            if ($freezer !== null) $freezer -= $qty / max(1.0, (float) ($c['ppc'] ?? 1));
            $fill -= $hours;
            $r['actions'][] = ['lever' => 'advance_mts', 'item_id' => $c['item_id'], 'item' => $c['name'], 'quantity' => $qty, 'hours' => round($hours, 2)];
            // Godziny tego samego zlecenia w innych grupach (np. pakowanie).
            foreach ($parts[$c['item_id']] as $group => $part) {
                if ($group === (int) $r['group_id']) continue;
                $j = $byGroup[$group];
                $extra = (float) ($part['fixed_h'] ?? 0) + $qty * $part['unit_h'];
                $rows[$j]['after']['required_h'] += $extra;
                $rows[$j]['actions'][] = ['lever' => 'advance_mts', 'item_id' => $c['item_id'], 'item' => $c['name'], 'quantity' => $qty, 'hours' => round($extra, 2), 'follows_group_id' => (int) $r['group_id']];
            }
        }
        unset($r);
    }

    private static function reassign(array &$rows, array $indexes, float $max): void
    {
        $deficits = [];
        $spares = [];
        foreach ($indexes as $i) {
            $need = self::need($rows[$i], $max);
            $have = $rows[$i]['after']['headcount'];
            if ($need > $have) $deficits[$i] = $need - $have;
            elseif ($have > $need) $spares[$i] = $have - $need;
        }
        arsort($deficits);
        foreach ($deficits as $to => $missing) {
            foreach ($spares as $from => $spare) {
                if ($missing <= 0) break;
                if ($spare <= 0) continue;
                $n = min($missing, $spare);
                $rows[$to]['after']['headcount'] += $n;
                $rows[$from]['after']['headcount'] -= $n;
                $spares[$from] -= $n;
                $missing -= $n;
                $rows[$to]['actions'][] = ['lever' => 'reassign_polyvalent', 'people' => $n, 'from_group_id' => $rows[$from]['group_id']];
                $rows[$from]['actions'][] = ['lever' => 'reassign_polyvalent', 'people' => -$n, 'to_group_id' => $rows[$to]['group_id']];
            }
        }
    }

    private static function openClose(array &$r, float $min, float $max, float $maxOt, bool $overtimeLater): void
    {
        $have = $r['after']['headcount'];
        $need = self::need($r, $max);
        if ($r['after']['required_h'] <= 0) {
            if ($have > 0 || $r['after']['overtime_h'] > 0) {
                $r['actions'][] = ['lever' => 'open_close_shift', 'action' => 'close', 'people' => -$have];
                $r['after']['headcount'] = 0;
                $r['after']['overtime_h'] = 0.0;
            }
            return;
        }
        $available = self::available($r);
        if ($have > $need && $r['after']['required_h'] < $min * $available) {
            $r['actions'][] = ['lever' => 'open_close_shift', 'action' => 'reduce', 'people' => $need - $have];
            $r['after']['headcount'] = $need;
            return;
        }
        if ($have < $need) {
            $deficitH = $r['after']['required_h'] / $max - $available;
            // Heures sup. seulement si l'effectif couvre déjà l'opération la plus nombreuse.
            if ($overtimeLater && $have >= max(1, $r['max_operators']) && $deficitH <= $have * $maxOt - $r['after']['overtime_h'] + 1e-9) return;
            $r['actions'][] = ['lever' => 'open_close_shift', 'action' => $have === 0 ? 'open' : 'add', 'people' => $need - $have];
            $r['after']['headcount'] = $need;
        }
    }

    private static function overtime(array &$r, float $max, float $maxOt): void
    {
        $have = $r['after']['headcount'];
        if ($have <= 0) return;
        $needed = $r['after']['required_h'] / $max - $have * $r['shift_h'];
        if ($needed <= $r['after']['overtime_h'] + 1e-9) return;
        $hours = min($have * $maxOt, ceil(round($needed * 4, 6)) / 4);
        if ($hours <= $r['after']['overtime_h']) return;
        $r['actions'][] = ['lever' => 'overtime', 'hours' => round($hours - $r['after']['overtime_h'], 2)];
        $r['after']['overtime_h'] = $hours;
    }
}
