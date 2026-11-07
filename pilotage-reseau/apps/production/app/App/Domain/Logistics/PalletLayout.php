<?php
declare(strict_types=1);

namespace App\Domain\Logistics;

/**
 * Układ kartonów na palecie [205] (katalog kartonów, krok « Paleta » karty produktu).
 * Warstwa : jednolita (karton wzdłuż albo w poprzek palety) albo dwa bloki (część w jednym kierunku, reszta obrócona)
 * — najwięcej kartonów ; wystawanie poza paletę (mm z każdej strony) powiększa podłogę. Warstwy : ile mieści się
 * w maks. wysokości (z paletą) i w maks. wadze brutto. Piętrowanie : dwie palety jedna na drugiej w ciężarówce, gdy
 * obie mieszczą się w wysokości wnętrza (z zapasem GAP_MM).
 */
final class PalletLayout
{
    /** Palety : wymiary podłogi (mm), wysokość podstawy (mm), waga pustej (kg). */
    public const PALLETS = [
        'eur' => ['length_mm' => 1200, 'width_mm' => 800, 'base_mm' => 144, 'tare_kg' => 25.0],
        'industrial' => ['length_mm' => 1200, 'width_mm' => 1000, 'base_mm' => 144, 'tare_kg' => 30.0],
    ];
    /** Zapas nad dwiema paletami piętrowanymi (mm). */
    public const GAP_MM = 100;

    /**
     * Najlepsza warstwa : prostokąty kartonów (mm, od lewego górnego rogu palety ; mogą wystawać o $overhang).
     * @return list<array{x: float, y: float, w: int, h: int}>
     */
    public static function layer(int $length, int $width, int $palletLength, int $palletWidth, int $overhang = 0): array
    {
        if ($length <= 0 || $width <= 0) return [];
        $pl = $palletLength + 2 * $overhang;
        $pw = $palletWidth + 2 * $overhang;
        $grid = function (int $x0, int $y0, int $l, int $w, int $a, int $b): array {
            $out = [];
            if ($a <= 0 || $b <= 0) return $out;
            for ($i = 0; $i < intdiv($l, $a); $i++) for ($j = 0; $j < intdiv($w, $b); $j++) $out[] = ['x' => $x0 + $i * $a, 'y' => $y0 + $j * $b, 'w' => $a, 'h' => $b];
            return $out;
        };
        $best = [];
        foreach ([[$length, $width], [$width, $length]] as [$a, $b]) {
            $candidates = [$grid(0, 0, $pl, $pw, $a, $b)];
            for ($i = 1; $i * $a <= $pl; $i++) $candidates[] = [...$grid(0, 0, $i * $a, $pw, $a, $b), ...$grid($i * $a, 0, $pl - $i * $a, $pw, $b, $a)];
            for ($j = 1; $j * $b <= $pw; $j++) $candidates[] = [...$grid(0, 0, $pl, $j * $b, $a, $b), ...$grid(0, $j * $b, $pl, $pw - $j * $b, $b, $a)];
            foreach ($candidates as $c) if (count($c) > count($best)) $best = $c;
        }
        if (!$best) return [];
        // Wyśrodkowanie na palecie.
        $mx = max(array_map(fn($r) => $r['x'] + $r['w'], $best));
        $my = max(array_map(fn($r) => $r['y'] + $r['h'], $best));
        $dx = ($palletLength - $mx) / 2;
        $dy = ($palletWidth - $my) / 2;
        return array_map(fn($r) => ['x' => $r['x'] + $dx, 'y' => $r['y'] + $dy, 'w' => $r['w'], 'h' => $r['h']], $best);
    }

