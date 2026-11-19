<?php
namespace App\Services\Logistics;

use App\Core\Exceptions\CustomException;
use App\Repositories\Logistics\DeliveryRepository;
use App\Services\Utils\TCPDFService;

/**
 * [310] Etykiety palet z kodem QR (A5 poziomo, jedna paleta na stronę) : tablet kierowcy skanuje QR przy rozładunku
 * i sprawdza, czy paleta należy do tego klienta. Treść QR : « TFBPAL:{numer palety} » (numer z palets_delivery).
 */
class PalletLabelService
{
    public const QR_PREFIX = 'TFBPAL:';
    private const BRAND = [141, 29, 44];

    public function __construct(private LogisticsService $logistics, private DeliveryRepository $deliveries) {}

    /** @return array{filename: string, file_string: string} */
    public function pdf(string $date, ?int $customerId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $pallets = $this->deliveries->labelPallets($date, $customerId);
        if (!$pallets) throw new CustomException('Brak palet w tym dniu wysyłki (paletyzacja niezakończona).', 'errors', 404);
        $plan = $this->logistics->day($date);
        $contents = $this->deliveries->palletContents(array_column($pallets, 'id_palet'));

        // Pojazd, trasa i numer postoju klienta ; liczba palet klienta (k / N).
        $truckOf = [];
        foreach ($plan['fleet'] as $v) foreach ($v['customers'] as $i => $cid) $truckOf[$cid] = [$v['label'], $i + 1, count($v['customers'])];
        $tourOf = [];
        foreach ($plan['tours'] as $t) foreach ($t['customers'] as $cid) $tourOf[$cid] = $t['name'];
        $byCustomer = [];
        foreach ($pallets as $p) $byCustomer[(int) $p['id_customer']][] = (int) $p['id_palet'];

        $pdf = new TCPDFService('L', 'mm', 'A5', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(false, 0);
        foreach ($pallets as $p) {
            $cid = (int) $p['id_customer'];
            $no = (int) $p['id_palet'];
            $c = $plan['by_id'][$cid] ?? null;
            $this->page($pdf, [
                'no' => $no, 'customer' => (string) ($c['name'] ?? $p['customer']), 'place' => trim(($c['town'] ?? '') . ' ' . ($c['country'] ?? '')),
                'date' => $date, 'truck' => $truckOf[$cid] ?? null, 'tour' => $tourOf[$cid] ?? null,
                'k' => array_search($no, $byCustomer[$cid], true) + 1, 'n' => count($byCustomer[$cid]), 'contents' => $contents[$no] ?? [],
            ]);
        }
        $name = 'ETYKIETY_PALET_' . $date . ($customerId !== null ? '_' . $customerId : '') . '.pdf';
        return ['filename' => $name, 'file_string' => $pdf->Output('', 'S')];
    }

    private function page(TCPDFService $pdf, array $l): void
    {
        $pdf->AddPage('L', 'A5');
        $w = $pdf->getPageWidth();
        $h = $pdf->getPageHeight();
        // Pasek marki u góry : dzień wysyłki.
        $pdf->SetFillColorArray(self::BRAND);
        $pdf->Rect(0, 0, $w, 14, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetXY(8, 3.5);
        $pdf->Cell($w - 16, 7, 'PALETA  ·  Dzień wysyłki ' . date('d.m.Y', strtotime($l['date'])), 0, 0, 'L');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY(8, 4.5);
        $pdf->Cell($w - 16, 6, 'Nr ' . $l['no'], 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // QR po lewej, numer palety pod nim.
        $qr = 70;
        $pdf->write2DBarcode(self::QR_PREFIX . $l['no'], 'QRCODE,M', 8, 20, $qr, $qr, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
        $pdf->SetFont('dejavusans', 'B', 22);
        $pdf->SetXY(8, 20 + $qr + 2);
        $pdf->Cell($qr, 10, (string) $l['no'], 0, 0, 'C');
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetXY(8, 20 + $qr + 12);
        $pdf->Cell($qr, 4, self::QR_PREFIX . $l['no'], 0, 0, 'C');

        // Po prawej : klient, miejsce, postój, paleta k / N, pojazd i trasa.
        $x = 8 + $qr + 8;
        $cw = $w - $x - 8;
        $pdf->SetFont('dejavusans', 'B', 20);
        $pdf->SetXY($x, 19);
        $pdf->MultiCell($cw, 9, $l['customer'], 0, 'L', false, 1, '', '', true, 0, false, true, 19, 'T', true);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetX($x);
        $pdf->Cell($cw, 6, $l['place'], 0, 1, 'L');

        $y = 47;
        $half = ($cw - 4) / 2;
        $this->box($pdf, $x, $y, $half, 'Paleta klienta', $l['k'] . ' / ' . $l['n']);
        $this->box($pdf, $x + $half + 4, $y, $half, 'Postój', $l['truck'] ? $l['truck'][1] . ' z ' . $l['truck'][2] : '—');

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY($x, $y + 28);
        $truck = $l['truck'] ? $l['truck'][0] : '—';
        $pdf->MultiCell($cw, 5, 'Pojazd : ' . $truck . ($l['tour'] ? '   ·   Trasa : ' . $l['tour'] : ''), 0, 'L');

        // Zawartość (najwyżej 5 pozycji, reszta zsumowana).
        $pdf->SetXY($x, $y + 36);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell($cw, 5, 'Zawartość', 'B', 1, 'L');
        $pdf->SetFont('dejavusans', '', 8.5);
        $items = $l['contents'];
        if (!$items) {
            $pdf->SetX($x);
            $pdf->Cell($cw, 5, '—', 0, 1, 'L');
        }
        $more = array_slice($items, 5);
        foreach (array_slice($items, 0, 5) as $it) {
            $pdf->SetX($x);
            $pdf->Cell($cw - 22, 5, $it['product'], 0, 0, 'L', false, '', 1);
            $pdf->Cell(22, 5, $it['cartons'] . ' krt.', 0, 1, 'R');
        }
        if ($more) {
            $pdf->SetX($x);
            $pdf->Cell($cw - 22, 5, '+ ' . count($more) . ' inne', 0, 0, 'L');
            $pdf->Cell(22, 5, array_sum(array_column($more, 'cartons')) . ' krt.', 0, 1, 'R');
        }
        if ($items) {
            $pdf->SetX($x);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->Cell($cw - 22, 5, 'Razem', 'T', 0, 'L');
            $pdf->Cell(22, 5, array_sum(array_column($items, 'cartons')) . ' krt.', 'T', 1, 'R');
        }
        $pdf->SetFont('dejavusans', '', 6.5);
        $pdf->SetTextColor(120, 113, 108);
        $pdf->SetXY(8, $h - 8);
        $pdf->Cell($w - 16, 4, 'Kierowca : zeskanuj QR tabletem przy rozładunku i zrób zdjęcie palety.', 0, 0, 'L');
    }

    /** Ramka z etykietą i dużą wartością (paleta k / N, postój). */
    private function box(TCPDFService $pdf, float $x, float $y, float $w, string $label, string $value): void
    {
        $pdf->SetDrawColor(220, 214, 209);
        $pdf->RoundedRect($x, $y, $w, 24, 2.5, '1111', 'D');
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(120, 113, 108);
        $pdf->SetXY($x + 3, $y + 2.5);
        $pdf->Cell($w - 6, 4, $label, 0, 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 24);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x + 3, $y + 8.5);
        $pdf->Cell($w - 6, 12, $value, 0, 0, 'L');
    }
}
