<?php

namespace App\Services\Client;

use App\Repositories\Client\ClientRepository;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Services\Utils\TCPDFService;

class ClientSalesSummaryService
{
    public const SORT_NAME_ASC  = 'name_asc';
    public const SORT_QTY_ASC   = 'qty_asc';
    public const SORT_QTY_DESC  = 'qty_desc';
    public const SORT_AVG_ASC   = 'avg_asc';
    public const SORT_AVG_DESC  = 'avg_desc';

    private const ALLOWED_SORTS = [
        self::SORT_NAME_ASC,
        self::SORT_QTY_ASC,
        self::SORT_QTY_DESC,
        self::SORT_AVG_ASC,
        self::SORT_AVG_DESC,
    ];

    public function __construct(
        private WDTProductRepository $wdtProductRepository,
        private ClientRepository     $clientRepository
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

        $products = $this->wdtProductRepository->getSoldDataByCustomerInPeriod($idCustomer, $dateFrom, $dateTo);

        $months = $this->countMonthsInPeriod($dateFrom, $dateTo);

        foreach ($products as &$p) {
            $p['avg_cartons_per_month'] = $months > 0
                ? round((int)$p['total_cartons'] / $months, 2)
                : (int)$p['total_cartons'];
        }
        unset($p);

        $products = $this->sort($products, $sort);

        $totalCartons = array_sum(array_column($products, 'total_cartons'));
        $avgTotal     = $months > 0 ? round($totalCartons / $months, 2) : (float)$totalCartons;

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
     * Oblicza liczbę pełnych (rozpoczętych) miesięcy między dwiema datami (min. 1).
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

        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Asima ERP');
        $pdf->SetAuthor('Asima ERP');
        $pdf->SetTitle('Podsumowanie sprzedaży');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // ── Nagłówek ───────────────────────────────────────────────────
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->Cell(0, 8, 'Podsumowanie sprzedaży WDT', 0, 1, 'C');

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $summary['customer']['company'], 0, 1, 'C');
        $pdf->Cell(0, 6, 'Okres: ' . $dateFrom . ' – ' . $dateTo . '  (' . $summary['period']['months_in_period'] . ' mies.)', 0, 1, 'C');
        $pdf->Ln(4);

        // ── Tabela ─────────────────────────────────────────────────────
        $colWidths = [10, 18, 97, 35, 30];

        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($colWidths[0], 7, '#',              'B', 0, 'C', true);
        $pdf->Cell($colWidths[1], 7, 'ID',             'B', 0, 'C', true);
        $pdf->Cell($colWidths[2], 7, 'Nazwa produktu', 'B', 0, 'L', true);
        $pdf->Cell($colWidths[3], 7, 'Kartony',        'B', 0, 'R', true);
        $pdf->Cell($colWidths[4], 7, 'Śr./mies.',      'B', 1, 'R', true);

        $pdf->SetFont('dejavusans', '', 9);
        $fill = false;
        foreach ($summary['products'] as $i => $p) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell($colWidths[0], 6, $i + 1,                             'B', 0, 'C', $fill);
            $pdf->Cell($colWidths[1], 6, (int)$p['id_product'],              'B', 0, 'C', $fill);
            $pdf->Cell($colWidths[2], 6, $p['name'],                         'B', 0, 'L', $fill);
            $pdf->Cell($colWidths[3], 6, (int)$p['total_cartons'],           'B', 0, 'R', $fill);
            $pdf->Cell($colWidths[4], 6, number_format((float)$p['avg_cartons_per_month'], 2, ',', ' '), 'B', 1, 'R', $fill);
            $fill = !$fill;
        }

        // ── Wiersz sumy i średniej ──────────────────────────────────────
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetFillColor(210, 210, 210);
        $pdf->Cell($colWidths[0] + $colWidths[1] + $colWidths[2], 7, 'Łącznie',                                                            'T', 0, 'R', true);
        $pdf->Cell($colWidths[3],                                  7, (int)$summary['total_cartons'],                                       'T', 0, 'R', true);
        $pdf->Cell($colWidths[4],                                  7, number_format((float)$summary['avg_cartons_per_month'], 2, ',', ' '), 'T', 1, 'R', true);

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
        fputcsv($handle, ['#', 'ID produktu', 'Nazwa produktu', 'Ilość kartonów', 'Śr. kartonów / mies.'], ';');

        // ── Dane ─────────────────────────────────────────────────────────
        foreach ($summary['products'] as $i => $p) {
            fputcsv($handle, [
                $i + 1,
                (int)$p['id_product'],
                $p['name'],
                (int)$p['total_cartons'],
                number_format((float)$p['avg_cartons_per_month'], 2, ',', ''),
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

