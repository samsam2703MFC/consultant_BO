<?php
namespace App\Services\Logistics;

/**
 * Palety klienta na dzień wysyłki, z kartonów zamówionych i karty produktu.
 *
 * Każdy produkt : pełne palety = kartony ÷ kartony na paletę (cpp), w dół. Reszty kartonów idą na
 * palety mieszane — tylko z kartonami tego samego rodzaju (wymiar kartonu z karty opakowań :
 * na jednej palecie układa się kartony jednego formatu). Palety mieszane wypełniane po kolei
 * (największe reszty najpierw), reszta produktu może przejść na następną paletę. Bez mieszania :
 * osobna paleta na każdą resztę. Resztki różnych formatów słabo zapełnione (logistics.leftover_fill_max)
 * mogą jechać razem na jednej palecie. Waga : kartony × sztuki w kartonie × gramatura + tara każdej
 * palety. Produkt bez cpp nie może być policzony : sygnalizowany, jego kartony pominięte w paletach.
 */
final class PalletCalculator
{
    /**
     * @param list<array{cartons:float|int, cpp:int|string|null, ppc?:int|string|null, weight_g?:float|string|null,
     *                   product?:string, carton_type_id?:?int, carton_type?:?string, category?:?string}> $lines
     * @return array{cartons:float, pallets:int, full_pallets:int, mixed_pallets:int, pallets_exact:float, weight_kg:float, missing_cpp:int, carton_types:list<array>}
     */
    public static function compute(array $lines, bool $mixed = true, float $tareKg = 25.0, float $leftoverMax = 0.0): array
    {
        $cartons = 0.0;
        $exact = 0.0;
        $goodsKg = 0.0;
        $missing = 0;
        foreach ($lines as $l) {
            $qty = (float) $l['cartons'];
            if ($qty <= 0) continue;
            $cartons += $qty;
            $goodsKg += $qty * (float) ($l['ppc'] ?? 0) * (float) ($l['weight_g'] ?? 0) / 1000;
            $cpp = (int) ($l['cpp'] ?? 0);
            if ($cpp <= 0) { $missing++; continue; }
            $exact += $qty / $cpp;
        }
        $types = self::layout($lines, $mixed);
        if ($mixed && $leftoverMax > 0) $types = self::combineLeftovers($types, $leftoverMax);
        $full = 0;
        $partial = 0;
        foreach ($types as $type) {
            foreach ($type['pallets'] as $p) $p['kind'] === 'full' ? $full++ : $partial++;
        }
        $pallets = $full + $partial;
        return [
            'cartons' => $cartons,
            'pallets' => $pallets,
            'full_pallets' => $full,
            'mixed_pallets' => $partial,
            'pallets_exact' => round($exact, 2),
            'weight_kg' => round($goodsKg + $pallets * $tareKg, 1),
            'missing_cpp' => $missing,
            'carton_types' => $types,
        ];
    }

    /**
     * Resztki różnych formatów (palety mieszane zapełnione poniżej $max) układane razem : kolejno od
     * najpełniejszej, dopóki suma zapełnień ≤ 1 — tak jak przy załadunku małe ilości kładzie się na
     * jednej palecie. Grupa „różne formaty” na końcu ; palety ≥ $max zostają w swoim formacie.
     */
    public static function combineLeftovers(array $types, float $max): array
    {
        $leftovers = [];
        foreach ($types as $t => $type) {
            foreach ($type['pallets'] as $i => $p) {
                if ($p['kind'] !== 'full' && $p['fill'] < $max) $leftovers[] = [$t, $i, $p + ['format' => $type['name'] ?: '—']];
            }
        }
        if (count(array_unique(array_column($leftovers, 0))) < 2) return $types;
        usort($leftovers, fn($a, $b) => $b[2]['fill'] <=> $a[2]['fill']);
        $bins = [];
        foreach ($leftovers as [$t, $i, $p]) {
            $placed = false;
            foreach ($bins as &$bin) {
                if ($bin['fill'] + $p['fill'] <= 1.0 + 1e-9) {
                    $bin['fill'] = round($bin['fill'] + $p['fill'], 3);
                    foreach ($p['items'] as $item) $bin['items'][] = $item + ['format' => $p['format']];
                    $placed = true;
                    break;
                }
            }
            unset($bin);
            if (!$placed) $bins[] = ['kind' => 'formats', 'fill' => $p['fill'], 'items' => array_map(fn($item) => $item + ['format' => $p['format']], $p['items'])];
            unset($types[$t]['pallets'][$i]);
        }
        foreach ($types as &$type) $type['pallets'] = array_values($type['pallets']);
        unset($type);
        $types[] = ['id' => 'formats', 'name' => 'Różne formaty kartonów (resztki razem)', 'cartons' => array_sum(array_map(fn($b) => array_sum(array_column($b['items'], 'cartons')), $bins)),
            'pallets' => $bins, 'categories' => []];
        return array_values(array_filter($types, fn($t) => $t['pallets'] || $t['categories']));
    }

