<?php
declare(strict_types=1);

namespace App\Domain\Client\Order;

/**
 * Jedna definicja zamówienia otwartego dla Dzisiaj, Stanów magazynowych, kart produktów, planowania, Rentowności,
 * logistyki, reklamacji i e-maili :
 *  - otwarte = nowe (status 1) i jeszcze bez WDT ; « Tak, wysłano » (status 3) i « Nie » (status 5) zamykają zamówienie ;
 *  - ilości (produkcja, magazyn, palety, wartość) bez zamówień GRATIS — nie są skanowane ani paletyzowane ;
 *  - dni wysyłki : wszystkie zamówienia otwarte (także gratisy, które jadą z dostawą).
 */
final class OpenOrders
{
    /** Warunek SQL zamówienia otwartego (alias tabeli customer_order). */
    public static function sql(string $alias = 'co'): string
    {
        return "{$alias}.status = " . CustomerOrderStatus::PENDING . " AND {$alias}.id_wdt IS NULL";
    }

    /** Zamówienia otwarte liczone w ilościach : bez gratisów. */
    public static function quantitySql(string $alias = 'co'): string
    {
        return self::sql($alias) . " AND COALESCE({$alias}.is_freebie, 0) = 0";
    }

    /**
     * Widok załadunku (logistyka) : zamówienia otwarte oraz już wysłane, bez zarchiwizowanych bez wysyłki,
     * historycznego statusu 4 i gratisów — dzień po wysyłce zostaje widoczny w załadunku i tygodniu ciężarówek.
     */
    public static function loadingSql(string $alias = 'co'): string
    {
        return "({$alias}.status = " . CustomerOrderStatus::PENDING . " OR {$alias}.status = " . CustomerOrderStatus::IN_TRANSIT
            . " OR {$alias}.status = " . CustomerOrderStatus::COMPLETED . ") AND COALESCE({$alias}.is_freebie, 0) = 0";
    }

    /** Stan magazynu do porównania z zamówieniami : kartony wolne (1) i już na paletach (2). */
    public const STOCK_STATUSES = '1, 2';
}
