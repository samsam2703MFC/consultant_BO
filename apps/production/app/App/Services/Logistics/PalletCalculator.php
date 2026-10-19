<?php
namespace App\Services\Logistics;

/**
 * Palety klienta na dzień wysyłki, z kartonów zamówionych i karty produktu.
 *
 * Każdy produkt : pełne palety = kartony ÷ kartony na paletę (cpp), w dół. Reszty kartonów :
 * paleta mieszana (suma ułamków reszt, w górę) albo — bez mieszania — osobna paleta na każdą
 * resztę. Waga : kartony × sztuki w kartonie × gramatura + tara każdej palety. Produkt bez cpp nie
 * może być policzony : sygnalizowany, jego kartony pominięte w paletach.
 */
final class PalletCalculator
{
    /**
     * @param list<array{cartons:float|int, cpp:int|string|null, ppc?:int|string|null, weight_g?:float|string|null}> $lines
     * @return array{cartons:float, pallets:int, full_pallets:int, mixed_pallets:int, pallets_exact:float, weight_kg:float, missing_cpp:int}
     */
    public static function compute(array $lines, bool $mixed = true, float $tareKg = 25.0): array
    {
        $cartons = 0.0;
        $full = 0;
        $remainders = 0.0;
        $separate = 0;
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
            $whole = (int) floor($qty / $cpp + 1e-9);
            $full += $whole;
            $rest = $qty - $whole * $cpp;
            if ($rest > 1e-9) {
                $remainders += $rest / $cpp;
                $separate++;
            }
        }
        $mixedPallets = $mixed ? (int) ceil($remainders - 1e-9) : $separate;
        $pallets = $full + $mixedPallets;
        return [
            'cartons' => $cartons,
            'pallets' => $pallets,
            'full_pallets' => $full,
            'mixed_pallets' => $mixedPallets,
            'pallets_exact' => round($exact, 2),
            'weight_kg' => round($goodsKg + $pallets * $tareKg, 1),
            'missing_cpp' => $missing,
        ];
    }
}
