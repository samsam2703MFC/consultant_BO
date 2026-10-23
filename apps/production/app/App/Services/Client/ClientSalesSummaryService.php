<?php

namespace App\Services\Client;

use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Client\ClientRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

class ClientSalesSummaryService
{
    public const SORT_NAME_ASC  = 'name_asc';
    public const SORT_QTY_ASC   = 'qty_asc';
    public const SORT_QTY_DESC  = 'qty_desc';
    public const SORT_AVG_ASC   = 'avg_asc';
    public const SORT_AVG_DESC  = 'avg_desc';

    /** Etykiety PDF w języku klienta (polski zawsze, drugi język pod spodem). */
    private const PDF_LABELS = [
        'period' => ['pl' => 'Okres', 'fr' => 'Période', 'nl' => 'Periode', 'en' => 'Period'],
        'months' => ['pl' => 'Liczba miesięcy', 'fr' => 'Nombre de mois', 'nl' => 'Aantal maanden', 'en' => 'Number of months'],
        'avg_month' => ['pl' => 'Śr./mies.', 'fr' => 'Moy./mois', 'nl' => 'Gem./maand', 'en' => 'Avg./month'],
        'total' => ['pl' => 'Łącznie', 'fr' => 'Total', 'nl' => 'Totaal', 'en' => 'Total'],
    ];

    private const ALLOWED_SORTS = [
        self::SORT_NAME_ASC,
        self::SORT_QTY_ASC,
        self::SORT_QTY_DESC,
        self::SORT_AVG_ASC,
        self::SORT_AVG_DESC,
    ];

    public function __construct(
        private WdtSalesRepository $wdtSales,
        private ClientRepository   $clientRepository
    ) {
    }

    /**
     * Zwraca podsumowanie sprzedaży dla klienta w podanym okresie.
     *
     * @param int    $idCustomer
     * @param string $dateFrom  format YYYY-MM-DD
     * @param string $dateTo    format YYYY-MM-DD
     * @param string $sort      jedna z stałych SORT_*
     * @return array{customer: array, period: array, products: array, total_cartons: int}
     */
    public function getSummary(int $idCustomer, string $dateFrom, string $dateTo, string $sort = self::SORT_NAME_ASC): array
    {
        $customer = $this->clientRepository->first(['id' => $idCustomer]);

        // Ta sama sprzedaż co Analiza sprzedaży : WDT zastąpione pominięte, średnia = suma ÷ miesiące ze sprzedażą
        $products = array_map(fn($r) => [
            'id_product'            => (int)$r['product_id'],
            'name'                  => $r['product_name'],
            'total_cartons'         => (int)$r['cartons'],
            'months_with_sales'     => (int)$r['months_with_sales'],
            'avg_cartons_per_month' => round((int)$r['cartons'] / max(1, (int)$r['months_with_sales']), 2),
        ], $this->wdtSales->sales($dateFrom, $dateTo, ['customer_id' => $idCustomer], ['product']));

        $months = $this->countMonthsInPeriod($dateFrom, $dateTo);

        $products = $this->sort($products, $sort);

        $total        = $this->wdtSales->sales($dateFrom, $dateTo, ['customer_id' => $idCustomer])[0] ?? [];
        $totalCartons = (int)($total['cartons'] ?? 0);
        $avgTotal     = round($totalCartons / max(1, (int)($total['months_with_sales'] ?? 0)), 2);

        return [
            'customer' => [
                'id'         => (int)$customer['id'],
                'company'    => $customer['company']    ?? '',
                'tax_number' => $customer['tax_number'] ?? '',
            ],
            'period' => [
                'date_from'        => $dateFrom,
                'date_to'          => $dateTo,
                'months_in_period' => $months,
            ],
            'products'               => $products,
            'total_cartons'          => (int)$totalCartons,
            'avg_cartons_per_month'  => $avgTotal,
        ];
    }

    /**
     * Liczba miesięcy kalendarzowych okresu (do opisu nagłówka ; średnie liczone z miesięcy ze sprzedażą).
     */
    private function countMonthsInPeriod(string $dateFrom, string $dateTo): int
    {
        $from = new \DateTime($dateFrom);
        $to   = new \DateTime($dateTo);

        $years  = (int)$to->format('Y') - (int)$from->format('Y');
        $months = (int)$to->format('n') - (int)$from->format('n');

        $total = $years * 12 + $months + 1; // +1 — miesiąc startowy liczymy jako pełny

        return max(1, $total);
    }

    /**
     * Waliduje i normalizuje sortowanie — zwraca dopuszczalną wartość.
     */
    public function normalizeSort(?string $sort): string
    {
        if ($sort !== null && in_array($sort, self::ALLOWED_SORTS, true)) {
            return $sort;
        }
        return self::SORT_NAME_ASC;
    }

