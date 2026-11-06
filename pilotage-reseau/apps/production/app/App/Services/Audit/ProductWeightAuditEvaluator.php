<?php

namespace App\Services\Audit;

class ProductWeightAuditEvaluator
{
    public const ERROR = 'ERROR';
    public const WARNING = 'WARNING';
    public const OK = 'OK';

    public static function evaluate($weight, $unitsPerCarton): array
    {
        $weightResult = self::normalizeWeight($weight);
        if (!$weightResult['valid']) {
            return [
                'audit_status' => self::ERROR,
                'audit_reason' => $weightResult['reason'],
                'unit_weight_g' => null,
                'units_per_carton' => self::normalizeUnits($unitsPerCarton),
                'carton_net_content_weight_g' => null,
                'carton_weight_reason' => 'Nie można wyliczyć — brak poprawnej masy produktu',
            ];
        }

        $weightG = $weightResult['weight'];
        $units = self::normalizeUnits($unitsPerCarton);
        $status = $weightG === 1.0 ? self::WARNING : self::OK;
        $reason = $status === self::WARNING
            ? 'Masa wynosi dokładnie 1 g i może być wartością domyślną lub błędną'
            : null;

        return [
            'audit_status' => $status,
            'audit_reason' => $reason,
            'unit_weight_g' => $weightG,
            'units_per_carton' => $units,
            'carton_net_content_weight_g' => $units === null ? null : $weightG * $units,
            'carton_weight_reason' => $units === null ? 'Nie można wyliczyć — brak poprawnej liczby sztuk w kartonie' : null,
        ];
    }

    public static function validateInput($weight): float
    {
        $result = self::normalizeWeight($weight);
        if (!$result['valid']) {
            throw new \InvalidArgumentException($result['reason']);
        }
        return $result['weight'];
    }

    private static function normalizeWeight($weight): array
    {
        if ($weight === null || (is_string($weight) && trim($weight) === '')) {
            return ['valid' => false, 'reason' => 'Brak masy produktu'];
        }
        if (is_bool($weight) || !is_numeric(str_replace(',', '.', trim((string) $weight)))) {
            return ['valid' => false, 'reason' => 'Nieprawidłowy format masy'];
        }
        $value = (float) str_replace(',', '.', trim((string) $weight));
        if ($value === 0.0) return ['valid' => false, 'reason' => 'Masa produktu wynosi 0 g'];
        if ($value < 0.0) return ['valid' => false, 'reason' => 'Masa produktu jest ujemna'];
        return ['valid' => true, 'weight' => $value];
    }

    private static function normalizeUnits($units): ?float
    {
        if ($units === null || (is_string($units) && trim($units) === '')) return null;
        if (is_bool($units) || !is_numeric(trim((string) $units))) return null;
        $value = (float) $units;
        return $value > 0 ? $value : null;
    }
}
