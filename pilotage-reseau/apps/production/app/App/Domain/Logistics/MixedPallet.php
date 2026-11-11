<?php
declare(strict_types=1);

namespace App\Domain\Logistics;

/**
 * Paleta mieszana [215] (makieta 214) : pełne warstwy kilku produktów jedna na drugiej.
 *
 *  - kolejność od dołu ustawiana sama : niepełna warstwa (mniej kartonów niż na warstwę) tylko na samej górze ; większa
 *    podłoga niżej (mniejsze kartony zawsze wyżej) ; ta sama podłoga (wymiary ± SAME_FLOOR_MM, tyle samo kartonów na
 *    warstwę) — cięższa warstwa na dole ;
 *  - kontrole : wysokość ≤ najniższy limit produktów (limit produktu / kartonu zastępuje parametr, jak PalletLoad) i wnętrze
 *    ciężarówki ; waga ≤ najniższy limit ; ładunek na karton najniższej warstwy każdego produktu wobec palety samego
 *    produktu (warstwy ERP − 1 kartonów) ; cięższa warstwa na dole ; inna podłoga wyżej tylko na pełnych warstwach
 *    ≥ MIN_FLOOR_PCT % palety ; jedna niepełna warstwa, na górze ;
 *  - propozycje dnia : resztki klienta (kartony poza pełnymi paletami) łączone w jak najmniej palet — najwyższe najpierw,
 *    na pierwszą paletę, na której przechodzą wszystkie kontrole.
 */
final class MixedPallet
{
    public const MIN_FLOOR_PCT = 90.0;
    public const SAME_FLOOR_MM = 15;