    /**
     * Sortuje listę produktów w PHP.
     */
    private function sort(array $products, string $sort): array
    {
        usort($products, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                self::SORT_QTY_ASC  => (int)$a['total_cartons'] <=> (int)$b['total_cartons'],
                self::SORT_QTY_DESC => (int)$b['total_cartons'] <=> (int)$a['total_cartons'],
                self::SORT_AVG_ASC  => $a['avg_cartons_per_month'] <=> $b['avg_cartons_per_month'],
                self::SORT_AVG_DESC => $b['avg_cartons_per_month'] <=> $a['avg_cartons_per_month'],
                default             => strcmp($a['name'], $b['name']),
            };
        });
        return $products;
    }

    /**
     * Generuje PDF i zwraca binarny string gotowy do wysyłki.
     */
    public function buildPdf(int $idCustomer, string $dateFrom, string $dateTo, string $sort = self::SORT_NAME_ASC): array
    {
        $summary = $this->getSummary($idCustomer, $dateFrom, $dateTo, $sort);

        $lang = $this->clientRepository->communicationLang($idCustomer);
        $sub = fn(string $key) => $lang === 'pl' ? '' : (self::PDF_LABELS[$key][$lang] ?? '');
        $pair = fn(string $key) => self::PDF_LABELS[$key]['pl'] . ($sub($key) !== '' ? ' / ' . $sub($key) : '');

        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Asima ERP');
        $pdf->SetAuthor('Asima ERP');
        $pdf->SetTitle('Podsumowanie sprzedaży');

        // ── Nagłówek ───────────────────────────────────────────────────
        $pdf->brandPage('Podsumowanie sprzedaży WDT', ['ref' => (string) $summary['customer']['company'], 'lang' => $lang]);
        $pdf->brandHeader('Podsumowanie sprzedaży WDT', [
            [$pair('period'), $dateFrom . ' – ' . $dateTo],
            [$pair('months'), (string) $summary['period']['months_in_period']],
        ], implode(' · ', array_filter([DocumentLabels::sub('doc_sales_summary', $lang), (string) $summary['customer']['company']])));

        // ── Tabela ─────────────────────────────────────────────────────
        $rows = [];
        foreach ($summary['products'] as $i => $p) {
            $rows[] = [$i + 1, (int)$p['id_product'], $p['name'], DocumentLabels::number((int)$p['total_cartons']),
                number_format((float)$p['avg_cartons_per_month'], 2, ',', ' ')];
        }
        // ── Wiersz sumy i średniej ──────────────────────────────────────
        $rows[] = ['_total' => ['', '', $pair('total'), DocumentLabels::number((int)$summary['total_cartons']),
            number_format((float)$summary['avg_cartons_per_month'], 2, ',', ' ')]];
        $pdf->brandTable([
            ['label' => '#', 'w' => 6, 'align' => 'C'],
            ['label' => 'ID', 'w' => 11, 'align' => 'C'],
            ['label' => DocumentLabels::get('product'), 'sub' => DocumentLabels::sub('product', $lang), 'w' => 55],
            ['label' => DocumentLabels::get('cartons'), 'sub' => DocumentLabels::sub('cartons', $lang), 'w' => 14, 'align' => 'R'],
            ['label' => self::PDF_LABELS['avg_month']['pl'], 'sub' => $sub('avg_month'), 'w' => 14, 'align' => 'R'],
        ], $rows, ['font' => 8.5, 'head_font' => 7.5]);

        $pdf->brandNote('Śr./mies. = kartony ÷ liczba miesięcy, w których produkt był kupowany (jak w Analizie sprzedaży). Dokumenty WDT zastąpione korektą nie są liczone.');

        $filename = 'sprzedaz_wdt_' . $summary['customer']['id'] . '_' . $dateFrom . '_' . $dateTo . '.pdf';

        return [
            'content'  => $pdf->Output('', 'S'),
            'filename' => $filename,
        ];
    }

    /**
     * Generuje plik CSV i zwraca binarny string gotowy do wysyłki.
     */
    public function buildCsv(int $idCustomer, string $dateFrom, string $dateTo, string $sort = self::SORT_NAME_ASC): array
    {
        $summary = $this->getSummary($idCustomer, $dateFrom, $dateTo, $sort);

        $handle = fopen('php://temp', 'r+');

        // BOM — poprawne wyświetlanie polskich znaków w Excelu
        fwrite($handle, "\xEF\xBB\xBF");

        // ── Metadane ────────────────────────────────────────────────────
        fputcsv($handle, ['Podsumowanie sprzedaży WDT'], ';');
        fputcsv($handle, [$summary['customer']['company']], ';');
        fputcsv($handle, ['Okres: ' . $dateFrom . ' – ' . $dateTo . '  (' . $summary['period']['months_in_period'] . ' mies.)'], ';');
        fputcsv($handle, [], ';');

        // ── Nagłówki kolumn ─────────────────────────────────────────────
        fputcsv($handle, ['#', 'ID produktu', 'Nazwa produktu', 'Ilość kartonów', 'Śr. kartonów / mies.', 'Miesięcy ze sprzedażą'], ';');

        // ── Dane ─────────────────────────────────────────────────────────
        foreach ($summary['products'] as $i => $p) {
            fputcsv($handle, [
                $i + 1,
                (int)$p['id_product'],
                $p['name'],
                (int)$p['total_cartons'],
                number_format((float)$p['avg_cartons_per_month'], 2, ',', ''),
                (int)$p['months_with_sales'],
            ], ';');
        }

        // ── Wiersz sumy ──────────────────────────────────────────────────
        fputcsv($handle, [
            '',
            '',
            'Łącznie',
            (int)$summary['total_cartons'],
            number_format((float)$summary['avg_cartons_per_month'], 2, ',', ''),
        ], ';');

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $filename = 'sprzedaz_wdt_' . $summary['customer']['id'] . '_' . $dateFrom . '_' . $dateTo . '.csv';

        return [
            'content'  => $content,
            'filename' => $filename,
        ];
    }
}

