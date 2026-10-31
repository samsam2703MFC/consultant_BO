<?php
declare(strict_types=1);

namespace App\Services\DevCard;

/**
 * Karta technologiczna w PDF [196] : ten sam układ co .docx (tytuł w rubinie, linia autora, pasek moreli, składniki
 * i kroki w dwóch kolumnach, zdjęcia etapów 4 w rzędzie, stopka) — do druku i podglądu bez Worda.
 */
final class CardPdf
{
    private const RUBY = [141, 29, 44];
    private const APRICOT = [242, 201, 160];
    private const GREY = [100, 100, 100];
    /** szerokość zdjęcia etapu (mm), jak w karcie .docx */
    private const PHOTO_W = 30.4;

    /** @param array<int, array{mime:string, image:string}> $images id klatki → obraz */
    public static function build(array $card, array $images): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetCreator('L’Atelier By ERP');
        $pdf->SetTitle($card['title']);
        $pdf->AddPage();
        $w = 186.0;
        $x0 = 12.0;

        $pdf->SetTextColor(...self::RUBY);
        $pdf->SetFont('dejavuserif', 'B', 19);
        $pdf->MultiCell($w, 0, $card['title'], 0, 'L', false, 1);
        $origin = trim($card['original_name'] . ($card['author'] !== '' ? ($card['original_name'] !== '' ? ' — ' : '') . 'wg ' . $card['author'] : ''));
        $y = $pdf->GetY() + 0.5;
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetXY($x0, $y);
        $pdf->Cell($w * 0.6, 4, $origin, 0, 0, 'L');
        $pdf->SetTextColor(...self::RUBY);
        $pdf->SetXY($x0 + $w * 0.6, $y);
        $pdf->Cell($w * 0.4, 4, 'L’Atelier By · Karta technologiczna', 0, 1, 'R');
        $pdf->SetDrawColor(...self::RUBY);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($x0, $pdf->GetY() + 0.8, $x0 + $w, $pdf->GetY() + 0.8);
        $pdf->SetY($pdf->GetY() + 3.5);

