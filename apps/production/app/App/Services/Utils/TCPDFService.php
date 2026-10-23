<?php

namespace App\Services\Utils;

use TCPDF;

/**
 * Wszystkie wydruki ERP w jednym stylu : logo SL Food z dopiskiem « producent zatwierdzony przez L'Atelier By »
 * (logo L'Atelier), tytuł w kolorze marki, bloki stron, tabele z nagłówkiem w kolorze marki, stopka z danymi firmy
 * i numerem strony na każdej stronie.
 *
 * Dokument zaczyna brandPage() ; kilka dokumentów w jednym pliku (WZ, CMR, WDT, różnice) ma osobną numerację stron
 * (grupy stron). Strony bez brandPage() (formularz CMR) nie dostają nagłówka ani stopki.
 */
class TCPDFService extends TCPDF
{
    public const BRAND = [141, 29, 44];        // Ruby Red L'Atelier (--at-primary)
    public const BRAND_SOFT = [248, 236, 238];
    public const INK = [33, 37, 41];
    public const MUTED = [108, 117, 125];
    public const LINE = [222, 226, 230];
    public const ZEBRA = [248, 249, 250];
    public const PRODUCER_LOGO = __DIR__ . '/../../../public/resources/images/logo.jpg';             // SL Food
    public const NETWORK_LOGO = __DIR__ . '/../../../public/assets/images/atelier-logo-print.jpg';   // L'Atelier
    public const NETWORK_NAME = "L'Atelier By";
    /** Tabele : krój zwężony (więcej kolumn bez łamania liczb). */
    public const TABLE_FONT = 'dejavusanscondensed';
    public const DEFAULT_COMPANY = "SL Food · producent zatwierdzony przez L'Atelier By";

    /** Dokument w toku : title, ref, company, lang (null = strony bez stylu, np. CMR). */
    private ?array $brandActive = null;
    /** numer strony => dokument ; stopka strony jest rysowana dopiero przy otwarciu następnej. */
    private array $pageBrand = [];

    // Nagłówek i stopka są rysowane według strony (pageBrand). Wyłączenie ich przez kolejny dokument w tym samym
    // pliku nie może zabrać stopki ostatniej stronie poprzedniego dokumentu.
    public function setPrintHeader($val = true)
    {
        if (!$val) {
            $this->brandActive = null;
            $this->startPageGroup();
        }
        $this->print_header = true;
    }

    public function setPrintFooter($val = true)
    {
        $this->print_footer = true;
    }

    /**
     * Nowy dokument : marginesy, grupa stron, pierwsza strona.
     * $opts : orientation (P|L), ref (numer dokumentu), company (linia stopki), lang (drugi język etykiet).
     */
    public function brandPage(string $title, array $opts = []): void
    {
        $this->brandActive = null;
        $this->SetMargins(12, 18, 12);
        $this->SetAutoPageBreak(true, 16);
        $this->setCellPaddings(0, 0, 0, 0);
        $this->startPageGroup();
        $this->AddPage($opts['orientation'] ?? 'P');
        $this->brandActive = [
            'title' => $title,
            'ref' => (string) ($opts['ref'] ?? ''),
            'company' => (string) (($opts['company'] ?? '') !== '' ? $opts['company'] : self::DEFAULT_COMPANY),
            'lang' => DocumentLabels::lang($opts['lang'] ?? 'pl'),
        ];
        $this->pageBrand[$this->getPage()] = $this->brandActive;
        $this->SetTextColorArray(self::INK);
        $this->SetY(9);
    }

    public function brandLang(): string
    {
        return $this->brandActive['lang'] ?? 'pl';
    }

    public function contentWidth(): float
    {
        return $this->getPageWidth() - $this->lMargin - $this->rMargin;
    }

