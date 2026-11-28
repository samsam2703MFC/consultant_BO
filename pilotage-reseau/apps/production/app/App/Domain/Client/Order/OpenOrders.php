<?php
declare(strict_types=1);

namespace App\Domain\Client\Order;

/**
 * Jedna definicja zamówienia otwartego dla Dzisiaj, Stanów magazynowych, kart produktów, planowania, Rentowności,
 * logistyki, reklamacji i e-maili :
 *  - otwarte = nowe (status 1) i jeszcze bez WDT ; « Tak, wysłano » (status 3) i « Nie » (status 5) zamykają zamówienie ;
 *    Wyślij dzień przestawia zamówienia dnia na « W transporcie » (3), potwierdzenie kierowcy na « Zrealizowane » (2) ;
 *  - lista zamówień (Oczekujące) = otwarte ; Archiwum zamówień = zamknięte (closedSql), bez historycznego statusu 4 ;
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

    /** Zamówienia zamknięte (Archiwum zamówień) : zrealizowane, wysłane, zarchiwizowane i « Oczekujące » już z WDT. */
    public static function closedSql(string $alias = 'co'): string
    {
        return "({$alias}.status IN (" . implode(', ', CustomerOrderStatus::archivalValues()) . ") OR ({$alias}.status = "
            . CustomerOrderStatus::PENDING . " AND {$alias}.id_wdt IS NOT NULL))";
    }

    /** Status pokazywany : « Oczekujące » z WDT (wysłane przed automatycznym statusem Wyślij dzień) = « W transporcie ». */
    public static function effectiveStatusSql(string $alias = 'co'): string
    {
        return "CASE WHEN {$alias}.status = " . CustomerOrderStatus::PENDING . " AND {$alias}.id_wdt IS NOT NULL THEN "
            . CustomerOrderStatus::IN_TRANSIT . " ELSE {$alias}.status END";
    }

    /**
     * [408] Zamówienia otwarte do zrobienia i wysłania (produkcja, stan, plan, załadunek) : RAZEM z gratisami — gratis
     * jest produkowany, kompletowany i wysyłany jak każde zamówienie (WDT zamyka go razem z innymi zamówieniami klienta
     * z tego dnia). Audyt z 23.11.2026 : gratis 1527 (Brownies) nie był widoczny w planie zespołu.
     */
    public static function demandSql(string $alias = 'co'): string
    {
        return self::sql($alias);
    }

    /**
     * Zamówienia otwarte w WARTOŚCIACH i statystykach handlowych (rentowność, płynność, średnie zamówienia) : bez
     * gratisów. Nigdy dla ilości do zrobienia albo wysłania — tam demandSql() (tests/freebie_demand_test.php).
     */
    public static function quantitySql(string $alias = 'co'): string
    {
        return self::sql($alias) . " AND COALESCE({$alias}.is_freebie, 0) = 0";
    }

    /**
     * Widok załadunku (logistyka) : zamówienia otwarte oraz już wysłane, bez zarchiwizowanych bez wysyłki i
     * historycznego statusu 4 — dzień po wysyłce zostaje widoczny w załadunku i tygodniu ciężarówek. [408] Gratisy
     * jadą na tej samej ciężarówce : liczą się (kartony, palety, waga).
     */
    public static function loadingSql(string $alias = 'co'): string
    {
        return "({$alias}.status = " . CustomerOrderStatus::PENDING . " OR {$alias}.status = " . CustomerOrderStatus::IN_TRANSIT
            . " OR {$alias}.status = " . CustomerOrderStatus::COMPLETED . ")";
    }

    /** Stan magazynu do porównania z zamówieniami : kartony wolne (1) i już na paletach (2). */
    public const STOCK_STATUSES = '1, 2';
}
