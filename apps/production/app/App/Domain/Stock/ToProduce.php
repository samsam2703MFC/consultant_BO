<?php
declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Jedna formuła « do produkcji » (kartony), wspólna dla /stock i « Stan magazynu » (karty produktów) :
 *  - zamówione     = zamówienia bez WDT, jeszcze nie paletyzowane (status Nowe / W realizacji, gratisy też) ;
 *  - na zamówienia = zamówione − kartony wolne na magazynie ;
 *  - do minimum   = minimum − to, co zostaje po zamówieniach (z zleceniami MTS w toku), tylko gdy jest minimum ;
 *  - razem        = na zamówienia + do minimum.
 * Kartony na paletach należą do zamówień « Paletyzowane » : nie liczą się ani do zamówionych, ani do stanu.
 */
final class ToProduce
{
    /** @return array{available: float, orders: float, to_min: ?float, total: float} */
    public static function split(float $ordered, float $free, ?float $min, float $inProduction = 0.0): array
    {
        $available = round($free - $ordered + $inProduction, 1);
        $orders = round(max(0.0, $ordered - $free), 1);
        $toMin = $min === null ? null : round(max(0.0, $min - max(0.0, $available)), 1);
        return ['available' => $available, 'orders' => $orders, 'to_min' => $toMin, 'total' => round($orders + ($toMin ?? 0.0), 1)];
    }
}