    /**
     * @param list<array{product_id:int, name:string, length_mm:int, width_mm:int, height_mm:int, per_layer:int, carton_kg:float,
     *                   cartons:int, lpp?:?int, max_height_mm?:?int, max_kg?:?int}> $items
     * @param array{max_height_mm:int, max_kg:int, truck_height_mm?:int} $limits domyślne (parametry logistyki)
     * @return array{groups: list<array>, height_mm: int, kg: float, cartons: int, layers: int, max_height_mm: int, max_kg: int,
     *               checks: list<array{code:string, status:string, params:array}>, ok: bool}
     */
    public static function stack(array $items, array $limits, string $pallet = 'eur'): array
    {
        $p = PalletLayout::PALLETS[$pallet] ?? PalletLayout::PALLETS['eur'];
        $area = $p['length_mm'] * $p['width_mm'];
        $groups = [];
        foreach ($items as $it) {
            $n = (int) ($it['cartons'] ?? 0);
            $per = max(1, (int) ($it['per_layer'] ?? 0));
            if ($n <= 0) continue;
            $l = max((int) $it['length_mm'], (int) $it['width_mm']);
            $w = min((int) $it['length_mm'], (int) $it['width_mm']);
            $kg = (float) $it['carton_kg'];
            $layers = (int) ceil($n / $per);
            $groups[] = $it + [
                'cartons' => $n, 'per_layer' => $per, 'layers' => $layers, 'partial' => $n % $per,
                'l' => $l, 'w' => $w, 'floor_pct' => round(min($per, $n) * $l * $w / $area * 100, 1),
                'layer_kg' => round($per * $kg, 2), 'kg' => round($n * $kg, 2), 'height' => $layers * (int) $it['height_mm'],
            ];
        }
        // Ta sama podłoga : jedna grupa (podłoga grupy = największa w niej).
        $cluster = [];
        foreach ($groups as $i => $g) {
            $cluster[$i] = $i;
            foreach ($groups as $j => $o) {
                if ($j >= $i) break;
                if (self::sameFloor($g, $o)) { $cluster[$i] = $cluster[$j]; break; }
            }
        }
        $floor = [];
        foreach ($groups as $i => $g) $floor[$cluster[$i]] = max($floor[$cluster[$i]] ?? 0, $g['floor_pct']);
        $keys = array_keys($groups);
        usort($keys, fn($a, $b) => [$groups[$a]['partial'] > 0, -$floor[$cluster[$a]], $cluster[$a], -$groups[$a]['layer_kg'], $groups[$a]['name']]
            <=> [$groups[$b]['partial'] > 0, -$floor[$cluster[$b]], $cluster[$b], -$groups[$b]['layer_kg'], $groups[$b]['name']]);
        $sorted = [];
        foreach ($keys as $k) $sorted[] = $groups[$k] + ['cluster' => $cluster[$k]];
        $groups = $sorted;

        $maxH = null;
        $maxKg = null;
        $z = (int) $p['base_mm'];
        $kg = (float) $p['tare_kg'];
        $total = 0.0;
        foreach ($groups as $g) $total += $g['kg'];
        $above = $total;
        foreach ($groups as $i => &$g) {
            $maxH = min($maxH ?? PHP_INT_MAX, (int) ($g['max_height_mm'] ?? 0) ?: (int) $limits['max_height_mm']);
            $maxKg = min($maxKg ?? PHP_INT_MAX, (int) ($g['max_kg'] ?? 0) ?: (int) $limits['max_kg']);
            $g['position'] = $i + 1;
            $g['z_mm'] = $z;
            $z += $g['height'];
            $above -= $g['kg'];
            // Ładunek na karton najniższej warstwy produktu : wszystko wyżej (jego warstwy i produkty nad nim) ÷ kartony warstwy.
            $first = min($g['per_layer'], $g['cartons']);
            $g['load_kg'] = round(($above + $g['kg'] - $first * (float) $g['carton_kg']) / $first, 1);
            $lpp = (int) ($g['lpp'] ?? 0);
            $g['own_load_kg'] = $lpp > 1 ? round(($lpp - 1) * (float) $g['carton_kg'], 1) : null;
            $kg += $g['kg'];
        }
        unset($g);
        $maxH ??= (int) $limits['max_height_mm'];
        $maxKg ??= (int) $limits['max_kg'];
        $height = $groups ? $z : 0;
        $kg = $groups ? round($kg, 1) : 0.0;
        $truck = (int) ($limits['truck_height_mm'] ?? 0);

        $checks = [];
        if ($groups) {
            $checks[] = ['code' => 'height', 'status' => $height > $maxH ? 'error' : 'ok', 'params' => ['height' => $height, 'max' => $maxH]];
            if ($truck > 0 && $height > $truck) $checks[] = ['code' => 'truck', 'status' => 'error', 'params' => ['height' => $height, 'max' => $truck]];
            $checks[] = ['code' => 'weight', 'status' => $kg > $maxKg + 0.05 ? 'error' : 'ok', 'params' => ['kg' => $kg, 'max' => $maxKg, 'load' => $groups[0]['load_kg']]];
            foreach ($groups as $g) {
                if ($g['own_load_kg'] !== null && $g['load_kg'] > $g['own_load_kg'] + 0.5) {
                    $checks[] = ['code' => 'load', 'status' => 'warn', 'params' => ['name' => $g['name'], 'load' => $g['load_kg'], 'own' => $g['own_load_kg']]];
                }
            }
        }
        if (count($groups) > 1) {
            $inverted = null;
            for ($i = 1; $i < count($groups); $i++) {
                if ($groups[$i]['layer_kg'] > $groups[$i - 1]['layer_kg'] + 0.01) { $inverted = $i; break; }
            }
            $checks[] = $inverted === null
                ? ['code' => 'order', 'status' => 'ok', 'params' => ['kg' => array_column($groups, 'layer_kg')]]
                : ['code' => 'order', 'status' => 'warn', 'params' => ['upper' => $groups[$inverted]['name'], 'upper_kg' => $groups[$inverted]['layer_kg'],
                    'lower' => $groups[$inverted - 1]['name'], 'lower_kg' => $groups[$inverted - 1]['layer_kg']]];
            $same = count(array_unique(array_column($groups, 'cluster'))) === 1;
            $support = array_slice($groups, 0, -1);
            $minPct = min(array_column($support, 'floor_pct'));
            if ($same) {
                $checks[] = ['code' => 'floor_same', 'status' => 'ok', 'params' => ['base' => self::module($groups[0]), 'pct' => $minPct]];
                $checks[] = ['code' => 'floor_rule', 'status' => 'info', 'params' => ['pct' => self::MIN_FLOOR_PCT]];
            } else {
                $small = null;
                for ($i = 0; $i < count($groups) - 1; $i++) {
                    if ($groups[$i]['cluster'] === $groups[$i + 1]['cluster']) continue;
                    if ($groups[$i]['partial'] > 0 || $groups[$i]['floor_pct'] < self::MIN_FLOOR_PCT) { $small = $groups[$i]; break; }
                }
                $checks[] = $small === null
                    ? ['code' => 'floor_diff', 'status' => 'ok', 'params' => ['pct' => $minPct, 'min' => self::MIN_FLOOR_PCT]]
                    : ['code' => 'floor_small', 'status' => 'error', 'params' => ['name' => $small['name'], 'pct' => $small['floor_pct'], 'min' => self::MIN_FLOOR_PCT]];
            }
        }
        $partials = array_values(array_filter($groups, fn($g) => $g['partial'] > 0));
        foreach ($partials as $g) {
            $top = $g['position'] === count($groups);
            $checks[] = ['code' => $top ? 'partial_top' : 'partial', 'status' => $top ? 'ok' : 'error',
                'params' => ['name' => $g['name'], 'n' => $g['partial'], 'per' => $g['per_layer']]];
        }

        return [
            'groups' => $groups, 'height_mm' => $height, 'kg' => $kg, 'cartons' => (int) array_sum(array_column($groups, 'cartons')),
            'layers' => (int) array_sum(array_column($groups, 'layers')), 'max_height_mm' => $maxH, 'max_kg' => $maxKg,
            'checks' => $checks, 'ok' => !array_filter($checks, fn($c) => $c['status'] === 'error'),
        ];
    }

