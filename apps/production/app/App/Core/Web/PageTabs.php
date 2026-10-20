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
        // Produkcja na jednej stronie : arkusz tygodnia (jedna linia na produkt), plan tygodnia (dni), listy dnia,
        // stany, partie i jakość, hala.
        'production' => ['label' => 'Produkcja', 'section' => 'Produkcja', 'icon' => 'bi-gear-wide-connected', 'tabs' => [
            ['/workshop/sheet', 'Arkusz tygodnia', 'bi-table'],
            ['/workshop/gantt', 'Plan tygodnia', 'bi-calendar-week'],
            ['/production/plan-wizard', 'Listy dnia (PDF)', 'bi-file-earmark-pdf'],
            ['/stock', 'Stany magazynowe', 'bi-building'],
            ['/workshop/traceability', 'Partie i identyfikowalność', 'bi-upc-scan'],
            ['/purchasing/nc', 'Niezgodności', 'bi-exclamation-octagon'],
            ['/workshop/execution', 'Hala : tablety i czas pracy', 'bi-tablet'],
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
        'returns' => ['label' => 'Zwroty', 'section' => 'Zamówienia', 'icon' => 'bi-arrow-counterclockwise', 'tabs' => [
            ['/client-complaints/pending-products', 'Oczekujące produkty', 'bi-hourglass-split'],
            ['/client-complaints/pending-shipment', 'Oczekujące zwroty (wysyłka)', 'bi-truck'],
            ['/client-complaints/picking-list', 'Listy kompletacyjne', 'bi-list-check'],
            ['/client-complaints/shipping-documents', 'Dokumenty zwrotów', 'bi-file-earmark-text'],
        ]],
        'archive' => ['label' => 'Archiwum dokumentów', 'section' => 'Zamówienia', 'icon' => 'bi-archive', 'tabs' => [
            ['/archive/documents/shipping/wz', 'WZ', 'bi-file-earmark'],
            ['/archive/documents/shipping/cmr', 'CMR', 'bi-truck'],
            ['/archive/documents/shipping/wdt', 'WDT', 'bi-receipt'],
            ['/archive/documents/shipping/difference', 'Dokumenty różnic', 'bi-plus-slash-minus'],
            ['/archive/documents/picking-lists', 'Listy kompletacyjne', 'bi-list-check'],
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
            $links .= '<a class="at-page-tab' . ($t['active'] ? ' active" aria-current="page' : '') . '" href="' . $e($root . $t['url']) . '">'
                . '<i class="bi ' . $e($t['icon']) . '"></i> ' . $e($t['label']) . '</a>';
        }
        return '<nav class="at-page-tabs" aria-label="' . $e($group['label']) . '"><span class="at-page-tabs-title"><i class="bi ' . $e($group['icon']) . '"></i> '
            . $e($group['label']) . '</span>' . $links . '</nav>';
    }
}
