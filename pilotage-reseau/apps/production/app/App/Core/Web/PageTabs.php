<?php
declare(strict_types=1);

namespace App\Core\Web;

/**
 * Grupy stron pokazywane jako zakładki zamiast podmenu w railu : jedna pozycja w railu na grupę,
 * a na każdej stronie grupy pasek zakładek nad nagłówkiem (układ Twig i widoki .view.php).
 * Wyszukiwarka dostaje wszystkie strony grup z ukrytej listy w sidebar.twig.
 */
final class PageTabs
{
    /**
     * grupa => [rail : etykieta, sekcja, ikona ; tabs : [ścieżka, etykieta, ikona, tylko ta ścieżka (bez podstron) ?,
     * 'blank' = przycisk otwierający nową kartę (tablety)]]. [253] Klucz grupy = klucz pozycji railu (Rail::ITEMS) :
     * jedna pozycja w railu, jej strony jako zakładki ; grupy bez pozycji należą do pozycji z Rail::GROUP_PARENT.
     */
    public const GROUPS = [
        // --- Wspólne
        'mail' => ['label' => 'Poczta', 'section' => 'Start', 'icon' => 'bi-envelope', 'tabs' => [
            ['/mail', 'Skrzynka', 'bi-inbox'],
            ['/mail/settings', 'Ustawienia skrzynki', 'bi-envelope-at'],
        ]],
        // Zadania zespołu i « Kto co zrobił » (wykonanie zadań stanowisk) : jedna pozycja.
        'team_tasks' => ['label' => 'Zadania zespołu', 'section' => 'Start', 'icon' => 'bi-people', 'tabs' => [
            ['/organization/team-tasks', 'Zadania zespołu', 'bi-people'],
            ['/organization/execution', 'Kto co zrobił', 'bi-clipboard-check'],
        ]],
        // --- Analiza
        // Rentowność : pulpit, wynik grup (miesiąc), rentowność grup (tydzień), raport miesięczny, analiza sprzedaży.
        'profitability' => ['label' => 'Rentowność', 'section' => 'Analiza', 'icon' => 'bi-graph-up', 'tabs' => [
            ['/dashboard', 'Pulpit', 'bi-speedometer2'],
            ['/dashboard/results', 'Wynik grup : miesiąc', 'bi-bar-chart-steps'],
            ['/dashboard/groups', 'Rentowność grup : tydzień', 'bi-calendar-week'],
            ['/dashboard/monthly', 'Raport miesięczny', 'bi-file-earmark-bar-graph'],
            ['/finances/sales-analytics', 'Analiza sprzedaży', 'bi-bar-chart-line'],
        ]],
        'margins' => ['label' => 'Marże i ceny', 'section' => 'Analiza', 'icon' => 'bi-percent', 'tabs' => [
            ['/finances/product-margin', 'Marża produktów', 'bi-percent'],
            ['/finances/category-margin', 'Marża kategorii', 'bi-bar-chart-line'],
            ['/finances/price-wizard', 'Kreator cen', 'bi-magic'],
        ]],
        // Koszty : produktu i działów, kalkulator, faktury i koszty (przypisania, zatwierdzenie), usługi miesiąca, zamknięcie miesiąca.
        'costs' => ['label' => 'Koszty', 'section' => 'Analiza', 'icon' => 'bi-cash-coin', 'tabs' => [
            ['/finances/cost-analysis', 'Koszty produktu', 'bi-search'],
            ['/finances/cost-analysis/section', 'Koszty działów', 'bi-cash'],
            ['/cost-calculator', 'Kalkulator kosztów', 'bi-calculator'],
            ['/finances/invoices', 'Faktury i koszty', 'bi-inboxes'],
            ['/finances/costs', 'Koszty usług miesiąca', 'bi-calendar2-range'],
            ['/month-close', 'Zamknięcie miesiąca', 'bi-calendar2-check'],
        ]],
        // --- Produkcja
        // Produkcja na jednej stronie : arkusz tygodnia (jedna linia na produkt), plan tygodnia (dni), plan zespołów
        // (druk, tablet) z listami dnia (PDF), prognoza 13 tygodni, moce produkcyjne, dostawy surowców, partie, śledzenie
        // produkcji, niezgodności. Stanowiska robocze i tabela do wypełnienia : w Ustawieniach (production_settings).
        'production' => ['label' => 'Produkcja', 'section' => 'Produkcja', 'icon' => 'bi-gear-wide-connected', 'tabs' => [
            ['/workshop/sheet', 'Arkusz tygodnia', 'bi-table'],
            ['/workshop/gantt', 'Plan tygodnia', 'bi-calendar-week'],
            ['/workshop/team-sheet', 'Plan zespołów i listy dnia (PDF)', 'bi-printer'],
            // Prognoza 13 tygodni [220] : kroczące tygodnie, gamy sezonowe panelu TF Buddy, zakupy, zatwierdzenie w poniedziałek.
            ['/production/forecast', 'Prognoza 13 tygodni', 'bi-graph-up'],
            // Moce produkcyjne [234] : wykorzystanie maszyn wobec planu, co jeszcze zmieszczę, ilość na zmianę, ustawienia maszyn.
            ['/production/capacity', 'Moce produkcyjne', 'bi-speedometer2'],
            ['/workshop/deliveries', 'Dostawy surowców', 'bi-truck'],
            ['/workshop/traceability', 'Partie i identyfikowalność', 'bi-upc-scan'],
            ['/production/follow-up', 'Śledzenie produkcji', 'bi-graph-up-arrow'],
            ['/purchasing/nc', 'Niezgodności', 'bi-exclamation-octagon'],
        ]],
        // Magazyn produktów gotowych : stan dziś (zamówienia, min., maks., do produkcji), zapas MTS (kartony, palety, wartość),
        // ruchy (przyjęcia, wydania, rozbieżności). Ta sama aplikacja co Karty produktów, otwarta na widoku magazynu.
        'warehouse' => ['label' => 'Magazyn', 'section' => 'Produkcja', 'icon' => 'bi-building', 'tabs' => [
            ['/workshop/stock', 'Stan magazynu', 'bi-bar-chart-line'],
            ['/workshop/stock/mts', 'Zapas MTS', 'bi-boxes'],
            ['/workshop/stock/forecast', 'Prognoza zapasu i okresy', 'bi-calendar2-range'],
            ['/workshop/stock-movements', 'Ruchy magazynowe', 'bi-arrow-left-right'],
        ]],
        // Zespół : pracownicy produkcji. [256] Budżet personelu, premie zespołów i moduł kierownika → Administracja › Płace i premie.
        'team' => ['label' => 'Zespół', 'section' => 'Produkcja', 'icon' => 'bi-person-badge', 'tabs' => [
            ['/production-employees', 'Pracownicy produkcji', 'bi-person-badge'],
        ]],
        // Tablety : zarządzanie (tablety, PIN, czas pracy), zatwierdzenia produkcji, zamówienia między grupami ;
        // przyciski otwierają tablety w nowej karcie.
        'tablets' => ['label' => 'Tablety', 'section' => 'Produkcja', 'icon' => 'bi-tablet', 'tabs' => [
            ['/workshop/execution', 'Tablety, PIN, czas pracy', 'bi-tablet'],
            ['/workshop/production-days', 'Zatwierdzenia produkcji', 'bi-check2-square'],
            ['/workshop/group-orders', 'Zamówienia między grupami', 'bi-arrow-left-right'],
            ['/tablet/office', 'Otwórz tablet biurowy', 'bi-box-arrow-up-right', false, 'blank'],
            ['/tablet/production', 'Otwórz tablet grupy', 'bi-box-arrow-up-right', false, 'blank'],
            ['/tablet/driver', 'Otwórz tablet kierowcy', 'bi-box-arrow-up-right', false, 'blank'],
            ['/tablet/packing', 'Otwórz tablet pakowania', 'bi-box-arrow-up-right', false, 'blank'],
        ]],
        // --- Zakupy
        // Zakupy : zakupy tygodnia (kreator), zaopatrzenie (zapotrzebowanie, zamówienia, faktury dostawców), kontrola przyjęć.
        'purchasing' => ['label' => 'Zakupy', 'section' => 'Zakupy', 'icon' => 'bi-cart3', 'tabs' => [
            ['/workshop/buy', 'Zakupy tygodnia', 'bi-cart3'],
            ['/workshop/supply', 'Zaopatrzenie i faktury dostawców', 'bi-cart-check'],
            ['/workshop/receiving', 'Kontrola przyjęć', 'bi-box-arrow-in-down'],
        ]],
        // Surowce i ceny : koszty surowców, ceny, oferty, karty surowców, wartości odżywcze.
        'materials' => ['label' => 'Surowce i ceny', 'section' => 'Zakupy', 'icon' => 'bi-basket', 'tabs' => [
            ['/purchasing', 'Koszty surowców', 'bi-basket', true],
            ['/materials/prices', 'Ceny surowców', 'bi-tags'],
            ['/purchasing/offers', 'Oferty cenowe', 'bi-tag'],
            ['/materials/all', 'Surowce', 'bi-basket2'],
            ['/materials/nutritional-values', 'Wartości odżywcze', 'bi-heart-pulse'],
        ]],
        'suppliers' => ['label' => 'Dostawcy', 'section' => 'Zakupy', 'icon' => 'bi-truck-front', 'tabs' => [
            ['/material-suppliers', 'Dostawcy', 'bi-truck-front'],
            ['/purchasing/tenders', 'Zapytania ofertowe', 'bi-envelope'],
            ['/purchasing/supplier-review', 'Ocena dostawców (kwartał)', 'bi-award'],
        ]],
        // --- Produkt
        // Produkty na jednej stronie : karty produktów (lista ERP, kategorie, MTO / MTS, stany), receptury, półprodukty,
        // kompozycje, nowe produkty (etapy od pomysłu do wprowadzenia, testy z tabletu grupy) i lista produktów testowych.
        'products' => ['label' => 'Produkty', 'section' => 'Produkt', 'icon' => 'bi-box-seam', 'tabs' => [
            ['/workshop/products', 'Karty produktów', 'bi-diagram-3'],
            ['/recipes', 'Receptury', 'bi-journal-text'],
            ['/semi-products', 'Półprodukty', 'bi-diagram-2'],
            ['/compositions', 'Kompozycje', 'bi-layers'],
            // Produkty w rozwoju : etapy (pipeline) i lista na jednej zakładce, przełącznik na stronie (N7).
            ['/test-products', 'Nowe produkty (etapy i lista)', 'bi-kanban'],
        ]],
        // Opakowania : kartony i palety, BDO (wagi, dane, raporty) i PPWR (rejestr, kartony sprzedażowe, raporty).
        'packaging' => ['label' => 'Opakowania', 'section' => 'Produkt', 'icon' => 'bi-recycle', 'tabs' => [
            ['/products/cartons', 'Kartony i palety', 'bi-boxes'],
            ['/audits/product-weights', 'Wagi produktów', 'bi-speedometer2'],
            ['/audits/packaging-data', 'Dane opakowań BDO', 'bi-clipboard-data'],
            ['/audits/packaging-reports', 'Raporty BDO', 'bi-file-earmark-bar-graph'],
            ['/ppwr/register', 'Rejestr PPWR', 'bi-journal-check'],
            ['/ppwr/sales-packaging', 'Kartony sprzedażowe PPWR', 'bi-box-seam'],
            ['/ppwr/reports', 'Raporty PPWR', 'bi-file-earmark-text'],
        ]],
        // --- Sprzedaż
        'orders' => ['label' => 'Zamówienia', 'section' => 'Sprzedaż', 'icon' => 'bi-receipt', 'tabs' => [
            ['/client-orders', 'Zamówienia klientów', 'bi-receipt'],
            ['/client-orders/queue', 'Do obsłużenia', 'bi-inbox'],
        ]],
        'clients' => ['label' => 'Klienci', 'section' => 'Sprzedaż', 'icon' => 'bi-shop', 'tabs' => [
            ['/clients', 'Klienci', 'bi-shop'],
            ['/finances/clients', 'Wolumen i marża', 'bi-graph-up'],
            ['/price-lists/general', 'Cennik ogólny', 'bi-list-ol'],
            ['/client-franchise-networks', 'Sieci franczyzowe', 'bi-diagram-3'],
            ['/franchise-products', 'Produkty franczyzy', 'bi-shop-window'],
        ]],
        // Reklamacje (franczyza) i zwroty : jedna pozycja.
        'complaints' => ['label' => 'Reklamacje i zwroty', 'section' => 'Sprzedaż', 'icon' => 'bi-arrow-counterclockwise', 'tabs' => [
            ['/franchise-complaints', 'Reklamacje', 'bi-exclamation-triangle'],
            ['/client-complaints/to-ship', 'Zwroty do wysłania', 'bi-box-arrow-up'],
            ['/client-complaints/pending-products', 'Oczekujące produkty', 'bi-hourglass-split'],
            ['/client-complaints/pending-shipment', 'Oczekujące zwroty (wysyłka)', 'bi-truck'],
            ['/client-complaints/picking-list', 'Listy kompletacyjne', 'bi-list-check'],
            ['/client-complaints/shipping-documents', 'Dokumenty zwrotów', 'bi-file-earmark-text'],
        ]],
        // Faktury i płatności : płatności dnia (wpłaty, potwierdzenia), proformy, faktury, przypomnienia płatności.
        'invoicing' => ['label' => 'Faktury i płatności', 'section' => 'Sprzedaż', 'icon' => 'bi-cash-stack', 'tabs' => [
            ['/finances/payments-day', 'Płatności dnia', 'bi-cash-stack'],
            ['/proforma-documents', 'Proformy', 'bi-file-earmark'],
            ['/invoice-documents', 'Faktury', 'bi-file-earmark-text'],
            ['/communication/payment-reminders', 'Przypomnienia płatności', 'bi-bell'],
        ]],
        // --- Logistyka
        // Dzień wysyłki : jeden dzień logistyki w kolejności — plan dnia, załadunek ciężarówki, ekran paletyzacji, dokumenty
        // WZ / CMR / WDT (Wyślij dzień), wydruki dla kierowców, powiadomienia klientów, potwierdzenia dostaw z tabletu kierowcy.
        'shipping_day' => ['label' => 'Dzień wysyłki', 'section' => 'Logistyka', 'icon' => 'bi-calendar-check', 'tabs' => [
            ['/logistics', 'Plan dnia', 'bi-stack', true],   // /logistics/week, /vehicles, /tours : osobne strony
            ['/logistics/loading', 'Załadunek ciężarówki', 'bi-truck-flatbed'],
            ['/logistics/live', 'Ekran paletyzacji', 'bi-display'],
            ['/shipping/wizard', 'Wyślij dzień', 'bi-send-check'],
            ['/logistics/documents', 'Dokumenty dla kierowców', 'bi-printer'],
            ['/logistics/notices', 'Powiadomienia klientów', 'bi-chat-dots'],
            ['/logistics/deliveries', 'Potwierdzenia dostaw', 'bi-clipboard2-check'],
        ]],
        // Ciężarówki : plan na 12 tygodni (prognoza palet, klienci na ciężarówkach, święta, urlopy kierowców) [200] i tydzień
        // z zamówień (ciężarówki dzień po dniu, wypełnienie).
        'trucks' => ['label' => 'Ciężarówki', 'section' => 'Logistyka', 'icon' => 'bi-calendar-range', 'tabs' => [
            ['/logistics/plan', 'Plan 12 tygodni', 'bi-calendar-range'],
            ['/logistics/week', 'Tydzień : ciężarówki', 'bi-calendar-week'],
        ]],
        // Jedna lista wysyłek z filtrem rodzaju dokumentu (dawniej Wysyłki, Dokumenty transportowe i Archiwum WZ / CMR / WDT / różnic)
        // oraz archiwum list kompletacyjnych.
        'shipments' => ['label' => 'Wysyłki i dokumenty', 'section' => 'Logistyka', 'icon' => 'bi-file-earmark-text', 'tabs' => [
            ['/shipments', 'Wysyłki i dokumenty', 'bi-truck'],
            ['/shipments/differences', 'Różnice i problemy dostaw', 'bi-exclamation-diamond'],
            ['/archive/documents/picking-lists', 'Archiwum list kompletacyjnych', 'bi-list-check'],
        ]],
        // --- Administracja
        // Aktywność w ERP i licznik stron : jedna pozycja w Administracji (N7).
        // [256] Płace i premie (tylko administrator) : budżet personelu, premie zespołów, moduł kierownika produkcji.
        'bonuses' => ['label' => 'Płace i premie', 'section' => 'Administracja', 'icon' => 'bi-wallet2', 'tabs' => [
            ['/staff/team-bonus', 'Premie zespołów', 'bi-trophy'],
            ['/workshop/manager', 'Kierownik produkcji', 'bi-person-workspace'],
        ]],
        'activity' => ['label' => 'Aktywność', 'section' => 'Administracja', 'icon' => 'bi-activity', 'tabs' => [
            ['/organization/activity', 'Aktywność w ERP', 'bi-activity'],
            ['/settings/page-usage', 'Licznik stron', 'bi-bar-chart'],
        ]],
        // Grupy w Ustawieniach (Rail::GROUP_PARENT) : komunikacja, wzory, ustawienia produkcji.
        // Komunikacja : kto dostaje jakie dokumenty, historia wiadomości, stan wysyłek (błędy, kolejki, ponów jedną pozycję).
        'communication' => ['label' => 'Komunikacja', 'section' => 'Ustawienia', 'icon' => 'bi-envelope', 'tabs' => [
            ['/communication/distribution', 'Dystrybucja dokumentów', 'bi-diagram-3'],
            ['/communication/messages', 'Historia wiadomości', 'bi-envelope-open'],
            ['/communication/outbox', 'Stan wysyłek', 'bi-send-exclamation'],
        ]],
        // Wzory wydruków PDF (przykłady w językach klientów) i szablony e-maili
        'templates' => ['label' => 'Wzory dokumentów i e-maili', 'section' => 'Ustawienia', 'icon' => 'bi-file-earmark-richtext', 'tabs' => [
            ['/communication/document-templates', 'Dokumenty PDF', 'bi-file-earmark-pdf'],
            ['/communication/templates', 'Szablony e-mail', 'bi-envelope-paper'],
        ]],
        // Ustawienia produkcji : stanowiska robocze i coboty, tabela do wypełnienia (Excel : dane rzeczywiste pracowni → import).
        'production_settings' => ['label' => 'Ustawienia produkcji', 'section' => 'Ustawienia', 'icon' => 'bi-sliders', 'tabs' => [
            ['/workshop/workstations', 'Stanowiska robocze i coboty', 'bi-robot'],
            ['/workshop/data-sheet', 'Tabela do wypełnienia', 'bi-file-earmark-spreadsheet'],
        ]],
    ];