        // Pasek : wydajność, pieczenie, czas.
        $cw = $w / 3;
        $facts = [['WYDAJNOŚĆ', $card['yield']], ['PIECZENIE', $card['baking']], ['CZAS', $card['time']]];
        $pdf->SetFont('dejavusans', '', 7.5);
        $h = 0.0;
        foreach ($facts as [, $v]) $h = max($h, $pdf->getStringHeight($cw - 4, $v !== '' ? $v : '—'));
        $h += 6.5;
        $y = $pdf->GetY();
        $pdf->SetFillColor(...self::APRICOT);
        $pdf->Rect($x0, $y, $w, $h, 'F');
        foreach ($facts as $i => [$label, $value]) {
            $pdf->SetXY($x0 + $i * $cw + 2, $y + 1.5);
            $pdf->SetFont('dejavusans', 'B', 6);
            $pdf->SetTextColor(...self::RUBY);
            $pdf->Cell($cw - 4, 3, $label, 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetX($x0 + $i * $cw + 2);
            $pdf->MultiCell($cw - 4, 0, $value !== '' ? $value : '—', 0, 'L', false, 1);
        }
        $y += $h + 4;

        // Składniki | Sposób wykonania.
        $lw = $w * 0.38;
        $rw = $w - $lw - 6;
        // Tabele zamiast list HTML (TCPDF dodaje duże odstępy w <ul>/<ol>) : wypunktowanie i numeracja w kolumnie.
        $row = fn(string $mark, string $text, float $size) => '<tr><td width="7%" style="color:#8D1D2C;font-size:' . $size . 'pt;font-weight:bold">' . $mark . '</td><td width="93%" style="font-size:' . $size . 'pt">' . self::h($text) . '</td></tr>';
        $left = '<div style="font-family:dejavuserif;color:#8D1D2C;font-size:12pt;font-weight:bold">Składniki</div>';
        foreach ($card['ingredients'] as $g) {
            if ($g['group'] !== '') $left .= '<div style="font-size:7pt;font-weight:bold;line-height:1.6">' . self::h($g['group']) . '</div>';
            $left .= '<table cellpadding="0.6">' . implode('', array_map(fn($it) => $row('•', trim($it['qty'] . ' ' . $it['name']), 7.5), $g['items'])) . '</table>';
        }
        $right = '<div style="font-family:dejavuserif;color:#8D1D2C;font-size:12pt;font-weight:bold">Sposób wykonania</div><table cellpadding="0.9">';
        foreach ($card['steps'] as $i => $s) $right .= $row(($i + 1) . '.', $s, 8);
        $right .= '</table>';
        $pdf->SetTextColor(0, 0, 0);
        $page = $pdf->getPage();
        $pdf->writeHTMLCell($lw, 0, $x0, $y, $left, 0, 1);
        $endL = [$pdf->getPage(), $pdf->GetY()];
        $pdf->setPage($page);
        $pdf->writeHTMLCell($rw, 0, $x0 + $lw + 6, $y, $right, 0, 1);
        $endR = [$pdf->getPage(), $pdf->GetY()];
        $end = $endL[0] > $endR[0] || ($endL[0] === $endR[0] && $endL[1] > $endR[1]) ? $endL : $endR;
        $pdf->setPage($end[0]);
        $pdf->SetY($end[1] + 3);

        // Etapy – zdjęcia : 4 w rzędzie.
        $photos = array_values(array_filter($card['photos'], fn($p) => isset($images[$p['frame_id']])));
        if ($photos) {
            $pw = ($w - 3 * 4) / 4;
            if ($pdf->GetY() > 250) $pdf->AddPage();
            $pdf->SetFont('dejavuserif', 'B', 12);
            $pdf->SetTextColor(...self::RUBY);
            $pdf->Cell($w, 7, 'Etapy – zdjęcia', 0, 1, 'L');
            foreach (array_chunk($photos, 4, true) as $row) {
                $dims = [];
                $rowH = 0.0;
                foreach ($row as $k => $p) {
                    $info = @getimagesizefromstring($images[$p['frame_id']]['image']);
                    $ih = is_array($info) && $info[0] > 0 ? min(54.0, self::PHOTO_W * $info[1] / $info[0]) : self::PHOTO_W;
                    $dims[$k] = $ih;
                    $rowH = max($rowH, $ih);
                }
                if ($pdf->GetY() + $rowH + 8 > 285) $pdf->AddPage();
                $y = $pdf->GetY();
                foreach (array_values($row) as $i => $p) {
                    $k = array_keys($row)[$i];
                    $x = $x0 + $i * ($pw + 4);
                    $img = $images[$p['frame_id']];
                    $type = match ($img['mime']) { 'image/png' => 'PNG', 'image/webp' => '', default => 'JPG' };
                    if ($type === '') { $c = CardGenerator::shrink($img['mime'], $img['image']); $img = ['mime' => $c['mime'], 'image' => $c['bytes']]; $type = 'JPG'; }
                    $pdf->Image('@' . $img['image'], $x + ($pw - self::PHOTO_W) / 2, $y + ($rowH - $dims[$k]), self::PHOTO_W, $dims[$k], $type, '', '', true, 150, '', false, false, 0, 'CT');
                    $pdf->SetFont('dejavusans', '', 6.5);
                    $num = ($k + 1) . '. ';
                    $pdf->writeHTMLCell($pw, 0, $x, $y + $rowH + 0.8, '<span style="color:#8D1D2C;font-weight:bold">' . $num . '</span><span style="color:#222">' . self::h($p['caption']) . '</span>', 0, 0, false, true, 'C');
                }
                $pdf->SetY($y + $rowH + 7);
            }
        }
        $pdf->SetFont('dejavusans', '', 6.2);
        $pdf->SetTextColor(136, 136, 136);
        $pdf->MultiCell($w, 0, CardSchema::FOOTER, 0, 'L', false, 1);
        return $pdf->Output('', 'S');
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
