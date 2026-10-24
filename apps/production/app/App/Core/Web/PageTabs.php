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
    /** grupa => [rail : etykieta, sekcja, ikona ; tabs : [ścieżka, etykieta, ikona]] */
    public const GROUPS = [
        // Produkcja na jednej stronie : arkusz tygodnia (jedna linia na produkt), plan tygodnia (dni), plan zespołów
        // (druk, tablet) z listami dnia (PDF), dostawy surowców (kto dostarcza co i kiedy), stany, partie, śledzenie produkcji (plan / zrobione / straty), jakość, stanowiska i coboty.
        // Tablet produkcji i jego zarządzanie : osobna pozycja w railu pod Produkcją.
        'production' => ['label' => 'Produkcja', 'section' => 'Produkcja', 'icon' => 'bi-gear-wide-connected', 'tabs' => [
            ['/workshop/sheet', 'Arkusz tygodnia', 'bi-table'],
            ['/workshop/gantt', 'Plan tygodnia', 'bi-calendar-week'],
            ['/workshop/team-sheet', 'Plan zespołów i listy dnia (PDF)', 'bi-printer'],
            ['/workshop/deliveries', 'Dostawy surowców', 'bi-truck'],
            ['/workshop/products#/levels', 'Stan magazynu', 'bi-building'],
            ['/workshop/traceability', 'Partie i identyfikowalność', 'bi-upc-scan'],
            ['/production/follow-up', 'Śledzenie produkcji', 'bi-graph-up-arrow'],
            ['/purchasing/nc', 'Niezgodności', 'bi-exclamation-octagon'],
            ['/workshop/workstations', 'Stanowiska i coboty', 'bi-robot'],
        ]],
        'bdo' => ['label' => 'Opakowania BDO', 'section' => 'Produkt', 'icon' => 'bi-box2', 'tabs' => [
            ['/audits/product-weights', 'Wagi produktów', 'bi-speedometer2'],
            ['/audits/packaging-data', 'Dane opakowań BDO', 'bi-clipboard-data'],
            ['/audits/packaging-reports', 'Raporty BDO', 'bi-file-earmark-bar-graph'],
        ]],
        'ppwr' => ['label' => 'PPWR', 'section' => 'Produkt', 'icon' => 'bi-recycle', 'tabs' => [
            ['/ppwr/register', 'Rejestr PPWR', 'bi-journal-check'],
            ['/ppwr/sales-packaging', 'Kartony sprzedażowe PPWR', 'bi-box-seam'],
            ['/ppwr/reports', 'Raporty PPWR', 'bi-file-earmark-text'],
        ]],
        // Wzory wydruków PDF (przykłady w językach klientów) i szablony e-maili
        'templates' => ['label' => 'Wzory dokumentów i e-maili', 'section' => 'Ustawienia › Klienci', 'icon' => 'bi-file-earmark-richtext', 'tabs' => [
            ['/communication/document-templates', 'Dokumenty PDF', 'bi-file-earmark-pdf'],
            ['/communication/templates', 'Szablony e-mail', 'bi-envelope-paper'],
        ]],
        'returns' => ['label' => 'Zwroty', 'section' => 'Zamówienia', 'icon' => 'bi-arrow-counterclockwise', 'tabs' => [
            ['/client-complaints/pending-products', 'Oczekujące produkty', 'bi-hourglass-split'],
            ['/client-complaints/pending-shipment', 'Oczekujące zwroty (wysyłka)', 'bi-truck'],
            ['/client-complaints/picking-list', 'Listy kompletacyjne', 'bi-list-check'],
            ['/client-complaints/shipping-documents', 'Dokumenty zwrotów', 'bi-file-earmark-text'],
        ]],
        // Jedna lista wysyłek z filtrem rodzaju dokumentu (dawniej Wysyłki, Dokumenty transportowe i Archiwum WZ / CMR / WDT / różnic)
        // oraz archiwum list kompletacyjnych ; pozycja « Wysyłka › Wysyłki i dokumenty » w railu.
        'shipments' => ['label' => 'Wysyłki i dokumenty', 'section' => 'Logistyka', 'icon' => 'bi-truck', 'tabs' => [
            ['/shipments', 'Wysyłki i dokumenty', 'bi-truck'],
            ['/archive/documents/picking-lists', 'Archiwum list kompletacyjnych', 'bi-list-check'],
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
            foreach ($group['tabs'] as [$url]) {
                if (($path === $url || str_starts_with($path, $url . '/')) && strlen($url) > $bestLen) {
                    $best = $key;
                    $bestLen = strlen($url);
                }
            }
        }
        if ($best === null) return null;
        $group = self::GROUPS[$best];
        $active = null;
        foreach ($group['tabs'] as [$url]) {
            if (($path === $url || str_starts_with($path, $url . '/')) && strlen($url) === $bestLen) $active = $url;
        }
        $group['key'] = $best;
        $group['tabs'] = array_map(fn(array $t) => ['url' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'active' => $t[0] === $active], $group['tabs']);
        return $group;
    }

    /** Pasek zakładek grupy bieżącej strony (pusty, gdy strona nie należy do grupy). */
    public static function html(string $root, ?string $path = null): string
    {
        $group = self::forPath($path ?? self::currentPath());
        if ($group === null) return '';
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
        $links = '';
        foreach ($group['tabs'] as $t) {
            if (!$t['active'] && !PageAccess::visible($t['url'])) continue;
            $links .= '<a class="at-page-tab' . ($t['active'] ? ' active" aria-current="page' : '') . '" href="' . $e($root . $t['url']) . '">'
                . '<i class="bi ' . $e($t['icon']) . '"></i> ' . $e($t['label']) . '</a>';
        }
        return '<nav class="at-page-tabs" aria-label="' . $e($group['label']) . '"><span class="at-page-tabs-title"><i class="bi ' . $e($group['icon']) . '"></i> '
            . $e($group['label']) . '</span>' . $links . '</nav>';
    }
}
