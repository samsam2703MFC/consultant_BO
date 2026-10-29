<?php

namespace App\Services\Product;

use App\Services\Utils\TCPDFService;

/**
 * Generuje kartę techniczną jako PDF (styl dokumentów L'Atelier : TCPDFService::brand*).
 *
 * Wejście: tablica zwracana przez TechnicalCardDataService::getForProduct()
 * lub ::getForComposition().
 *
 * Sekcje karty:
 *   1. Nagłówek – kategoria, nazwa, czas przygotowania
 *   2. Składniki z alergenami
 *   3. Typy przygotowania (piekarnik, mikser itp.)
 *   4. Kroki przygotowania
 */
class TechnicalCardPdfService
{
    // -------------------------------------------------------------------------
    // Publiczne API
    // -------------------------------------------------------------------------

    /**
     * Generuje PDF dla jednej karty i zwraca jej zawartość jako string.
     *
     * @param array $data  Wynik TechnicalCardDataService::getFor*()
     * @return array ['filename' => string, 'file_string' => string]
     */
    public function generate(array $data): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SLFood');
        $pdf->SetTitle('Karta techniczna');
        $this->buildContent($pdf, $data);

        $safeName = preg_replace('/[^\w\-]/u', '_', $data['object']['name'] ?? 'karta');
        $pdfString = $pdf->Output('karta.pdf', 'S');