    /** [253] Liczniki na zakładkach (data-count : /ajax/rail-counters) — ta sama liczba co przy pozycji railu. */
    public const TAB_COUNTS = ['/mail' => 'mail', '/franchise-complaints' => 'complaints', '/client-orders/queue' => 'queue'];

    /** Bieżąca ścieżka trasy (jak w App::run : z parametru url). */
    public static function currentPath(): string
    {
        return isset($_GET['url']) ? '/' . trim((string) $_GET['url'], '/') : '/';
    }

    /** Grupa strony (najdłuższe dopasowanie : ścieżka równa zakładce albo jej podstrona) albo null. */
    public static function forPath(string $path): ?array
    {
        $best = null;
        $bestLen = -1;
        foreach (self::GROUPS as $key => $group) {
            foreach ($group['tabs'] as $tab) {
                if (($tab[4] ?? '') === 'blank') continue;
                $url = $tab[0];
                if (($path === $url || (empty($tab[3]) && str_starts_with($path, $url . '/'))) && strlen($url) > $bestLen) {
                    $best = $key;
                    $bestLen = strlen($url);
                }
            }
        }
        if ($best === null) return null;
        $group = self::GROUPS[$best];
        $active = null;
        foreach ($group['tabs'] as $tab) {
            if (($path === $tab[0] || (empty($tab[3]) && str_starts_with($path, $tab[0] . '/'))) && strlen($tab[0]) === $bestLen) $active = $tab[0];
        }
        $group['key'] = $best;
        $group['tabs'] = array_map(fn(array $t) => ['url' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'active' => $t[0] === $active, 'blank' => ($t[4] ?? '') === 'blank'], $group['tabs']);
        return $group;
    }