    /**
     * Palety po kolei, pogrupowane według rodzaju kartonu, z kategoriami produktów w każdym rodzaju.
     * @return list<array{id:?int, name:string, cartons:float, pallets:list<array{kind:string, fill:float, items:list<array{product:string, cartons:float}>}>,
     *                    categories:list<array{name:string, cartons:float, pallets_exact:float, products:list<array>}>}>
     */
    public static function layout(array $lines, bool $mixed = true): array
    {
        $types = [];
        foreach ($lines as $l) {
            $qty = (float) $l['cartons'];
            if ($qty <= 0) continue;
            $key = $l['carton_type_id'] ?? null;
            $key = $key === null ? 'none' : (string) $key;
            $types[$key] ??= ['id' => $key === 'none' ? null : (int) $key, 'name' => $l['carton_type'] ?? null, 'cartons' => 0.0, 'lines' => []];
            $types[$key]['cartons'] += $qty;
            $types[$key]['lines'][] = $l;
        }
        $out = [];
        foreach ($types as $type) {
            $pallets = [];
            $rests = [];
            $categories = [];
            foreach ($type['lines'] as $l) {
                $qty = (float) $l['cartons'];
                $cpp = (int) ($l['cpp'] ?? 0);
                $product = (string) ($l['product'] ?? '');
                $category = (string) (($l['category'] ?? null) ?: '—');
                $categories[$category] ??= ['name' => $category, 'cartons' => 0.0, 'pallets_exact' => 0.0, 'products' => []];
                $categories[$category]['cartons'] += $qty;
                $categories[$category]['pallets_exact'] += $cpp > 0 ? $qty / $cpp : 0;
                $categories[$category]['products'][] = ['name' => $product, 'cartons' => $qty, 'cpp' => $cpp ?: null, 'pallets_exact' => $cpp > 0 ? round($qty / $cpp, 2) : null];
                if ($cpp <= 0) continue;
                $whole = (int) floor($qty / $cpp + 1e-9);
                for ($i = 0; $i < $whole; $i++) $pallets[] = ['kind' => 'full', 'fill' => 1.0, 'items' => [['product' => $product, 'cartons' => (float) $cpp]]];
                $rest = $qty - $whole * $cpp;
                if ($rest > 1e-9) $rests[] = ['product' => $product, 'cartons' => $rest, 'cpp' => $cpp];
            }
            if ($mixed) {
                usort($rests, fn($a, $b) => $b['cartons'] / $b['cpp'] <=> $a['cartons'] / $a['cpp']);
                $current = null;
                foreach ($rests as $r) {
                    $left = $r['cartons'];
                    while ($left > 1e-9) {
                        if ($current === null) $current = ['kind' => 'mixed', 'fill' => 0.0, 'items' => []];
                        $fit = min($left, floor((1 - $current['fill']) * $r['cpp'] + 1e-9));
                        if ($fit <= 0) { $pallets[] = $current; $current = null; continue; }
                        $current['items'][] = ['product' => $r['product'], 'cartons' => $fit];
                        $current['fill'] = min(1.0, $current['fill'] + $fit / $r['cpp']);
                        $left -= $fit;
                    }
                }
                if ($current !== null) $pallets[] = $current;
            } else {
                foreach ($rests as $r) $pallets[] = ['kind' => 'partial', 'fill' => $r['cartons'] / $r['cpp'], 'items' => [['product' => $r['product'], 'cartons' => $r['cartons']]]];
            }
            foreach ($pallets as &$p) $p['fill'] = round($p['fill'], 3);
            unset($p);
            uasort($categories, fn($a, $b) => $b['cartons'] <=> $a['cartons']);
            $out[] = [
                'id' => $type['id'], 'name' => $type['name'] ?? '', 'cartons' => $type['cartons'], 'pallets' => $pallets,
                'categories' => array_values(array_map(fn($c) => ['pallets_exact' => round($c['pallets_exact'], 2)] + $c, $categories)),
            ];
        }
        usort($out, fn($a, $b) => [$a['id'] === null, -count($a['pallets'])] <=> [$b['id'] === null, -count($b['pallets'])]);
        return $out;
    }
}
