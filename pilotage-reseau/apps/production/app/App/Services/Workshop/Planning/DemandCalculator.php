<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

/**
 * Calcul des besoins (sans base) : arrondi au lot, réapprovisionnement MTS (sous le min. → jusqu'au max.),
 * plafond du surgélateur. Quantités dans l'unité de base de l'article.
 */
final class DemandCalculator
{
    /** Arrondi au lot supérieur : au moins le lot minimum, en multiple du multiple de lot. */
    public static function roundBatch(float $qty, ?float $batchMin, ?float $batchMultiple): float
    {
        if ($qty <= 0) return 0.0;
        $q = $qty;
        if ($batchMin !== null && $batchMin > 0) $q = max($q, $batchMin);
        if ($batchMultiple !== null && $batchMultiple > 0) $q = ceil(round($q / $batchMultiple, 6)) * $batchMultiple;
        return (float) $q;
    }

    /**
     * Uzupełnienie magazynu (prosta reguła) : gdy dostępne < stan min., do stanu maks. ; inaczej nic. Bez stanu
     * min. próg = stan maks. Partie liczy się potem dla całego tygodnia produktu (w górę do pełnych partii).
     * @return array{available:float, min:?float, max:float, qty:float, reason:string}
     */
    public static function refill(float $available, ?float $min, float $max): array
    {
        $qty = $available < ($min ?? $max) ? max(0.0, $max - $available) : 0.0;
        return ['available' => $available, 'min' => $min, 'max' => $max, 'qty' => (float) $qty, 'reason' => $qty > 0 ? 'below_min' : 'stock_sufficient'];
    }

    /**
     * Plafond du surgélateur : si l'occupation actuelle plus les cartons MTS proposés dépassent la
     * capacité, les quantités MTS sont réduites (au prorata, arrondies au multiple de lot inférieur).
     * @param list<array{key:string|int, qty:float, ppc:int, multiple:?float}> $lines
     * @return array{lines:array<string|int,float>, limited:bool, free_cartons:float}
     */
    public static function capFreezer(array $lines, int $capacityCartons, int $occupancyCartons): array
    {
        $out = [];
        foreach ($lines as $l) $out[$l['key']] = $l['qty'];
        if ($capacityCartons <= 0) return ['lines' => $out, 'limited' => false, 'free_cartons' => INF];
        $free = max(0, $capacityCartons - $occupancyCartons);
        $wanted = 0.0;
        foreach ($lines as $l) $wanted += $l['ppc'] > 0 ? $l['qty'] / $l['ppc'] : 0.0;
        if ($wanted <= $free) return ['lines' => $out, 'limited' => false, 'free_cartons' => (float) $free];
        $ratio = $wanted > 0 ? $free / $wanted : 0.0;
        foreach ($lines as $l) {
            $step = $l['multiple'] !== null && $l['multiple'] > 0 ? $l['multiple'] : (float) max(1, $l['ppc']);
            $out[$l['key']] = floor(round($l['qty'] * $ratio / $step, 6)) * $step;
        }
        return ['lines' => $out, 'limited' => true, 'free_cartons' => (float) $free];
    }
}
