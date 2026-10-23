<?php
declare(strict_types=1);

namespace App\Core\Web;

/**
 * Katalog stron ERP do uprawnień stanowisk : jedna pozycja na pozycję railu (grupy z podmenu razem), z prefiksami
 * ścieżek (strona i jej podstrony). Ścieżka należy do pozycji o najdłuższym pasującym prefiksie ; ścieżki spoza
 * katalogu (Dzisiaj, Moje zadania, logowanie, tablet) są dla wszystkich. Pozycje « admin » widzi tylko administrator.
 *
 * Sprawdzenie dla bieżącego użytkownika ustawia App (PageAccess::$checker) — rail, zakładki i linki go używają.
 */
final class PageAccess
{
    public const SECTIONS = ['Analiza', 'Produkcja', 'Produkt', 'Zamówienia', 'Logistyka', 'Finanse', 'Administracja'];

    /** klucz => [etykieta, sekcja, prefiksy ścieżek, tylko administrator] */
    public const PAGES = [
        'dashboard' => ['Rentowność (pulpit, wynik i produkcja grup)', 'Analiza', ['/dashboard'], false],
        'sales' => ['Analiza sprzedaży', 'Analiza', ['/finances/sales-analytics'], false],
        'margins' => ['Marże i koszty (marże, koszty produktu i działów, kalkulator)', 'Analiza', ['/finances', '/cost-calculator'], false],
        'production' => ['Produkcja (arkusz, plan tygodnia, plan zespołów, stany, partie, śledzenie, niezgodności, stanowiska)', 'Produkcja',
            ['/workshop/sheet', '/workshop/gantt', '/workshop/team-sheet', '/stock', '/workshop/traceability', '/production', '/purchasing/nc', '/workshop/workstations'], false],
        'tablet' => ['Tablet produkcji : zarządzanie (tablety, PIN, czas pracy)', 'Produkcja', ['/workshop/execution', '/workshop/timesheet'], false],
        'supply' => ['Zaopatrzenie', 'Produkcja', ['/workshop/supply'], false],
        'employees' => ['Zespół : pracownicy produkcji', 'Produkcja', ['/production-employees'], false],
        'budget' => ['Zespół : budżet personelu i premie', 'Produkcja', ['/staff'], false],
        'product_cards' => ['Karty produktów', 'Produkt', ['/workshop/products'], false],
        'products' => ['Produkty, receptury, półprodukty, kompozycje', 'Produkt', ['/product-categories', '/recipes', '/test-products', '/products', '/semi-products', '/compositions'], false],
        'materials' => ['Surowce (kategorie, wartości odżywcze, ceny, dostawcy)', 'Produkt', ['/material-categories', '/materials', '/material-suppliers'], false],
        'bdo' => ['Opakowania BDO', 'Produkt', ['/audits'], false],
        'ppwr' => ['PPWR', 'Produkt', ['/ppwr'], false],
        'orders' => ['Zamówienia klientów', 'Zamówienia', ['/client-orders'], false],
        'clients' => ['Klienci', 'Zamówienia', ['/clients'], false],
        'franchise' => ['Franczyza', 'Zamówienia', ['/client-franchise-networks', '/franchise-products', '/franchise-complaints'], false],
        'invoices' => ['Proformy i faktury', 'Zamówienia', ['/proforma-documents', '/invoice-documents'], false],
        'returns' => ['Zwroty', 'Zamówienia', ['/client-complaints'], false],
        'communication' => ['Komunikacja (automatyzacje, przypomnienia, szablony, wiadomości)', 'Zamówienia', ['/communication'], false],
        'archive' => ['Archiwum dokumentów', 'Zamówienia', ['/archive'], false],
        'logistics' => ['Logistyka (tydzień, załadunek dnia, trasy i pojazdy)', 'Logistyka', ['/logistics'], false],
        'shipping' => ['Wysyłka', 'Logistyka', ['/shipping', '/shipments', '/shipping-transport-documents', '/shipping-documents', '/product-carriers'], false],
        'price_lists' => ['Cennik ogólny', 'Finanse', ['/price-lists'], false],
        'purchasing' => ['Zakupy i jakość (koszty surowców, oferty, zapytania)', 'Finanse', ['/purchasing'], false],
        'ksef_purchases' => ['Faktury zakupowe KSeF', 'Finanse', ['/settings/ksef/purchase-invoices', '/purchases'], false],
        'positions' => ['Stanowiska i zadania, protokoły', 'Administracja', ['/organization', '/task-forms'], false],
        'settings' => ['Ustawienia (użytkownicy, aliasy, języki, e-mail, KSeF, licznik stron, inne)', 'Administracja',
            ['/users', '/aliases', '/settings', '/communication/email-settings', '/other-settings'], false],
        'devices' => ['Urządzenia', 'Administracja', ['/devices'], false],
        'manager' => ['Kierownik produkcji : stanowisko, cele, raport, premia, rozmowa, audyt', 'Administracja', ['/workshop/manager'], true],
        'access' => ['Uprawnienia stanowisk', 'Administracja', ['/organization/access'], true],
        'activity' => ['Aktywność w ERP (sesje, strony, kliknięcia, koszt czasu)', 'Administracja', ['/organization/activity'], true],
        'panel_api' => ['Konto API panelu (ceny w sklepach franczyzy)', 'Administracja', ['/settings/panel-api'], true],
    ];

    /** @var (callable(string): bool)|null sprawdzenie ścieżki dla zalogowanego użytkownika (null = wszystko widoczne) */
    public static $checker = null;

    /** @var array<string, bool> prefiks => widoczny dla użytkownika (rail i wyszukiwarka ; pusta = wszystko widoczne) */
    public static array $rail = [];

    /** Pozycja katalogu dla ścieżki (najdłuższy prefiks) albo null (strona wspólna). */
    public static function keyFor(string $path): ?string
    {
        $path = '/' . trim(strtok($path, '?#') ?: '/', '/');
        $best = null;
        $bestLen = -1;
        foreach (self::PAGES as $key => [, , $prefixes]) {
            foreach ($prefixes as $p) {
                if (($path === $p || str_starts_with($path, $p . '/')) && strlen($p) > $bestLen) {
                    $best = $key;
                    $bestLen = strlen($p);
                }
            }
        }
        return $best;
    }

    public static function adminOnly(string $key): bool
    {
        return (bool) (self::PAGES[$key][3] ?? false);
    }

    /** @return list<string> klucze, które można przyznać stanowisku */
    public static function grantable(): array
    {
        return array_values(array_filter(array_keys(self::PAGES), fn($k) => !self::adminOnly($k)));
    }

    /** Czy bieżący użytkownik widzi stronę (ścieżka bez prefiksu aplikacji). */
    public static function visible(string $path): bool
    {
        return self::$checker === null || (self::$checker)($path);
    }
}
