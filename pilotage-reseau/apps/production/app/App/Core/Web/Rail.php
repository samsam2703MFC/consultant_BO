<?php
declare(strict_types=1);

namespace App\Core\Web;

/**
 * [253] Menu ERP (rail) bez dubli : jedna pozycja = jedna praca (makieta 25), reszta to zakładki na stronie pozycji
 * (PageTabs::GROUPS pod tym samym kluczem). Wspólne dla wszystkich (26 pozycji) ; stanowisko może mieć krótsze menu
 * (n_position_menu, makieta 26) — strony spoza menu zostają dostępne z wyszukiwarki (Ctrl K) i linków.
 * Uprawnienia do stron bez zmian (PageAccess) : rail i tak ukrywa linki, których użytkownik nie widzi.
 */
final class Rail
{
    /** Sekcje w kolejności railu ('' = pozycje wspólne bez nagłówka). */
    public const SECTIONS = ['', 'Analiza', 'Produkcja', 'Zakupy', 'Produkt', 'Sprzedaż', 'Logistyka', 'Administracja'];

    /**
     * klucz => [etykieta, ikona, sekcja, strona otwierana z railu, inne ścieżki tej pozycji (podświetlenie, zapasowy link),
     * licznik (data-count : /ajax/rail-counters) albo null]. Zakładki pozycji : PageTabs::GROUPS[klucz].
     */
    public const ITEMS = [
        'today' => ['Dzisiaj', 'bi-sun', '', '/today', ['/my-tasks', '/my-card'], null],
        'mail' => ['Poczta', 'bi-envelope', '', '/mail', [], 'mail'],
        'team_tasks' => ['Zadania zespołu', 'bi-people', '', '/organization/team-tasks', [], null],
        'profitability' => ['Rentowność', 'bi-graph-up', 'Analiza', '/dashboard', [], null],
        'margins' => ['Marże i ceny', 'bi-percent', 'Analiza', '/finances/product-margin', [], null],
        'costs' => ['Koszty', 'bi-cash-coin', 'Analiza', '/finances/cost-analysis', [], null],
        'production' => ['Produkcja', 'bi-gear-wide-connected', 'Produkcja', '/workshop/sheet', ['/production'], null],
        'warehouse' => ['Magazyn', 'bi-building', 'Produkcja', '/workshop/stock', ['/stock'], null],
        'team' => ['Zespół', 'bi-person-badge', 'Produkcja', '/production-employees', ['/staff'], null],
        // [262] Czas pracy (moduł Pointages) : dzień do zatwierdzenia, miesiąc, urlopy, nadgodziny ; licznik = dni do zatwierdzenia.
        'time' => ['Czas pracy', 'bi-clock-history', 'Produkcja', '/time', [], 'time'],
        'hr' => ['HR', 'bi-person-vcard', 'Produkcja', '/hr', [], null],
        'tablets' => ['Tablety', 'bi-tablet', 'Produkcja', '/workshop/execution', ['/workshop/timesheet'], null],
        'purchasing' => ['Zakupy', 'bi-cart3', 'Zakupy', '/workshop/buy', ['/settings/ksef/purchase-invoices', '/purchases'], null],
        'materials' => ['Surowce i ceny', 'bi-basket', 'Zakupy', '/purchasing', ['/material-categories', '/materials'], null],
        'suppliers' => ['Dostawcy', 'bi-truck-front', 'Zakupy', '/material-suppliers', [], null],
        'products' => ['Produkty', 'bi-box-seam', 'Produkt', '/workshop/products', ['/product-categories', '/products'], null],
        'packaging' => ['Opakowania', 'bi-recycle', 'Produkt', '/products/cartons', [], null],
        'orders' => ['Zamówienia', 'bi-receipt', 'Sprzedaż', '/client-orders', [], 'queue'],
        'clients' => ['Klienci', 'bi-shop', 'Sprzedaż', '/clients', ['/price-lists'], null],
        'complaints' => ['Reklamacje i zwroty', 'bi-arrow-counterclockwise', 'Sprzedaż', '/franchise-complaints', ['/client-complaints'], 'complaints'],
        'invoicing' => ['Faktury i płatności', 'bi-cash-stack', 'Sprzedaż', '/finances/payments-day', ['/finances/payment-proofs', '/communication/compose/reminder'], null],
        'shipping_day' => ['Dzień wysyłki', 'bi-calendar-check', 'Logistyka', '/logistics', [], null],
        'trucks' => ['Ciężarówki', 'bi-calendar-range', 'Logistyka', '/logistics/plan', [], null],
        'shipments' => ['Wysyłki i dokumenty', 'bi-file-earmark-text', 'Logistyka', '/shipments', ['/shipping-transport-documents', '/shipping-documents', '/archive'], null],
        'settings' => ['Ustawienia', 'bi-gear', 'Administracja', '/settings', ['/other-settings', '/settings/languages', '/settings/ksef', '/settings/panel-api',
            '/settings/mail-footers', '/settings/ai', '/communication/email-settings', '/communication/automations', '/devices', '/aliases', '/users',
            '/organization/positions', '/logistics/tours', '/logistics/vehicles', '/product-carriers', '/task-forms'], null],
        'access' => ['Uprawnienia i menu', 'bi-shield-lock', 'Administracja', '/organization/access', ['/organization/week-plan'], null],
        // [256] Płace i premie (tylko administrator) : budżet personelu, premie zespołów i moduł kierownika produkcji
        // (karta, cele, raport, premia, rozmowa, audyt) — przeniesione z Produkcja › Zespół : płace i premie ustala administrator.
        'bonuses' => ['Płace i premie', 'bi-wallet2', 'Administracja', '/staff/team-bonus', [], null],
        'activity' => ['Aktywność', 'bi-activity', 'Administracja', '/organization/activity', [], null],
    ];

