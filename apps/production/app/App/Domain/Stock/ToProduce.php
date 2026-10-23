<?php
declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Jedna formuła « do produkcji » (kartony), wspólna dla /stock i « Stan magazynu » (karty produktów) :
 *  - zamówione     = zamówienia otwarte (App\Domain\Client\Order\OpenOrders : Nowe, bez WDT, bez gratisów) ;
 *  - stan          = kartony wolne + kartony na paletach (OpenOrders::STOCK_STATUSES) — zamówienie paletyzowane
 *                    zostaje otwarte do WDT, więc jego kartony na paletach liczą się po obu stronach, nigdy podwójnie ;
 *  - na zamówienia = zamówione − stan ;
 *  - do minimum   = minimum − to, co zostaje po zamówieniach (z zleceniami MTS w toku), tylko gdy jest minimum ;
 *  - razem        = na zamówienia + do minimum.
 * Ta sama reguła w planie tygodnia (PlanningRepository::stockCartons).
 */
final class ToProduce
{
    /**
     * Stan minimalny w kartonach (SQL) : MTS z kartą = stan min. karty ÷ szt./karton ; MTO = brak minimum ;
     * produkt bez karty produkcyjnej = stan minimalny ERP. Alias tfb_prod_product_settings i final_product.
     */
    public static function minCartonsSql(string $settings = 'ts', string $product = 'fp'): string
    {
        return "CASE WHEN {$settings}.mode = 'MTS' AND {$settings}.stock_min IS NOT NULL AND {$product}.ppc > 0 THEN {$settings}.stock_min / {$product}.ppc
                     WHEN {$settings}.mode IS NULL THEN NULLIF({$product}.stock_minimum, 0)
                     ELSE NULL END";
    }

    /** Zlecenia MTS w toku (sztuki) — po stronie « dostępne » formuły. Alias tfb_prod_order. */
    public const IN_PRODUCTION_WHERE = "origin = 'MTS' AND (status = 'in_progress' OR (status IN ('planned', 'released') AND due_at >= CURDATE()))";

    /** @return array{available: float, orders: float, to_min: ?float, total: float} */
    public static function split(float $ordered, float $free, ?float $min, float $inProduction = 0.0): array
    {
        $available = round($free - $ordered + $inProduction, 1);
        $orders = round(max(0.0, $ordered - $free), 1);
        $toMin = $min === null ? null : round(max(0.0, $min - max(0.0, $available)), 1);
        return ['available' => $available, 'orders' => $orders, 'to_min' => $toMin, 'total' => round($orders + ($toMin ?? 0.0), 1)];
    }
}
