<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

/**
 * Calcul des besoins (sans base) : arrondi au lot, réapprovisionnement MTS plafonné au stock
 * maximum, plafond du surgélateur. Quantités dans l'unité de base de l'article.
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
     * Réapprovisionnement d'un article MTS.
     * stock projeté = stock libre + OF MTS en cours − commandes confirmées servies sur stock ;
     * cible = couverture (jours) × consommation journalière, bornée par stock min et stock max ;
     * quantité = cible − projeté, arrondie au lot, sans jamais dépasser le stock max
     * (le stock max l'emporte sur le lot minimum : la quantité est alors réduite et signalée).
     *
     * @return array{projected:float, target:float, need:float, qty:float, capped:bool, below_batch_min:bool, reason:string}
     */
    public static function mts(float $dailyConsumption, float $coverageDays, ?float $stockMin, float $stockMax,
                               float $freeStock, float $openOrders, float $committed, ?float $batchMin, ?float $batchMultiple,
                               bool $reorderOnMin = true): array
    {
        $projected = $freeStock + $openOrders - $committed;
        $target = $dailyConsumption * max(0.0, $coverageDays);
        if ($stockMin !== null) $target = max($target, $stockMin);
        $target = min($target, $stockMax);
        $trigger = $reorderOnMin && $stockMin !== null ? $projected < $stockMin : $projected < $target;
        $result = ['projected' => $projected, 'target' => $target, 'need' => 0.0, 'qty' => 0.0, 'capped' => false, 'below_batch_min' => false,
            'reason' => $trigger ? 'below_target' : 'stock_sufficient'];
        if (!$trigger || $target - $projected <= 0) return $result;

        $need = $target - $projected;
        $qty = self::roundBatch($need, $batchMin, $batchMultiple);
        $room = $stockMax - $projected;
        if ($qty > $room) {
            $step = $batchMultiple !== null && $batchMultiple > 0 ? $batchMultiple : 1.0;
            $qty = max(0.0, floor(round($room / $step, 6)) * $step);
            $result['capped'] = true;
        }
        $result['need'] = $need;
        $result['qty'] = (float) $qty;
        $result['below_batch_min'] = $qty > 0 && $batchMin !== null && $qty < $batchMin;
        return $result;
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
