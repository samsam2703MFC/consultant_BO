<?php

namespace App\Services\Shipping;

/** Pure calculation rules used for packaging snapshots. */
class ShipmentPackagingCalculator
{
    /** @return array{packaging_units_total:float,total_weight_g:?float,requires_unit_weight:bool} */
    public static function calculate(float|int $shippedCartons, float|int $unitsPerCarton, string $unit, float|int|null $unitWeightG): array
    {
        $totalUnits = round((float)$shippedCartons * (float)$unitsPerCarton, 6);
        $normalisedUnit = mb_strtolower(trim($unit));
        if (in_array($normalisedUnit, ['g', 'gram', 'gramy'], true)) {
            return [
                'packaging_units_total' => $totalUnits,
                'total_weight_g' => round($totalUnits, 4),
                'requires_unit_weight' => false,
            ];
        }

        $weight = (float)($unitWeightG ?? 0);
        return [
            'packaging_units_total' => $totalUnits,
            'total_weight_g' => $weight > 0 ? round($totalUnits * $weight, 4) : null,
            'requires_unit_weight' => true,
        ];
    }
}
