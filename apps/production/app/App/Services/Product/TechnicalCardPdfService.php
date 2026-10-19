<?php

namespace App\Services\Product;

use App\Services\Utils\TCPDFService;

/**
 * Generuje kartę techniczną jako PDF.
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
    // Kolory zgodne z API TechnicalSheetService
    private array $grayDark    = [51, 51, 51];
    private array $grayMedium  = [102, 102, 102];
    private array $grayLight   = [208, 208, 208];
    private array $grayLighter = [224, 224, 224];
    private array $grayBg     = [248, 248, 248];
    private array $warningBg   = [255, 243, 205];
    private array $warningText = [133, 100, 4];

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
        $pdf = $this->createPdf();
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

    private function createPdf(): TCPDFService
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SLFood');
        $pdf->SetTitle('Karta techniczna');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        return $pdf;
    }

    private function buildContent(TCPDFService $pdf, array $data): void
    {
        $pdf->AddPage();

        $object      = $data['object']      ?? [];
        $recipe      = $data['recipe']      ?? [];
        $prepTypes   = $data['preparation']['types'] ?? [];
        $prepSteps   = $data['preparation']['steps'] ?? [];
        $competencies = $data['competencies'] ?? [];

        // ── 1. Nagłówek ──────────────────────────────────────────────────────
        $this->drawHeader($pdf, $object, $prepTypes);

        // ── 2. Składniki ─────────────────────────────────────────────────────
        $this->drawSectionTitle($pdf, 'Składniki / Ingrédients');
        $elements = $recipe['elements'] ?? [];
        if (!empty($elements)) {
            $this->drawIngredientsTable($pdf, $elements);
        } else {
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
            $pdf->Cell(0, 5, 'Brak składników.', 0, 1, 'L');
        }
        $pdf->Ln(4);

        // ── 3. Typy przygotowania ─────────────────────────────────────────────
        if (!empty($prepTypes)) {
            $this->drawPreparationTypes($pdf, $prepTypes);
            $pdf->Ln(4);
        }

        // ── 4. Kroki przygotowania ────────────────────────────────────────────
        if (!empty($prepSteps)) {
            $this->drawSectionTitle($pdf, 'Przygotowanie / Réalisation');
            $this->drawPreparationSteps($pdf, $prepSteps);
            $pdf->Ln(4);
        }

        // ── 5. Wymagane kompetencje ───────────────────────────────────────────
        $this->drawSectionTitle($pdf, 'Wymagane kompetencje');
        if (!empty($competencies)) {
            $this->drawCompetencies($pdf, $competencies);
        } else {
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
            $pdf->Cell(0, 5, 'Brak przypisanych kompetencji.', 0, 1, 'L');
        }
    }

    // ── Nagłówek ──────────────────────────────────────────────────────────────

    private function drawHeader(TCPDFService $pdf, array $object, array $prepTypes): void
    {
        // Kategoria
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
        $pdf->Cell(0, 5, ucfirst((string)($object['category_name'] ?? '')), 0, 1, 'L');

        // Nazwa produktu
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
        $pdf->Cell(130, 8, (string)($object['name'] ?? ''), 0, 0, 'L');

        // Łączny czas przygotowania (badge po prawej)
        $totalSec = array_sum(array_column($prepTypes, 'duration_second'));
        if ($totalSec > 0) {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
            $pdf->Cell(60, 8, 'Czas: ' . $this->formatTime($totalSec), 0, 0, 'R');
        }
        $pdf->Ln(10);

        // Linia oddzielająca
        $pdf->SetLineStyle(['width' => 0.3, 'color' => $this->grayLight]);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(4);
    }

    // ── Tabela składników ─────────────────────────────────────────────────────

    private function drawIngredientsTable(TCPDFService $pdf, array $elements): void
    {
        // Nagłówek tabeli
        $pdf->SetFillColor($this->grayBg[0], $this->grayBg[1], $this->grayBg[2]);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
        $pdf->Cell(8,  6, '#',          'B', 0, 'L', true);
        $pdf->Cell(107, 6, 'Składnik',  'B', 0, 'L', true);
        $pdf->Cell(30, 6, 'Ilość',      'B', 0, 'L', true);
        $pdf->Cell(45, 6, 'Alergeny',   'B', 1, 'L', true);

        $pdf->SetFont('dejavusans', '', 8);

        $counter = 1;
        $this->drawIngredientRows($pdf, $elements, $counter, 0);
    }

    private function drawIngredientRows(TCPDFService $pdf, array $elements, int &$counter, int $depth): void
    {
        // Stałe layoutu
        $leftMargin  = 10;   // mm – lewy margines strony
        $indentStep  = 6;    // mm – wcięcie na każdy poziom głębokości
        $colNum      = 8;    // mm – kolumna numeru
        $colQty      = 30;   // mm – kolumna ilości
        $colAllergen = 45;   // mm – kolumna alergenów
        $totalWidth  = 190;  // mm – całkowita szerokość tabeli

        foreach ($elements as $el) {
            $isSubRecipe = $el['type'] === 'sub-recipe';
            $indent      = $depth * $indentStep;   // fizyczne wcięcie w mm

            // Kolumna nazwy zwęża się proporcjonalnie do wcięcia
            $colName = $totalWidth - $colNum - $colQty - $colAllergen - $indent;

            $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);

            if ($isSubRecipe) {
                // ── Nagłówek podreceptury ────────────────────────────────────
                $pdf->SetFont('dejavusans', 'B', 8);
                $pdf->SetFillColor($this->grayLighter[0], $this->grayLighter[1], $this->grayLighter[2]);

                // Pionowa linia oznaczająca poziom zagnieżdżenia (rysowana przed komórkami)
                if ($depth > 0) {
                    $lineX = $leftMargin + $indent - 2;
                    $lineY = $pdf->GetY();
                    $pdf->SetDrawColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
                    $pdf->SetLineWidth(0.4);
                    $pdf->Line($lineX, $lineY, $lineX, $lineY + 5);
                }

                // Pusta komórka numeru
                $pdf->SetX($leftMargin + $indent);
                $pdf->Cell($colNum, 5, '', 'B', 0, 'L', true);

                // Nazwa podreceptury z ikoną folderu
                $subName = ($depth > 0 ? '▸ ' : '') . ($el['name'] ?? '');
                $pdf->Cell($colName, 5, $subName, 'B', 0, 'L', true);

                // Ilość
                $qty = $this->fmtQty((float)($el['required_quantity'] ?? 0), $el['unit_name'] ?? '');
                $pdf->Cell($colQty, 5, $qty . ' ' . ($el['unit_name'] ?? ''), 'B', 0, 'L', true);

                // Alergeny zebrane z podreceptury
                $allergenStr = !empty($el['allergens']) ? implode(', ', $el['allergens']) : '-';
                $pdf->Cell($colAllergen, 5, $allergenStr, 'B', 1, 'L', true);

                $pdf->SetFont('dejavusans', '', 8);

                // Rysuj składniki tej podreceptury rekurencyjnie
                if (!empty($el['elements'])) {
                    $this->drawIngredientRows($pdf, $el['elements'], $counter, $depth + 1);
                }

                // Pionowa linia zamykająca blok podreceptury (ciągła od nagłówka do ostatniego składnika)
                // (rysowanie odbywa się w trakcie rekurencji — wystarczy linia przy każdym składniku)

            } else {
                // ── Składnik ─────────────────────────────────────────────────
                $allergens     = $el['allergens'] ?? [];
                $badgeHeight   = 3.5;
                $badgeGap      = 1;
                $allergenCount = count($allergens);
                $rowH = $allergenCount > 0 ? ($allergenCount * ($badgeHeight + $badgeGap)) + 1 : 5;

                $startY = $pdf->GetY();
                $startX = $leftMargin + $indent;

                // Pionowa linia głębokości (wzdłuż całego wiersza składnika)
                if ($depth > 0) {
                    $lineX = $leftMargin + $indent - 2;
                    $pdf->SetDrawColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
                    $pdf->SetLineWidth(0.4);
                    $pdf->Line($lineX, $startY, $lineX, $startY + $rowH);
                    // Krótka pozioma gałązka
                    $pdf->SetLineWidth(0.2);
                    $pdf->Line($lineX, $startY + $rowH / 2, $lineX + 2, $startY + $rowH / 2);
                }

                // Nieznacznie jaśniejszy font dla zagłębionych składników
                if ($depth > 0) {
                    $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
                } else {
                    $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
                }

                // Komórka numeru
                $pdf->SetX($startX);
                $pdf->Cell($colNum, $rowH, $counter . '.', 'B', 0, 'L');

                // Komórka nazwy
                $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
                $pdf->Cell($colName, $rowH, ($el['name'] ?? ''), 'B', 0, 'L');

                // Komórka ilości
                $qty = $this->fmtQty((float)($el['required_quantity'] ?? 0), $el['unit_name'] ?? '');
                $pdf->Cell($colQty, $rowH, $qty . ' ' . ($el['unit_name'] ?? ''), 'B', 0, 'L');

                // Komórka alergenów
                $cellX = $pdf->GetX();
                $cellY = $pdf->GetY();

                if (!empty($allergens)) {
                    $pdf->SetFont('dejavusans', '', 7);
                    $pdf->SetTextColor($this->warningText[0], $this->warningText[1], $this->warningText[2]);
                    $pdf->SetFillColor($this->warningBg[0], $this->warningBg[1], $this->warningBg[2]);

                    $currentY = $cellY + 0.5;
                    foreach ($allergens as $allergen) {
                        $bw = $pdf->GetStringWidth((string)$allergen) + 5;
                        $pdf->RoundedRect($cellX + 1, $currentY, $bw, $badgeHeight, 1, '1111', 'F');
                        $pdf->SetXY($cellX + 1, $currentY + 1);
                        $pdf->Cell($bw, $badgeHeight, (string)$allergen, 0, 0, 'C');
                        $currentY += $badgeHeight + $badgeGap;
                    }

                    // Linia dolna kolumny alergenów
                    $pdf->SetDrawColor($this->grayLighter[0], $this->grayLighter[1], $this->grayLighter[2]);
                    $pdf->SetLineWidth(0.1);
                    $pdf->Line($cellX, $startY + $rowH, $cellX + $colAllergen, $startY + $rowH);
                } else {
                    $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
                    $pdf->Cell($colAllergen, $rowH, '-', 'B', 0, 'L');
                }

                // Wróć kursorem na początek następnego wiersza
                $pdf->SetXY($leftMargin, $startY + $rowH);
                $pdf->Ln(0);

                $counter++;
            }

            $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
            $pdf->SetFont('dejavusans', '', 8);
        }
    }

    // ── Typy przygotowania ────────────────────────────────────────────────────

    private function drawPreparationTypes(TCPDFService $pdf, array $types): void
    {
        $totalSec  = array_sum(array_column($types, 'duration_second'));
        $totalStr  = $this->formatTime($totalSec);

        $this->drawSectionTitleWithBadge($pdf, 'Czas przygotowania', $totalStr, 190);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);

        $i = 1;
        foreach ($types as $type) {
            $pdf->Cell(8,  4, $i . '.',                             0, 0, 'L');
            $pdf->Cell(120, 4, (string)($type['name'] ?? ''),       0, 0, 'L');
            $pdf->Cell(62, 4, $this->formatTime((int)($type['duration_second'] ?? 0)), 0, 1, 'R');
            $i++;
        }
    }

    // ── Kroki przygotowania ───────────────────────────────────────────────────

    private function drawPreparationSteps(TCPDFService $pdf, array $steps): void
    {
        $pdf->SetFont('dejavusans', '', 8.5);

        foreach ($steps as $step) {
            $currentY = $pdf->GetY();

            // Okrąg z numerem kroku
            $pdf->SetFillColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
            $pdf->Circle(14, $currentY + 2.5, 2.5, 0, 360, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->SetXY(12, $currentY + 1);
            $pdf->Cell(4, 3, (string)($step['step_number'] ?? ''), 0, 0, 'C');

            // Opis kroku
            $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->SetXY(20, $currentY);

            $desc = (string)($step['step_description'] ?? '');
            if (preg_match('/^(.+?):(.+)$/', $desc, $m)) {
                $pdf->SetFont('dejavusans', 'B', 8.5);
                $pdf->Write(4, $m[1] . ':');
                $pdf->SetFont('dejavusans', '', 8.5);
                $pdf->Write(4, $m[2]);
                $pdf->Ln(7);
            } else {
                $pdf->MultiCell(175, 4, $desc, 0, 'L');
            }
            $pdf->Ln(1);
        }
    }

    // ── Wymagane kompetencje ──────────────────────────────────────────────────

    private function drawCompetencies(TCPDFService $pdf, array $competencies): void
    {
        $pdf->SetFont('dejavusans', '', 8.5);

        foreach ($competencies as $competency) {
            // Bullet
            $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
            $pdf->Cell(5, 5, '•', 0, 0, 'L');

            // Nazwa kompetencji (bold)
            $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->Write(5, (string)($competency['name'] ?? ''));

            // Opis (normalny, szary) – jeśli istnieje
            if (!empty($competency['description'])) {
                $pdf->SetFont('dejavusans', '', 8.5);
                $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
                $pdf->Write(5, '  ' . (string)$competency['description']);
            }

            $pdf->Ln(5);
        }

        // Reset koloru i fontu
        $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
        $pdf->SetFont('dejavusans', '', 8);
    }

    // ── Pomocniki rysowania ───────────────────────────────────────────────────

    private function drawSectionTitle(TCPDFService $pdf, string $title): void
    {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
        $pdf->Cell(0, 6, ucfirst($title), 0, 1, 'L');

        $y = $pdf->GetY();
        $pdf->SetLineStyle(['width' => 0.2, 'color' => $this->grayLight]);
        $pdf->Line(10, $y - 1, 200, $y - 1);
        $pdf->Ln(2);
    }

    private function drawSectionTitleWithBadge(TCPDFService $pdf, string $title, string $badge, float $rightEdge): void
    {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);

        $titleW = $pdf->GetStringWidth(ucfirst($title));
        $pdf->Cell($titleW + 2, 6, ucfirst($title), 0, 0, 'L');

        // Badge
        $pdf->SetFont('dejavusans', 'B', 8);
        $bw   = $pdf->GetStringWidth($badge) + 6;
        $bx   = $rightEdge - $bw;
        $by   = $pdf->GetY();

        $pdf->SetFillColor($this->grayMedium[0], $this->grayMedium[1], $this->grayMedium[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->RoundedRect($bx, $by, $bw, 4, 2, '1111', 'F');
        $pdf->SetXY($bx, $by + 1.5);
        $pdf->Cell($bw, 4, $badge, 0, 1, 'C');

        $y = $pdf->GetY();
        $pdf->SetLineStyle(['width' => 0.2, 'color' => $this->grayLight]);
        $pdf->Line(10, $y + 1, 200, $y + 1);
        $pdf->Ln(4);

        $pdf->SetTextColor($this->grayDark[0], $this->grayDark[1], $this->grayDark[2]);
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