    /**
     * Pełny układ palety.
     * @param array{length_mm:?int, width_mm:?int, height_mm:?int, tare_g:?float} $carton
     * @param array{max_height_mm:int, max_kg:int, overhang_mm:int, truck_height_mm:int} $limits
     * @param ?float $netKg waga zawartości kartonu (kg) ; null = nieznana (waga brutto i limit wagi pominięte)
     */
    public static function plan(array $carton, string $pallet, array $limits, ?float $netKg): ?array
    {
        $p = self::PALLETS[$pallet] ?? self::PALLETS['eur'];
        [$l, $w, $h] = [(int) ($carton['length_mm'] ?? 0), (int) ($carton['width_mm'] ?? 0), (int) ($carton['height_mm'] ?? 0)];
        if ($l <= 0 || $w <= 0 || $h <= 0) return null;
        $rects = self::layer($l, $w, $p['length_mm'], $p['width_mm'], $limits['overhang_mm']);
        $perLayer = count($rects);
        $cartonKg = $netKg !== null ? $netKg + ((float) ($carton['tare_g'] ?? 0)) / 1000 : null;
        $byHeight = max(0, intdiv($limits['max_height_mm'] - $p['base_mm'], $h));
        $byWeight = $cartonKg !== null && $cartonKg > 0 && $perLayer > 0 ? max(0, (int) floor(($limits['max_kg'] - $p['tare_kg']) / ($perLayer * $cartonKg))) : null;
        $layers = $byWeight !== null ? min($byHeight, $byWeight) : $byHeight;
        $stackHeight = intdiv($limits['truck_height_mm'] - self::GAP_MM, 2);
        $stackLayers = max(0, intdiv($stackHeight - $p['base_mm'], $h));
        if ($byWeight !== null) $stackLayers = min($stackLayers, $byWeight);
        $option = fn(int $layers, int $places) => [
            'layers' => $layers,
            'cartons' => $perLayer * $layers,
            'height_mm' => $p['base_mm'] + $layers * $h,
            'gross_kg' => $cartonKg !== null ? round($perLayer * $layers * $cartonKg + $p['tare_kg'], 1) : null,
            'per_place' => $perLayer * $layers * $places,
        ];
        $max = $option($layers, 1);
        $stack = $stackLayers > 0 && $stackLayers * 2 * $perLayer > $max['cartons'] ? $option($stackLayers, 2) : null;
        return [
            'pallet' => $pallet,
            'pallet_length_mm' => $p['length_mm'], 'pallet_width_mm' => $p['width_mm'], 'base_mm' => $p['base_mm'], 'pallet_tare_kg' => $p['tare_kg'],
            'carton' => ['length_mm' => $l, 'width_mm' => $w, 'height_mm' => $h],
            'carton_kg' => $cartonKg !== null ? round($cartonKg, 3) : null,
            'per_layer' => $perLayer,
            'rects' => $rects,
            'layers_by_height' => $byHeight,
            'layers_by_weight' => $byWeight,
            'floor_pct' => round($perLayer * $l * $w / ($p['length_mm'] * $p['width_mm']) * 100, 1),
            'max' => $max,
            'stack' => $stack,
            'stack_height_mm' => $stackHeight,
            'limits' => $limits,
        ];
    }

    /**
     * Ocena układu z ERP (kartony na warstwę × warstwy = na paletę) wobec planu : lista problemów (kody).
     * @return list<array{code:string, params:array}>
     */
    public static function check(array $plan, int $cpl, int $lpp, int $cpp): array
    {
        $out = [];
        if ($cpl <= 0 || $lpp <= 0) return [['code' => 'ERP_EMPTY', 'params' => []]];
        if ($cpl > $plan['per_layer']) $out[] = ['code' => 'LAYER_TOO_MANY', 'params' => ['cpl' => $cpl, 'max' => $plan['per_layer']]];
        $height = $plan['base_mm'] + $lpp * $plan['carton']['height_mm'];
        if ($height > $plan['limits']['max_height_mm']) $out[] = ['code' => 'TOO_HIGH', 'params' => ['height' => $height, 'max' => $plan['limits']['max_height_mm']]];
        if ($plan['carton_kg'] !== null) {
            $kg = round(($cpp ?: $cpl * $lpp) * $plan['carton_kg'] + $plan['pallet_tare_kg'], 1);
            if ($kg > $plan['limits']['max_kg']) $out[] = ['code' => 'TOO_HEAVY', 'params' => ['kg' => $kg, 'max' => $plan['limits']['max_kg']]];
        }
        return $out;
    }
}
