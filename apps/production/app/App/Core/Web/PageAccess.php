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
    public const SECTIONS = ['Analiza', 'Produkcja', 'Zakupy', 'Produkt', 'Sprzedaż', 'Logistyka i pakowanie', 'Tablety', 'Administracja'];

    /** klucz => [etykieta, sekcja, prefiksy ścieżek, tylko administrator] */
    public const PAGES = [
        'dashboard' => ['Rentowność (pulpit, wynik i produkcja grup, raport miesięczny)', 'Analiza', ['/dashboard'], false],
        'sales' => ['Analiza sprzedaży', 'Analiza', ['/finances/sales-analytics'], false],
        'margins' => ['Marże i koszty (marże, koszty produktu i działów, kalkulator, zamknięcie miesiąca ; Finanse : faktury i koszty, koszty usług miesiąca)', 'Analiza', ['/finances', '/cost-calculator', '/month-close'], false],
        'production' => ['Produkcja (arkusz, plan tygodnia, plan zespołów, stany, ruchy magazynowe, partie, śledzenie, niezgodności, stanowiska, tabela do wypełnienia)', 'Produkcja',
            ['/workshop/sheet', '/workshop/gantt', '/workshop/team-sheet', '/stock', '/workshop/stock-movements', '/workshop/traceability', '/production', '/purchasing/nc', '/workshop/workstations', '/workshop/data-sheet'], false],
        'tablet' => ['Tablety : zarządzanie (tablety, PIN, czas pracy, zatwierdzenia produkcji)', 'Tablety', ['/workshop/execution', '/workshop/timesheet', '/workshop/production-days'], false],
        'supply' => ['Zaopatrzenie (zapotrzebowanie, zakupy tygodnia, zamówienia, faktury, stan)', 'Zakupy', ['/workshop/supply', '/workshop/buy'], false],
        'purchasing' => ['Koszty surowców, oferty i zapytania', 'Zakupy', ['/purchasing'], false],
        'supplier_prices' => ['Ceny surowców i dostawcy', 'Zakupy', ['/materials/prices', '/material-suppliers'], false],
        'ksef_purchases' => ['Faktury zakupowe KSeF (pobieranie) i archiwum dostaw', 'Zakupy', ['/settings/ksef/purchase-invoices', '/purchases'], false],
        'employees' => ['Zespół : pracownicy produkcji', 'Produkcja', ['/production-employees'], false],
        'budget' => ['Zespół : budżet personelu i premie', 'Produkcja', ['/staff'], false],
        'product_cards' => ['Produkty : karty produktów (lista, kategorie, MTO / MTS, stany)', 'Produkt', ['/workshop/products'], false],
        'products' => ['Produkty : receptury, półprodukty, kompozycje, produkty w rozwoju', 'Produkt', ['/product-categories', '/recipes', '/test-products', '/products', '/semi-products', '/compositions'], false],
        'materials' => ['Surowce (kategorie, wartości odżywcze)', 'Produkt', ['/material-categories', '/materials'], false],
        'bdo' => ['Opakowania BDO', 'Produkt', ['/audits'], false],
        'ppwr' => ['PPWR', 'Produkt', ['/ppwr'], false],
        'orders' => ['Zamówienia klientów', 'Sprzedaż', ['/client-orders'], false],
        'clients' => ['Klienci', 'Sprzedaż', ['/clients'], false],
        'price_lists' => ['Cennik ogólny', 'Sprzedaż', ['/price-lists'], false],
        'franchise' => ['Franczyza', 'Sprzedaż', ['/client-franchise-networks', '/franchise-products', '/franchise-complaints'], false],
        'invoices' => ['Proformy i faktury', 'Sprzedaż', ['/proforma-documents', '/invoice-documents'], false],
        'reminders' => ['Przypomnienia płatności (proformy 1–5, dopłaty różnic)', 'Sprzedaż', ['/communication/payment-reminders', '/communication/compose/reminder'], false],
        'client_volume' => ['Klienci : wolumen i marża (N / N-1, marża, należności)', 'Sprzedaż', ['/finances/clients'], false],
        'returns' => ['Zwroty', 'Sprzedaż', ['/client-complaints'], false],
        'communication' => ['Komunikacja (dystrybucja dokumentów i e-maile automatyczne, wzory dokumentów i e-maili, wiadomości)', 'Sprzedaż', ['/communication'], false],
        'logistics' => ['Logistyka i pakowanie (ekran paletyzacji, pakowanie, załadunek ciężarówki, tydzień, plan dnia, dokumenty dostawy, trasy i pojazdy)', 'Logistyka i pakowanie', ['/logistics'], false],
        'shipping' => ['Wysyłka (wyślij dzień, wysyłki i dokumenty, przewoźnicy)', 'Logistyka i pakowanie', ['/shipping', '/shipments', '/shipping-transport-documents', '/shipping-documents', '/product-carriers'], false],
        'archive' => ['Archiwum list kompletacyjnych', 'Logistyka i pakowanie', ['/archive'], false],
        'positions' => ['Funkcje i zadania, protokoły', 'Administracja', ['/organization', '/task-forms'], false],
        'settings' => ['Ustawienia (użytkownicy, aliasy, języki, e-mail, stopki e-maili i tłumacz, KSeF, licznik stron, inne)', 'Administracja',
            ['/users', '/aliases', '/settings', '/communication/email-settings', '/other-settings'], false],
        'devices' => ['Urządzenia (dostęp API)', 'Administracja', ['/devices'], false],
        'manager' => ['Kierownik produkcji : stanowisko, cele, raport, premia, rozmowa, audyt', 'Administracja', ['/workshop/manager'], true],
        'access' => ['Uprawnienia funkcji', 'Administracja', ['/organization/access'], true],
        'activity' => ['Aktywność w ERP (sesje, strony, kliknięcia, koszt czasu)', 'Administracja', ['/organization/activity'], true],
        'panel_api' => ['API cen w sklepach (konto panelu franczyzy)', 'Administracja', ['/settings/panel-api'], true],
    ];

    /** Strony startowe do wyboru dla stanowiska (logo, logowanie) : Dzisiaj dla produkcji, Rentowność dla kierownictwa. */
    public const HOME_PAGES = ['/today' => 'Dzisiaj', '/dashboard' => 'Rentowność', '/finances/invoices' => 'Faktury i koszty'];

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