        return [
            'filename'    => $safeName . '.pdf',
            'file_string' => $pdfString,
        ];
    }

    // -------------------------------------------------------------------------
    // Budowanie zawartości
    // -------------------------------------------------------------------------

    private function buildContent(TCPDFService $pdf, array $data): void
    {
        $object      = $data['object']      ?? [];
        $recipe      = $data['recipe']      ?? [];
        $prepTypes   = $data['preparation']['types'] ?? [];
        $prepSteps   = $data['preparation']['steps'] ?? [];
        $competencies = $data['competencies'] ?? [];

        // ── 1. Nagłówek ──────────────────────────────────────────────────────
        $name = (string)($object['name'] ?? '');
        $totalSec = array_sum(array_column($prepTypes, 'duration_second'));
        $meta = [['Kategoria', ucfirst((string)($object['category_name'] ?? ''))]];
        if ($totalSec > 0) $meta[] = ['Czas', $this->formatTime($totalSec)];
        $meta[] = ['Wydruk', date('d.m.Y')];
        $pdf->brandPage('Karta techniczna · ' . $name);
        $pdf->brandHeader($name, $meta, 'Karta techniczna / Fiche technique');

        // ── 2. Składniki ─────────────────────────────────────────────────────
        $pdf->brandSection('Składniki / Ingrédients');
        $elements = $recipe['elements'] ?? [];
        if (!empty($elements)) {
            $rows = [];
            $counter = 1;
            $this->ingredientRows($elements, $counter, 0, $rows);
            $pdf->brandTable([
                ['label' => '#', 'w' => 8, 'align' => 'R'],
                ['label' => 'Składnik', 'w' => 100],
                ['label' => 'Ilość', 'w' => 30, 'align' => 'R'],
                ['label' => 'Alergeny', 'w' => 52],
            ], $rows, ['font' => 8.5]);
        } else {
            $pdf->brandNote('Brak składników.');
        }

        // ── 3. Typy przygotowania ─────────────────────────────────────────────
        if (!empty($prepTypes)) {
            $pdf->brandSection('Czas przygotowania', 'Razem : ' . $this->formatTime($totalSec));
            $rows = [];
            foreach (array_values($prepTypes) as $i => $type) {
                $rows[] = [($i + 1) . '.', (string)($type['name'] ?? ''), $this->formatTime((int)($type['duration_second'] ?? 0))];
            }
            $pdf->brandTable([
                ['label' => '#', 'w' => 8, 'align' => 'R'],
                ['label' => 'Etap', 'w' => 140],
                ['label' => 'Czas', 'w' => 42, 'align' => 'R'],
            ], $rows, ['font' => 8.5]);
        }

        // ── 4. Kroki przygotowania ────────────────────────────────────────────
        if (!empty($prepSteps)) {
            $pdf->brandSection('Przygotowanie / Réalisation');
            $this->drawPreparationSteps($pdf, $prepSteps);
        }

        // ── 5. Wymagane kompetencje ───────────────────────────────────────────
        $pdf->brandSection('Wymagane kompetencje');
        if (!empty($competencies)) {
            $rows = array_map(fn($c) => [(string)($c['name'] ?? ''), (string)($c['description'] ?? '')], $competencies);
            $pdf->brandTable([
                ['label' => 'Kompetencja', 'w' => 60],
                ['label' => 'Opis', 'w' => 130],
            ], $rows, ['font' => 8.5]);
        } else {
            $pdf->brandNote('Brak przypisanych kompetencji.');
        }
    }

    // ── Składniki ─────────────────────────────────────────────────────────────

    /**
     * Wiersze tabeli składników : podreceptura = wiersz grupy (ilość, alergeny), jej składniki z wcięciem ;
     * numeracja tylko składników, ciągła przez całą kartę.
     */
    private function ingredientRows(array $elements, int &$counter, int $depth, array &$rows): void
    {
        $indent = str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth);
        foreach ($elements as $el) {
            $qty = $this->fmtQty((float)($el['required_quantity'] ?? 0), $el['unit_name'] ?? '') . ' ' . ($el['unit_name'] ?? '');
            $allergens = !empty($el['allergens']) ? implode(', ', $el['allergens']) : '-';
            if ($el['type'] === 'sub-recipe') {
                $rows[] = ['_group' => $indent . ($depth > 0 ? '▸ ' : '') . ($el['name'] ?? '') . ' · ' . trim($qty) . ' · Alergeny : ' . $allergens];
                if (!empty($el['elements'])) {
                    $this->ingredientRows($el['elements'], $counter, $depth + 1, $rows);
                }
                continue;
            }
            $rows[] = [$counter . '.', $indent . ($el['name'] ?? ''), trim($qty), $allergens];
            $counter++;
        }
    }

    // ── Kroki przygotowania ───────────────────────────────────────────────────

    /** Krok = numer w kółku w kolorze marki + opis (część przed « : » pogrubiona). */
    private function drawPreparationSteps(TCPDFService $pdf, array $steps): void
    {
        $lm = $pdf->getMargins()['left'];
        $w = $pdf->contentWidth() - 9;
        foreach ($steps as $step) {
            $desc = (string)($step['step_description'] ?? '');
            $html = preg_match('/^(.+?):(.+)$/s', $desc, $m)
                ? '<b>' . htmlspecialchars($m[1] . ':', ENT_QUOTES, 'UTF-8') . '</b>' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
            $pdf->SetFont('dejavusans', '', 8.5);
            $h = max(6, $pdf->getStringHeight($w, $desc) + 1);
            if ($pdf->GetY() + $h > $pdf->getPageHeight() - $pdf->getBreakMargin()) $pdf->AddPage();
            $y = $pdf->GetY();

            $pdf->SetFillColorArray(TCPDFService::BRAND);
            $pdf->Circle($lm + 2.6, $y + 2.6, 2.6, 0, 360, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->SetXY($lm, $y + 0.9);
            $pdf->Cell(5.2, 3.5, (string)($step['step_number'] ?? ''), 0, 0, 'C');

            $pdf->SetTextColorArray(TCPDFService::INK);
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->writeHTMLCell($w, 0, $lm + 9, $y + 0.5, $html, 0, 1, false, true, 'L', true);
            $pdf->SetY(max($pdf->GetY(), $y + 6) + 1.5);
        }
        $pdf->SetY($pdf->GetY() + 2);
    }

    // ── Formatowanie ──────────────────────────────────────────────────────────

    private function fmtQty(float $qty, string $unit): string
    {
        $u = strtolower(trim($unit));
        if (in_array($u, ['szt', 'szt.', 'pcs', 'pc', 'sztuka', 'g', 'ml'], true)) {
            return (string)(int)round($qty);
        }
        return number_format($qty, 3, ',', '');
    }

    private function formatTime(int $seconds): string
    {
        if ($seconds <= 0) return '-';
        if ($seconds < 60) return $seconds . 's';
        $min = intdiv($seconds, 60);
        $sec = $seconds % 60;
        return $sec > 0 ? "{$min} min {$sec}s" : "{$min} min";
    }
}