    /**
     * Nagłówek pierwszej strony : logo SL Food + « producent zatwierdzony przez » + logo L'Atelier po lewej,
     * tytuł, podtytuł i pary [etykieta, wartość] (numer, data…) po prawej, linia w kolorze marki.
     */
    public function brandHeader(string $title, array $meta = [], string $subtitle = ''): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $top = 9;
        $bottom = $this->drawLockup($lm, $top, 17);

        $rightW = $w - 82;
        $x = $lm + 82;
        $this->SetTextColorArray(self::BRAND);
        $this->SetFont('dejavusans', 'B', 15);
        $this->SetXY($x, $top);
        $this->MultiCell($rightW, 7, $title, 0, 'R', false, 1, $x, $top, true, 0, false, true, 0, 'T', true);
        $y = $this->GetY();
        if ($subtitle !== '') {
            $this->SetFont('dejavusans', '', 9);
            $this->SetTextColorArray(self::MUTED);
            $this->MultiCell($rightW, 4.5, $subtitle, 0, 'R', false, 1, $x, $y);
            $y = $this->GetY();
        }
        $y += 0.8;
        foreach ($meta as $pair) {
            [$label, $value] = $pair;
            $this->SetFont('dejavusans', '', 7.5);
            $this->SetTextColorArray(self::MUTED);
            $this->SetXY($lm + $w - 92, $y);
            $this->Cell(44, 4.6, (string) $label, 0, 0, 'R');
            $this->SetFont('dejavusans', 'B', 9);
            $this->SetTextColorArray(self::INK);
            $this->Cell(48, 4.6, (string) $value, 0, 1, 'R', false, '', 1);
            $y = $this->GetY();
        }
        $y = max($y, $bottom) + 2.5;
        $this->SetDrawColorArray(self::BRAND);
        $this->SetLineWidth(0.6);
        $this->Line($lm, $y, $lm + $w, $y);
        $this->SetLineWidth(0.2);
        $this->SetTextColorArray(self::INK);
        $this->SetY($y + 4);
    }

    /** Logo producenta, kreska, « producent zatwierdzony przez » i logo sieci ; zwraca dolną krawędź. */
    private function drawLockup(float $x, float $y, float $height): float
    {
        $logoW = 0;
        if (is_file(self::PRODUCER_LOGO)) {
            $this->Image(self::PRODUCER_LOGO, $x, $y, 0, $height, 'JPG');
            $logoW = $this->getImageRBX() - $x;
        }
        $dx = $x + $logoW + 3;
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($dx, $y + 1.5, $dx, $y + $height - 1.5);
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColorArray(self::MUTED);
        $this->SetXY($dx + 3, $y + $height / 2 - 6);
        $this->Cell(60, 3.5, DocumentLabels::get('producer', $this->brandLang()), 0, 1, 'L');
        if (is_file(self::NETWORK_LOGO)) {
            $this->Image(self::NETWORK_LOGO, $dx + 3, $y + $height / 2 - 1.8, 46, 0, 'JPG');
        }
        $this->SetTextColorArray(self::INK);
        return $y + $height;
    }

    /**
     * Dwa bloki stron (sprzedawca / nabywca, wydający / odbiorca) tej samej wysokości.
     * Blok : ['label' => …, 'name' => …, 'lines' => [linia, …]] ; drugi blok opcjonalny.
     */
    public function brandParties(array $left, ?array $right = null): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $gap = 6;
        $bw = $right === null ? $w : ($w - $gap) / 2;
        $y = $this->GetY();
        $h = $this->partyHeight($left, $bw);
        if ($right !== null) $h = max($h, $this->partyHeight($right, $bw));
        if ($y + $h > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage();
            $y = $this->GetY();
        }
        $this->drawParty($left, $lm, $y, $bw, $h);
        if ($right !== null) $this->drawParty($right, $lm + $bw + $gap, $y, $bw, $h);
        $this->SetY($y + $h + 5);
    }

    private function partyHeight(array $p, float $w): float
    {
        $inner = $w - 8;
        $this->SetFont('dejavusans', 'B', 9.5);
        $h = 9 + $this->getStringHeight($inner, (string) ($p['name'] ?? ''), false, true, ['T' => 0, 'R' => 0, 'B' => 0, 'L' => 0]);
        $this->SetFont('dejavusans', '', 8);
        foreach (array_filter($p['lines'] ?? [], fn($l) => trim((string) $l) !== '') as $line) {
            $h += $this->getStringHeight($inner, (string) $line, false, true, ['T' => 0, 'R' => 0, 'B' => 0, 'L' => 0]);
        }
        return $h + 3;
    }

    private function drawParty(array $p, float $x, float $y, float $w, float $h): void
    {
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.25);
        $this->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'D');
        $this->SetFillColorArray(self::BRAND);
        $this->Rect($x, $y + 1.5, 0.9, $h - 3, 'F');
        $this->SetFont('dejavusans', 'B', 6.5);
        $this->SetTextColorArray(self::BRAND);
        $this->SetXY($x + 4, $y + 2.5);
        $this->Cell($w - 8, 3.5, mb_strtoupper((string) ($p['label'] ?? '')), 0, 1, 'L', false, '', 1);
        $this->SetFont('dejavusans', 'B', 9.5);
        $this->SetTextColorArray(self::INK);
        $this->MultiCell($w - 8, 0, (string) ($p['name'] ?? ''), 0, 'L', false, 1, $x + 4, $y + 7);
        $this->SetFont('dejavusans', '', 8);
        foreach (array_filter($p['lines'] ?? [], fn($l) => trim((string) $l) !== '') as $line) {
            $this->MultiCell($w - 8, 0, (string) $line, 0, 'L', false, 1, $x + 4);
        }
    }

    /**
     * Tabela : nagłówek w kolorze marki (pod etykietą drugi język), wiersze na przemian jasne, nagłówek powtarzany
     * na kolejnych stronach, wysokość wiersza według najdłuższego tekstu.
     * $columns : [['label' => 'Ilość', 'sub' => 'Quantité', 'w' => 10 (waga szerokości), 'align' => 'R'], …]
     * $rows : wartości w kolejności kolumn ; ['_group' => tekst] = wiersz grupy (np. paleta, kategoria),
     *         ['_total' => [wartości]] = wiersz sumy.
     * $opts : font (8), head_font (7), min_h (5.5).
     */
    /**
     * Szerokości kolumn bez łamania słów : każda kolumna ma co najmniej szerokość najdłuższego słowa nagłówka
     * i (dla kolumn liczbowych, wyrównanych do prawej) całej wartości ; brak bierze się z kolumn z zapasem
     * (najczęściej opis). Gdy minimum wszystkich kolumn przekracza stronę — zostają proporcje (zawijanie).
     *
     * @param array<int,float> $ws
     * @return array<int,float>
     */
    private function fitColumnWidths(array $columns, array $rows, array $ws, float $avail, float $font, float $headFont, float $padX): array
    {
        $min = [];
        foreach ($columns as $k => $c) {
            $m = 0.0;
            foreach (preg_split('/\s+/u', trim((string) $c['label'])) ?: [] as $word) $m = max($m, $this->GetStringWidth($word, self::TABLE_FONT, 'B', $headFont));
            foreach (preg_split('/\s+/u', trim((string) ($c['sub'] ?? ''))) ?: [] as $word) $m = max($m, $this->GetStringWidth($word, self::TABLE_FONT, '', $headFont - 0.8));
            $numeric = ($c['align'] ?? 'L') === 'R';
            foreach ($rows as $row) {
                if (!is_array($row) || array_key_exists('_group', $row)) continue;
                $total = array_key_exists('_total', $row);
                $cell = (string) (array_values($total ? $row['_total'] : $row)[$k] ?? '');
                if ($cell === '') continue;
                $parts = $numeric ? [$cell] : (preg_split('/\s+/u', $cell) ?: []);
                foreach ($parts as $part) $m = max($m, $this->GetStringWidth($part, self::TABLE_FONT, $total ? 'B' : '', $font));
            }
            $min[$k] = $m + $padX + 0.4;
        }
        if (array_sum($min) > $avail) return $ws;
        for ($pass = 0; $pass < 5; $pass++) {
            $deficit = 0.0;
            foreach ($ws as $k => $w) if ($w < $min[$k]) { $deficit += $min[$k] - $w; $ws[$k] = $min[$k]; }
            if ($deficit <= 0.001) break;
            $slack = [];
            foreach ($ws as $k => $w) if ($w > $min[$k]) $slack[$k] = $w - $min[$k];
            $total = array_sum($slack);
            if ($total <= 0) break;
            foreach ($slack as $k => $sl) $ws[$k] -= $deficit * $sl / $total;
        }
        return $ws;
    }

    public function brandTable(array $columns, array $rows, array $opts = []): void
    {
        $lm = $this->lMargin;
        $avail = $this->contentWidth();
        $weights = array_map(fn($c) => (float) ($c['w'] ?? 1), $columns);
        $sum = array_sum($weights) ?: 1;
        $ws = array_map(fn($wk) => $avail * $wk / $sum, $weights);
        $font = $opts['font'] ?? 8;
        $headFont = $opts['head_font'] ?? 7;
        $minH = $opts['min_h'] ?? 5.5;
        $pad = ['L' => 1.3, 'T' => 0.9, 'R' => 1.3, 'B' => 0.9];
        $ws = $this->fitColumnWidths($columns, $rows, $ws, $avail, $font, $headFont, $pad['L'] + $pad['R']);

        $autoBreak = $this->getAutoPageBreak();
        $breakMargin = $this->getBreakMargin();
        $this->SetAutoPageBreak(false, $breakMargin);
        $prevPadding = $this->getCellPaddings();
        $this->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
        $limit = fn() => $this->getPageHeight() - $breakMargin;

        $head = function () use ($columns, $ws, $headFont, $lm, $avail, $pad) {
            $hasSub = (bool) array_filter($columns, fn($c) => ($c['sub'] ?? '') !== '');
            $this->SetFont(self::TABLE_FONT, 'B', $headFont);
            $h1 = 0;
            foreach ($columns as $k => $c) $h1 = max($h1, $this->getStringHeight($ws[$k], (string) $c['label'], false, true, $pad));
            $h2 = 0;
            if ($hasSub) {
                $this->SetFont(self::TABLE_FONT, '', $headFont - 0.8);
                foreach ($columns as $k => $c) $h2 = max($h2, $this->getStringHeight($ws[$k], (string) ($c['sub'] ?? ''), false, true, ['L' => $pad['L'], 'T' => 0, 'R' => $pad['R'], 'B' => $pad['B']]));
            }
            $y = $this->GetY();
            $this->SetFillColorArray(self::BRAND);
            $this->Rect($lm, $y, $avail, $h1 + $h2, 'F');
            $x = $lm;
            foreach ($columns as $k => $c) {
                $align = $c['head_align'] ?? ($c['align'] ?? 'L');
                $this->SetFont(self::TABLE_FONT, 'B', $headFont);
                $this->SetTextColor(255, 255, 255);
                $this->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $hasSub ? 0 : $pad['B']);
                $this->MultiCell($ws[$k], $h1, (string) $c['label'], 0, $align, false, 0, $x, $y, true, 0, false, true, $h1, $hasSub ? 'B' : 'M');
                if ($hasSub) {
                    $this->SetFont(self::TABLE_FONT, '', $headFont - 0.8);
                    $this->SetTextColor(244, 205, 211);
                    $this->setCellPaddings($pad['L'], 0, $pad['R'], $pad['B']);
                    $this->MultiCell($ws[$k], $h2, (string) ($c['sub'] ?? ''), 0, $align, false, 0, $x, $y + $h1, true, 0, false, true, $h2, 'T');
                }
                $x += $ws[$k];
            }
            $this->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
            $this->SetTextColorArray(self::INK);
            $this->SetY($y + $h1 + $h2);
        };

        $head();
        $i = 0;
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists('_group', $row)) {
                $this->SetFont(self::TABLE_FONT, 'B', $font);
                $h = max($minH, $this->getStringHeight($avail, (string) $row['_group'], false, true, $pad));
                if ($this->GetY() + $h + $minH > $limit()) { $this->AddPage(); $head(); $this->SetFont(self::TABLE_FONT, 'B', $font); }
                $y = $this->GetY();
                $this->SetFillColorArray(self::BRAND_SOFT);
                $this->SetTextColorArray(self::BRAND);
                $this->MultiCell($avail, $h, (string) $row['_group'], 0, 'L', true, 1, $lm, $y, true, 0, false, true, $h, 'M');
                $this->SetTextColorArray(self::INK);
                $this->SetY($y + $h);
                $i = 0;
                continue;
            }
            $total = is_array($row) && array_key_exists('_total', $row);
            $cells = array_values($total ? $row['_total'] : $row);
            $this->SetFont(self::TABLE_FONT, $total ? 'B' : '', $font);
            $h = $minH;
            foreach ($ws as $k => $wk) $h = max($h, $this->getStringHeight($wk, (string) ($cells[$k] ?? ''), false, true, $pad));
            if ($this->GetY() + $h > $limit()) { $this->AddPage(); $head(); $this->SetFont(self::TABLE_FONT, $total ? 'B' : '', $font); }
            $y = $this->GetY();
            $fill = $total || $i % 2 === 1;
            $this->SetFillColorArray($total ? self::BRAND_SOFT : self::ZEBRA);
            $x = $lm;
            foreach ($ws as $k => $wk) {
                $this->MultiCell($wk, $h, (string) ($cells[$k] ?? ''), 0, $columns[$k]['align'] ?? 'L', $fill, 0, $x, $y, true, 0, false, true, $h, 'M');
                $x += $wk;
            }
            if ($total) {
                $this->SetDrawColorArray(self::BRAND);
                $this->SetLineWidth(0.4);
                $this->Line($lm, $y, $lm + $avail, $y);
            }
            $this->SetDrawColorArray(self::LINE);
            $this->SetLineWidth(0.2);
            $this->Line($lm, $y + $h, $lm + $avail, $y + $h);
            $this->SetY($y + $h);
            if (!$total) $i++;
        }

        $this->setCellPaddings($prevPadding['L'], $prevPadding['T'], $prevPadding['R'], $prevPadding['B']);
        $this->SetAutoPageBreak($autoBreak, $breakMargin);
        $this->SetY($this->GetY() + 3);
    }

    /**
     * Pod tabelą : informacje [etykieta, wartość] po lewej (daty, waga, płatność) i sumy po prawej
     * (ostatnia suma wyróżniona). Każda strona opcjonalna.
     */
    public function brandSummary(array $info, array $totals = []): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $totW = $totals ? 78 : 0;
        $infoW = $w - $totW - ($totals ? 10 : 0);
        $need = max($this->infoHeight($info, $infoW), $this->totalsHeight($totals)) + 2;
        if ($this->GetY() + $need > $this->getPageHeight() - $this->getBreakMargin()) $this->AddPage();
        $y0 = $this->GetY();
        $y = $this->drawInfo($info, $lm, $y0, $infoW);
        $ty = $totals ? $this->drawTotals($totals, $lm + $w - $totW, $y0, $totW) : $y0;
        $this->SetTextColorArray(self::INK);
        $this->SetY(max($y, $ty) + 4);
    }

    /**
     * Zamknięcie faktury : płatność po lewej i sumy po prawej zawsze na dole ostatniej strony (dolna krawędź sum
     * w tym samym miejscu na każdej fakturze — dla OCR), uwaga prawna pod spodem, nad blokiem opcjonalnie $above
     * (np. kod QR KSeF : callable($pdf, $y) w strefie o wysokości $aboveHeight). Nowa strona, gdy tabela zajmuje to miejsce.
     */
    public function brandClosing(array $info, array $totals, string $note = '', ?callable $above = null, float $aboveHeight = 0.0): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $totW = 78;
        $infoW = $w - $totW - 10;
        $noteH = 9.0;
        $infoH = $this->infoHeight($info, $infoW);
        $totH = $this->totalsHeight($totals);
        $blockH = max($infoH, $totH);
        $bottom = $this->getPageHeight() - $this->getBreakMargin() - $noteH;
        $top = $bottom - $blockH - ($above ? $aboveHeight : 0.0) - 3;
        if ($this->GetY() > $top) {
            $this->AddPage();
        }
        if ($above) $above($this, $top);
        $blockTop = $bottom - $blockH;
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.2);
        $this->Line($lm, $blockTop - 2, $lm + $w, $blockTop - 2);
        $this->drawInfo($info, $lm, $blockTop, $infoW);
        $this->drawTotals($totals, $lm + $w - $totW, $bottom - $totH, $totW);
        if ($note !== '') {
            $this->SetFont('dejavusans', 'I', 7);
            $this->SetTextColorArray(self::MUTED);
            $this->MultiCell($w, $noteH - 2, $note, 0, 'C', false, 1, $lm, $bottom + 1.5, true, 0, false, true, $noteH - 2, 'T', true);
        }
        $this->SetTextColorArray(self::INK);
    }

    private function infoHeight(array $info, float $infoW): float
    {
        $lw = min(52, $infoW * 0.42);
        $h = 0.0;
        foreach ($info as [$label, $value]) {
            $this->SetFont('dejavusans', '', 7.5);
            $hl = $this->getStringHeight($lw - 2, (string) $label);
            $this->SetFont('dejavusans', '', 8.5);
            $hv = $this->getStringHeight($infoW - $lw, (string) $value);
            $h += max($hl, $hv, 4.2) + 0.9;
        }
        return $h;
    }

    private function drawInfo(array $info, float $x, float $y, float $infoW): float
    {
        $lw = min(52, $infoW * 0.42);
        foreach ($info as [$label, $value]) {
            $this->SetFont('dejavusans', '', 7.5);
            $hl = $this->getStringHeight($lw - 2, (string) $label);
            $this->SetTextColorArray(self::MUTED);
            $this->MultiCell($lw - 2, $hl, (string) $label, 0, 'L', false, 0, $x, $y);
            $this->SetFont('dejavusans', '', 8.5);
            $hv = $this->getStringHeight($infoW - $lw, (string) $value);
            $this->SetTextColorArray(self::INK);
            $this->MultiCell($infoW - $lw, $hv, (string) $value, 0, 'L', false, 0, $x + $lw, $y);
            $y += max($hl, $hv, 4.2) + 0.9;
        }
        return $y;
    }

    private function totalsHeight(array $totals): float
    {
        return $totals ? (count($totals) - 1) * 6 + 8.3 : 0.0;
    }

    /** Sumy : wiersze z linią, ostatni (razem) wyróżniony w kolorze marki ; zwraca dolną krawędź. */
    private function drawTotals(array $totals, float $tx, float $ty, float $totW): float
    {
        $last = count($totals) - 1;
        foreach (array_values($totals) as $k => [$label, $value]) {
            $strong = $k === $last;
            $h = $strong ? 7.5 : 6;
            if ($strong) {
                $this->SetFillColorArray(self::BRAND);
                $this->RoundedRect($tx, $ty + 0.8, $totW, $h, 1.2, '1111', 'F');
                $this->SetTextColor(255, 255, 255);
                $ty += 0.8;
            } else {
                $this->SetTextColorArray(self::INK);
                $this->SetDrawColorArray(self::LINE);
                $this->Line($tx, $ty + $h, $tx + $totW, $ty + $h);
            }
            $this->SetFont('dejavusans', $strong ? 'B' : '', $strong ? 9.5 : 8.5);
            $this->SetXY($tx + 2.5, $ty);
            $this->Cell($totW * 0.55 - 2.5, $h, (string) $label, 0, 0, 'L', false, '', 1);
            $this->Cell($totW * 0.45 - 2.5, $h, (string) $value, 0, 0, 'R', false, '', 1);
            $ty += $h;
        }
        $this->SetTextColorArray(self::INK);
        return $ty;
    }

    /** Pola podpisów (np. Wydał / Odebrał) obok siebie. */
    public function brandSignatures(array $labels): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $gap = 10;
        $n = max(1, count($labels));
        $bw = ($w - $gap * ($n - 1)) / $n;
        $h = 22;
        if ($this->GetY() + $h + 4 > $this->getPageHeight() - $this->getBreakMargin()) $this->AddPage();
        $y = $this->GetY() + 4;
        foreach (array_values($labels) as $k => $label) {
            $x = $lm + $k * ($bw + $gap);
            $this->SetFont('dejavusans', 'B', 7);
            $this->SetTextColorArray(self::MUTED);
            $this->SetXY($x, $y);
            $this->Cell($bw, 4, mb_strtoupper((string) $label), 0, 0, 'L', false, '', 1);
            $this->SetDrawColorArray(self::MUTED);
            $this->SetLineWidth(0.2);
            $this->Line($x, $y + $h - 5, $x + $bw, $y + $h - 5, ['dash' => '1,1']);
            $this->SetFont('dejavusans', '', 6.5);
            $this->SetXY($x, $y + $h - 4.5);
            $this->Cell($bw, 3.5, DocumentLabels::pair('signature', $this->brandLang()), 0, 0, 'L', false, '', 1);
        }
        $this->SetLineStyle(['dash' => 0]);
        $this->SetTextColorArray(self::INK);
        $this->SetY($y + $h + 2);
    }

    /** Tytuł sekcji w kolorze marki z cienką linią. */
    public function brandSection(string $title, string $sub = ''): void
    {
        if ($this->GetY() + 16 > $this->getPageHeight() - $this->getBreakMargin()) $this->AddPage();
        $lm = $this->lMargin;
        $this->SetFont('dejavusans', 'B', 10.5);
        $this->SetTextColorArray(self::BRAND);
        $this->MultiCell($this->contentWidth(), 0, $title, 0, 'L', false, 1, $lm);
        if ($sub !== '') {
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColorArray(self::MUTED);
            $this->MultiCell($this->contentWidth(), 0, $sub, 0, 'L', false, 1, $lm);
        }
        $y = $this->GetY() + 1;
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.2);
        $this->Line($lm, $y, $lm + $this->contentWidth(), $y);
        $this->SetTextColorArray(self::INK);
        $this->SetY($y + 2.5);
    }

    /** Akapit (list, uwagi) ; $html = true dla pogrubień. */
    public function brandText(string $text, array $opts = []): void
    {
        $this->SetFont('dejavusans', $opts['style'] ?? '', $opts['size'] ?? 9.5);
        $this->SetTextColorArray($opts['color'] ?? self::INK);
        $this->setCellHeightRatio($opts['ratio'] ?? 1.35);
        if (!empty($opts['html'])) {
            $this->writeHTMLCell($this->contentWidth(), 0, $this->lMargin, $this->GetY(), $text, 0, 1, false, true, $opts['align'] ?? 'L', true);
        } else {
            $this->MultiCell($this->contentWidth(), 0, $text, 0, $opts['align'] ?? 'L', false, 1, $this->lMargin);
        }
        $this->setCellHeightRatio(1.25);
        $this->SetTextColorArray(self::INK);
        $this->SetY($this->GetY() + ($opts['after'] ?? 2.5));
    }

    /** Mała uwaga (przypis, podstawa prawna). */
    public function brandNote(string $text, string $align = 'L'): void
    {
        $this->brandText($text, ['size' => 7.5, 'style' => 'I', 'color' => self::MUTED, 'align' => $align, 'after' => 1.5]);
    }

    /** Ramka wyróżniona (np. kwota do zapłaty w liście) w kolorze marki. */
    public function brandCallout(string $text): void
    {
        $lm = $this->lMargin;
        $w = $this->contentWidth();
        $this->SetFont('dejavusans', 'B', 9.5);
        $h = $this->getStringHeight($w - 10, $text) + 6;
        if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) $this->AddPage();
        $y = $this->GetY();
        $this->SetFillColorArray(self::BRAND_SOFT);
        $this->RoundedRect($lm, $y, $w, $h, 1.5, '1111', 'F');
        $this->SetFillColorArray(self::BRAND);
        $this->Rect($lm, $y, 1.2, $h, 'F');
        $this->SetTextColorArray(self::INK);
        $this->MultiCell($w - 10, 0, $text, 0, 'L', false, 1, $lm + 5, $y + 3);
        $this->SetY($y + $h + 4);
    }

    public function Header()
    {
        $doc = $this->brandActive;
        if ($doc === null || isset($this->pageBrand[$this->getPage()])) return;
        // kolejna strona dokumentu : mały nagłówek
        $this->pageBrand[$this->getPage()] = $doc;
        $lm = $this->original_lMargin;
        $w = $this->getPageWidth() - $lm - $this->original_rMargin;
        if (is_file(self::PRODUCER_LOGO)) $this->Image(self::PRODUCER_LOGO, $lm, 5, 0, 8, 'JPG');
        if (is_file(self::NETWORK_LOGO)) $this->Image(self::NETWORK_LOGO, $lm + 10, 7, 26, 0, 'JPG');
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColorArray(self::MUTED);
        $this->SetXY($lm + 40, 7);
        $this->Cell($w - 40, 4, $doc['title'] . ($doc['ref'] !== '' ? ' · ' . $doc['ref'] : ''), 0, 0, 'R', false, '', 1);
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.2);
        $this->Line($lm, 14, $lm + $w, 14);
    }

    public function Footer()
    {
        $doc = $this->pageBrand[$this->getPage()] ?? null;
        if ($doc === null) return;
        $lm = $this->original_lMargin;
        $w = $this->getPageWidth() - $lm - $this->original_rMargin;
        $y = $this->getPageHeight() - 11.5;
        $this->SetDrawColorArray(self::BRAND);
        $this->SetLineWidth(0.4);
        $this->Line($lm, $y, $lm + 18, $y);
        $this->SetDrawColorArray(self::LINE);
        $this->SetLineWidth(0.2);
        $this->Line($lm + 18, $y, $lm + $w, $y);
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColorArray(self::MUTED);
        $this->SetXY($lm, $y + 1.3);
        // numer strony wyrównany do lewej : aliasy liczby stron mają inną szerokość niż wynik
        $this->Cell($w - 76, 4, $doc['company'], 0, 0, 'L', false, '', 1);
        $this->SetX($lm + $w - 72);
        $this->Cell(44, 4, DocumentLabels::pair('page', $doc['lang']) . ' ' . $this->getPageNumGroupAlias() . ' / ' . $this->getPageGroupAlias(), 0, 0, 'L');
        $this->SetX($lm + $w - 26);
        $this->Cell(26, 4, date('Y-m-d H:i'), 0, 0, 'R');
    }

    /** Komórka nagłówka pola formularza CMR (numer pola + opis). */
    public function createHederToCell($number, $text)
    {
        $this->SetFont('dejavusans', '', 6);

        $this->MultiCell(6, 6, "<b>$number</b>", 'TL', "C", 0, '', '', '', true, false, true, 'M');
        $this->MultiCell(89, 6, "$text", 'TR', 'L', 0, 0, '', '', true);
        $this->SetFont('dejavusans', '', 10);
    }
}
