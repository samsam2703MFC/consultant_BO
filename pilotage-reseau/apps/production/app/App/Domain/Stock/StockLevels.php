<?php
declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Stany min. / maks. obowiązujące dziś [216] : okres stanów wyjątkowych zatwierdzony i trwający dziś (n_stock_period,
 * np. Boże Narodzenie 15.11 – 15.01) zastępuje stany normalne karty (n_prod_product_settings) ; poza okresem — normalne,
 * bez żadnego zapisu (powrót sam dzień po końcu). Okresy zatwierdzone nie nakładają się, więc najwyżej jeden na produkt.
 * Fragmenty SQL dla zapytań, które czytają stany (Stan magazynu, planowanie, tablet) : JOIN po n_prod_item (alias $item).
 */
final class StockLevels
{
    /** LEFT JOIN okresu wyjątkowego dziś (alias xp : stock_min, stock_max, period_name, period_to) po n_prod_item. */
    public static function join(string $item = 'i'): string
    {
        return "LEFT JOIN (SELECT pi.id_item, pi.stock_min, pi.stock_max, p.name AS period_name, p.date_to AS period_to
                           FROM n_stock_period_item pi JOIN n_stock_period p ON p.id = pi.id_period
                           WHERE p.status = 'approved' AND CURDATE() BETWEEN p.date_from AND p.date_to) xp ON xp.id_item = {$item}.id";
    }

    /** Kolumny : stock_min / stock_max obowiązujące, stany normalne i nazwa okresu ($s = alias n_prod_product_settings). */
    public static function columns(string $s = 's'): string
    {
        return "COALESCE(xp.stock_min, {$s}.stock_min) AS stock_min, COALESCE(xp.stock_max, {$s}.stock_max) AS stock_max,
                {$s}.stock_min AS normal_stock_min, {$s}.stock_max AS normal_stock_max, xp.period_name AS stock_period, xp.period_to AS stock_period_to";
    }
}
