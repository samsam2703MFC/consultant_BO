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
    /** grupa => [rail : etykieta, sekcja, ikona ; tabs : [ścieżka, etykieta, ikona, tylko ta ścieżka (bez podstron) ?]] */
    public const GROUPS = [
        // Produkcja na jednej stronie : arkusz tygodnia (jedna linia na produkt), plan tygodnia (dni), plan zespołów
        // (druk, tablet) z listami dnia (PDF), dostawy surowców (kto dostarcza co i kiedy), partie, śledzenie produkcji (plan / zrobione / straty), jakość, stanowiska i coboty,
        // tabela do wypełnienia (Excel : dane rzeczywiste pracowni → import).
        // Tablety (biurowy, grup, kierowcy) i ich zarządzanie : osobna sekcja « Tablety » w railu.
        // Wynik grup : miesiąc (wynik i produkcja grup) i tydzień (rentowność grup) na jednej pozycji (audyt 2, N7).
        'group_results' => ['label' => 'Wynik grup', 'section' => 'Analiza', 'icon' => 'bi-bar-chart-steps', 'tabs' => [
            ['/dashboard/results', 'Miesiąc : wynik i produkcja grup', 'bi-bar-chart-steps'],
            ['/dashboard/groups', 'Tydzień : rentowność grup', 'bi-calendar-week'],
        ]],
        'production' => ['label' => 'Produkcja', 'section' => 'Produkcja', 'icon' => 'bi-gear-wide-connected', 'tabs' => [
            ['/workshop/sheet', 'Arkusz tygodnia', 'bi-table'],
            ['/workshop/gantt', 'Plan tygodnia', 'bi-calendar-week'],
            ['/workshop/team-sheet', 'Plan zespołów i listy dnia (PDF)', 'bi-printer'],
            ['/workshop/deliveries', 'Dostawy surowców', 'bi-truck'],
            ['/workshop/traceability', 'Partie i identyfikowalność', 'bi-upc-scan'],
            ['/production/follow-up', 'Śledzenie produkcji', 'bi-graph-up-arrow'],
            ['/purchasing/nc', 'Niezgodności', 'bi-exclamation-octagon'],
            ['/workshop/workstations', 'Stanowiska robocze i coboty', 'bi-robot'],
            ['/workshop/data-sheet', 'Tabela do wypełnienia', 'bi-file-earmark-spreadsheet'],
        ]],
        // Magazyn produktów gotowych : stan dziś (zamówienia, min., maks., do produkcji), zapas MTS (kartony, palety, wartość),
        // ruchy (przyjęcia, wydania, rozbieżności). Ta sama aplikacja co Karty produktów, otwarta na widoku magazynu.
        'warehouse' => ['label' => 'Magazyn', 'section' => 'Produkcja', 'icon' => 'bi-building', 'tabs' => [
            ['/workshop/stock', 'Stan magazynu', 'bi-bar-chart-line'],
            ['/workshop/stock/mts', 'Zapas MTS', 'bi-boxes'],
            ['/workshop/stock-movements', 'Ruchy magazynowe', 'bi-arrow-left-right'],
        ]],
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
        // Opakowania : BDO (wagi, dane, raporty) i PPWR (rejestr, kartony sprzedażowe, raporty) — jedna pozycja w railu.
        'packaging' => ['label' => 'Opakowania BDO + PPWR', 'section' => 'Produkt', 'icon' => 'bi-recycle', 'tabs' => [
            ['/audits/product-weights', 'Wagi produktów', 'bi-speedometer2'],
            ['/audits/packaging-data', 'Dane opakowań BDO', 'bi-clipboard-data'],
            ['/audits/packaging-reports', 'Raporty BDO', 'bi-file-earmark-bar-graph'],
            ['/ppwr/register', 'Rejestr PPWR', 'bi-journal-check'],
            ['/ppwr/sales-packaging', 'Kartony sprzedażowe PPWR', 'bi-box-seam'],
            ['/ppwr/reports', 'Raporty PPWR', 'bi-file-earmark-text'],
        ]],
        // Komunikacja : kto dostaje jakie dokumenty, historia wiadomości, stan wysyłek (błędy, kolejki, ponów jedną pozycję)
        'communication' => ['label' => 'Komunikacja', 'section' => 'Sprzedaż', 'icon' => 'bi-envelope', 'tabs' => [
            ['/communication/distribution', 'Dystrybucja dokumentów', 'bi-diagram-3'],
            ['/communication/messages', 'Historia wiadomości', 'bi-envelope-open'],
            ['/communication/outbox', 'Stan wysyłek', 'bi-send-exclamation'],
        ]],
        // Wzory wydruków PDF (przykłady w językach klientów) i szablony e-maili
        'templates' => ['label' => 'Wzory dokumentów i e-maili', 'section' => 'Ustawienia › Ustawienia', 'icon' => 'bi-file-earmark-richtext', 'tabs' => [
            ['/communication/document-templates', 'Dokumenty PDF', 'bi-file-earmark-pdf'],
            ['/communication/templates', 'Szablony e-mail', 'bi-envelope-paper'],
        ]],
        'returns' => ['label' => 'Zwroty', 'section' => 'Sprzedaż', 'icon' => 'bi-arrow-counterclockwise', 'tabs' => [
            ['/client-complaints/to-ship', 'Do wysłania', 'bi-box-arrow-up'],
            ['/client-complaints/pending-products', 'Oczekujące produkty', 'bi-hourglass-split'],
            ['/client-complaints/pending-shipment', 'Oczekujące zwroty (wysyłka)', 'bi-truck'],
            ['/client-complaints/picking-list', 'Listy kompletacyjne', 'bi-list-check'],
            ['/client-complaints/shipping-documents', 'Dokumenty zwrotów', 'bi-file-earmark-text'],
        ]],
        // Dzień wysyłki : jeden dzień logistyki w kolejności — załadunek (trasy, pojazdy, palety), dokumenty WZ / CMR / WDT
        // (Wyślij dzień), wydruki dla kierowców, powiadomienia klientów, potwierdzenia dostaw z tabletu kierowcy.
        'shipping_day' => ['label' => 'Dzień wysyłki', 'section' => 'Logistyka i pakowanie', 'icon' => 'bi-calendar-check', 'tabs' => [
            ['/logistics', 'Plan dnia', 'bi-stack', true],   // /logistics/week, /vehicles, /tours : osobne strony
            ['/shipping/wizard', 'Wyślij dzień', 'bi-send-check'],
            ['/logistics/documents', 'Dokumenty dla kierowców', 'bi-printer'],
            ['/logistics/notices', 'Powiadomienia klientów', 'bi-chat-dots'],
            ['/logistics/deliveries', 'Potwierdzenia dostaw', 'bi-clipboard2-check'],
        ]],
        // Ciężarówki : plan na 12 tygodni (prognoza palet, klienci na ciężarówkach, święta, urlopy kierowców) [200] i tydzień
        // z zamówień (ciężarówki dzień po dniu, wypełnienie).
        'trucks' => ['label' => 'Ciężarówki : plan i tydzień', 'section' => 'Logistyka i pakowanie', 'icon' => 'bi-calendar-range', 'tabs' => [
            ['/logistics/plan', 'Plan 12 tygodni', 'bi-calendar-range'],
            ['/logistics/week', 'Tydzień : ciężarówki', 'bi-calendar-week'],
        ]],
        // Jedna lista wysyłek z filtrem rodzaju dokumentu (dawniej Wysyłki, Dokumenty transportowe i Archiwum WZ / CMR / WDT / różnic)
        // oraz archiwum list kompletacyjnych ; pozycja « Wysyłka › Wysyłki i dokumenty » w railu.
        'shipments' => ['label' => 'Wysyłki i dokumenty', 'section' => 'Logistyka i pakowanie', 'icon' => 'bi-truck', 'tabs' => [
            ['/shipments', 'Wysyłki i dokumenty', 'bi-truck'],
            ['/shipments/differences', 'Różnice i problemy dostaw', 'bi-exclamation-diamond'],
            ['/archive/documents/picking-lists', 'Archiwum list kompletacyjnych', 'bi-list-check'],
        ]],
        // Finanse (27/10/2026, stanowisko Administracja i fakturowanie) : faktury i koszty (przypisania, zatwierdzenie, wzrosty cen)
        // i koszty usług miesiąca (faktury usług po kategoriach → kalkulator kosztów). Uprawnienia : pozycja « margins » (/finances).
        'finance' => ['label' => 'Finanse : faktury i koszty', 'section' => 'Zakupy', 'icon' => 'bi-receipt-cutoff', 'tabs' => [
            ['/finances/invoices', 'Faktury i koszty', 'bi-inboxes'],
            ['/finances/costs', 'Koszty usług miesiąca', 'bi-calendar2-range'],
        ]],
        // Aktywność w ERP i licznik stron : jedna pozycja w Administracji (N7).
        'usage' => ['label' => 'Aktywność i licznik stron', 'section' => 'Administracja', 'icon' => 'bi-activity', 'tabs' => [
            ['/organization/activity', 'Aktywność w ERP', 'bi-activity'],
            ['/settings/page-usage', 'Licznik stron', 'bi-bar-chart'],
        ]],
    ];

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
        $group['tabs'] = array_map(fn(array $t) => ['url' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'active' => $t[0] === $active], $group['tabs']);
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
            $links .= '<a class="at-page-tab' . ($t['active'] ? ' active" aria-current="page' : '') . '" href="' . $e($root . $t['url'] . $query) . '">'
                . '<i class="bi ' . $e($t['icon']) . '"></i> ' . $e($t['label']) . '</a>';
        }
        return '<nav class="at-page-tabs" aria-label="' . $e($group['label']) . '"><span class="at-page-tabs-title"><i class="bi ' . $e($group['icon']) . '"></i> '
            . $e($group['label']) . '</span>' . $links . '</nav>';
    }
}