    /** Grupy, w których zakładki przenoszą dzień strony (?date=) : ten sam dzień na każdej zakładce. */
    private const DATED = ['shipping_day'];

    /** Pasek zakładek grupy bieżącej strony (pusty, gdy strona nie należy do grupy). */
    public static function html(string $root, ?string $path = null, ?string $date = null): string
    {
        $group = self::forPath($path ?? self::currentPath());
        if ($group === null) return '';
        $query = in_array($group['key'], self::DATED, true) && $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? '?date=' . $date : '';
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
        $links = '';
        foreach ($group['tabs'] as $t) {
            if (!$t['active'] && !PageAccess::visible($t['url'])) continue;
            $blank = $t['blank'] ? ' target="_blank" rel="noopener"' : '';
            $links .= '<a class="at-page-tab' . ($t['active'] ? ' active" aria-current="page' : '') . ($t['blank'] ? ' at-page-tab-open' : '') . '" href="' . $e($root . $t['url'] . ($t['blank'] ? '' : $query)) . '"' . $blank . '>'
                . '<i class="bi ' . $e($t['icon']) . '"></i> ' . $e($t['label'])
                . (isset(self::TAB_COUNTS[$t['url']]) ? ' <span class="at-count" data-count="' . self::TAB_COUNTS[$t['url']] . '" hidden></span>' : '') . '</a>';
        }
        return '<nav class="at-page-tabs" aria-label="' . $e($group['label']) . '"><span class="at-page-tabs-title"><i class="bi ' . $e($group['icon']) . '"></i> '
            . $e($group['label']) . '</span>' . $links . '</nav>';
    }
}