    /**
     * Propozycja : resztki produktów klienta w jak najmniej palet (najwyższe najpierw ; produkt na pierwszą paletę, na której
     * przechodzą wszystkie kontrole — inaczej nowa paleta). @return list<list<array>> palety (pozycje jak w stack)
     */
    public static function propose(array $items, array $limits, string $pallet = 'eur'): array
    {
        $items = array_values(array_filter($items, fn($it) => (int) ($it['cartons'] ?? 0) > 0));
        $h = fn($it) => (int) ceil((int) $it['cartons'] / max(1, (int) $it['per_layer'])) * (int) $it['height_mm'];
        usort($items, fn($a, $b) => [$h($b), (float) $b['carton_kg'] * (int) $b['cartons'], $a['name']] <=> [$h($a), (float) $a['carton_kg'] * (int) $a['cartons'], $b['name']]);
        $bins = [];
        foreach ($items as $it) {
            foreach ($bins as &$bin) {
                if (self::stack([...$bin, $it], $limits, $pallet)['ok']) { $bin[] = $it; continue 2; }
            }
            unset($bin);
            $bins[] = [$it];
        }
        return $bins;
    }

    /** Ta sama podłoga : wymiary kartonu ± SAME_FLOOR_MM i tyle samo kartonów na warstwę. */
    private static function sameFloor(array $a, array $b): bool
    {
        return $a['per_layer'] === $b['per_layer'] && abs($a['l'] - $b['l']) <= self::SAME_FLOOR_MM && abs($a['w'] - $b['w']) <= self::SAME_FLOOR_MM;
    }

    /** Moduł podłogi kartonu (np. 590 × 390 → « 600 × 400 »), zaokrąglony do 50 mm. */
    private static function module(array $g): string
    {
        return (int) (round($g['l'] / 50) * 50) . ' × ' . (int) (round($g['w'] / 50) * 50);
    }
}