    /** Grupy zakładek bez własnej pozycji w railu : należą do pozycji (podświetlenie, wyszukiwarka). */
    public const GROUP_PARENT = ['communication' => 'settings', 'templates' => 'settings', 'production_settings' => 'settings'];

    /**
     * Menu stanowiska bieżącego użytkownika : klucz pozycji => strona otwierana (null = strona pozycji) albo null = całe
     * menu (administrator, stanowisko bez własnego menu). Ustawia App (PageAccessService::railMenu).
     * @var array<string, ?string>|null
     */
    public static ?array $only = null;

    /** Wszystkie ścieżki pozycji : strona, zakładki (także grup podrzędnych) i inne ścieżki. @return list<string> */
    public static function paths(string $key): array
    {
        [, , , $path, $also] = self::ITEMS[$key];
        $out = [$path];
        foreach (self::groupsOf($key) as $group) {
            foreach (PageTabs::GROUPS[$group]['tabs'] as $tab) if (empty($tab[4])) $out[] = $tab[0];
        }
        return array_values(array_unique(array_merge($out, $also)));
    }

    /** Grupy zakładek pozycji (własna i podrzędne). @return list<string> */
    public static function groupsOf(string $key): array
    {
        $groups = isset(PageTabs::GROUPS[$key]) ? [$key] : [];
        foreach (self::GROUP_PARENT as $group => $parent) if ($parent === $key) $groups[] = $group;
        return $groups;
    }

    /** Pozycja railu strony (najdłuższy pasujący prefiks ścieżek pozycji) albo null. */
    public static function itemFor(string $path): ?string
    {
        $path = '/' . trim(strtok($path, '?#') ?: '/', '/');
        $best = null;
        $bestLen = -1;
        foreach (array_keys(self::ITEMS) as $key) {
            foreach (self::paths($key) as $p) {
                if (($path === $p || str_starts_with($path, rtrim($p, '/') . '/')) && strlen($p) > $bestLen) {
                    $best = $key;
                    $bestLen = strlen($p);
                }
            }
        }
        return $best;
    }

    /** Klucze uprawnień (PageAccess) stron pozycji — bez stron wspólnych i stron tylko dla administratora. @return list<string> */
    public static function accessKeys(string $key): array
    {
        $keys = [];
        foreach (self::paths($key) as $p) {
            $k = PageAccess::keyFor($p);
            if ($k !== null && !PageAccess::adminOnly($k)) $keys[$k] = true;
        }
        return array_keys($keys);
    }

    /** Pozycja tylko dla administratora : wszystkie jej strony są w katalogu i tylko dla administratora. */
    public static function adminOnly(string $key): bool
    {
        $any = false;
        foreach (self::paths($key) as $p) {
            $k = PageAccess::keyFor($p);
            if ($k === null || !PageAccess::adminOnly($k)) return false;
            $any = true;
        }
        return $any;
    }

    /**
     * Rail do wyświetlenia : sekcje z pozycjami (menu stanowiska albo całe), link, ścieżki podświetlenia, licznik.
     * @param array<string, ?string>|null $only
     * @return list<array{title: string, items: list<array{key: string, label: string, icon: string, href: string, also: list<string>, count: ?string}>}>
     */
    public static function menu(?array $only = null): array
    {
        $only ??= self::$only;
        $sections = [];
        foreach (self::SECTIONS as $s) $sections[$s] = ['title' => $s, 'items' => []];
        foreach (self::ITEMS as $key => [$label, $icon, $section, $path, , $count]) {
            if ($only !== null && !array_key_exists($key, $only)) continue;
            $href = $only[$key] ?? $path;
            $sections[$section]['items'][] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'href' => $href,
                'also' => array_values(array_diff(self::paths($key), [$href])), 'count' => $count];
        }
        return array_values(array_filter($sections, fn($s) => $s['items'] !== []));
    }

    /** Całe menu (26 pozycji) bez względu na stanowisko : wyszukiwarka, strona « Uprawnienia i menu ». */
    public static function all(): array
    {
        return self::menu(array_fill_keys(array_keys(self::ITEMS), null));
    }

    /** Liczba pozycji w menu (całe menu albo menu stanowiska). */
    public static function count(?array $only = null): int
    {
        return array_sum(array_map(fn($s) => count($s['items']), self::menu($only)));
    }
}
