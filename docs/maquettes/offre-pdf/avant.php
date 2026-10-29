<?php
/**
 * AVANT — le devis tel qu'OfferService::getPDF() le dessine aujourd'hui
 * (thème or #CD9B36 / anthracite #3F4648, DejaVu Sans). Les méthodes
 * render*() et fillProfessionalTable() sont recopiées telles quelles ;
 * seules les données arrivent en paramètre au lieu des dépôts.
 */
final class OffreAvant
{
    public function document(array $offerData, array $shopData): TCPDF
    {
        $currencySymbol = CurrencySymbol::getSymbol();
        $client = $offerData['client'];

        // Configuration PDF avec thème gold/charcoal
        $pdf = new TCPDFModel('p', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(15, 20, 15);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        // === EN-TÊTE PROFESSIONNEL ===
        $this->renderHeader($pdf, $shopData);

        // === INFORMATIONS CLIENT ===
        $this->renderClientInfo($pdf, $client);

        // === TITRE DE L'OFFRE ===
        $this->renderOfferTitle($pdf, $offerData);

        $pdf->setMargins(8, 10, 8);
        $pdf->Ln(0);

        // === TABLEAU DES PRODUITS ===
        $this->renderProductsTable($pdf, $offerData['products']);

        $pdf->setMargins(15, 20, 15);
        $pdf->Ln(0);

        // === INFORMATIONS DE LIVRAISON ===
        $this->renderDeliveryInfo($pdf, $offerData, $currencySymbol);

        // === COMMENTAIRES ===
        $this->renderComments($pdf, $offerData);

        // === PIED DE PAGE ===
        $this->renderFooter($pdf, $shopData);

        return $pdf;
    }

    private function renderHeader($pdf, $shopData)
    {
        // Logo en haut à droite
        $pdf->Image(SHARED_FILES_URL . "/franchise-images/pdf-logo.jpg", 160, 15, 35, '', 'JPG', '', '', false, 300, '', false, false, 0);

        // Informations de l'entreprise - Style premium gold/charcoal
        $pdf->SetFont('dejavusans ', 'B', 12);
        $pdf->SetTextColor(63, 70, 72); // #3F4648 - Charcoal foncé
        $pdf->Cell(0, 6, $shopData['representative_name'], 0, 1, 'L');

        $pdf->SetFont('dejavusans ', '', 8);
        $pdf->SetTextColor(120, 120, 120); // Gris moyen pour les détails
        $pdf->Cell(0, 5, $shopData['street'] . ' ' . $shopData['street_num'], 0, 1, 'L');
        $pdf->Cell(0, 5, $shopData['zip'] . ' ' . $shopData['city'], 0, 1, 'L');
        $pdf->Cell(0, 5, 'TVA: ' . $shopData['tax_number_prefixe'] . $shopData['tax_number'], 0, 1, 'L');
        $pdf->Cell(0, 5, 'Tél: ' . $shopData['phone'], 0, 1, 'L');

        // Ligne de séparation élégante en gold
        $pdf->SetDrawColor(205, 155, 54); // #CD9B36 - Gold
        $pdf->Line(15, 55, 195, 55);
        $pdf->Ln(15);
    }

    private function renderClientInfo($pdf, $client)
    {
        $pdf->SetFont('dejavusans ', 'B', 10);
        $pdf->SetTextColor(63, 70, 72); // #3F4648
        $pdf->Cell(0, 6, getTranslation('offer', 'client_information', Language::getCallLanguageCode()), 0, 1, 'L');

        // Cadre avec couleurs thématiques
        $pdf->SetDrawColor(221, 221, 221); // #DDDDDD - Bordure gris clair
        $pdf->SetFillColor(248, 248, 248); // Fond très légèrement gris

        $clientName = $client['is_b2b'] ? $client['company_name'] : $client['firstname'] . ' ' . $client['lastname'];

        $clientInfo = '<div style="padding: 6px;"><strong style="font-size: 9pt; color: #3F4648;">' . htmlspecialchars($clientName) . '</strong><br/><br/>';

        if ($client['is_b2b'] && !empty($client['tax_number'])) {
            $clientInfo .= '<span style="color: #CD9B36; font-size: 8pt;">' . getTranslation('offer', 'tax_number_short', Language::getCallLanguageCode()) . ': ' . htmlspecialchars($client['tax_number']) . '</span><br/>';
        }

        $clientInfo .= '<span style="color: #3F4648; font-size: 8pt;">' .
            htmlspecialchars($client['street']) . ' ' . htmlspecialchars($client['street_number']) . '<br/>' .
            htmlspecialchars($client['zip']) . ' ' . htmlspecialchars($client['city']) . '<br/>' .
            getTranslation('offer', 'phone_short', Language::getCallLanguageCode()) . ': ' . htmlspecialchars($client['phone']) . '<br/>' .
            getTranslation('offer', 'email', Language::getCallLanguageCode()) . ': ' . htmlspecialchars($client['email']) . '</span></div>';

        $pdf->writeHTMLCell(90, 25, 15, $pdf->GetY(), $clientInfo, 1, 1, true, true, 'L', true);
        $pdf->Ln(5);
    }

    private function renderOfferTitle($pdf, $offerData)
    {
        // Titre de l'offre avec gold accent
        $pdf->SetFont('dejavusans ', 'B', 14);
        $pdf->SetTextColor(205, 155, 54); // #CD9B36 - Gold pour le titre principal

        $offerTitle = getTranslation('offer', 'commercial_offer_no', Language::getCallLanguageCode()) . ' ' . str_pad($offerData['id'], 4, '0', STR_PAD_LEFT);
        $pdf->Cell(0, 8, $offerTitle, 0, 1, 'C');

        $pdf->SetFont('dejavusans ', '', 10);
        $pdf->SetTextColor(63, 70, 72); // #3F4648 - Retour au charcoal
        $pdf->Cell(0, 6, htmlspecialchars($offerData['name']), 0, 1, 'C');

        $pdf->SetFont('dejavusans ', '', 8);
        $pdf->SetTextColor(120, 120, 120); // Gris pour la date
        $pdf->Cell(0, 5, getTranslation('offer', 'date', Language::getCallLanguageCode()) . ': ' . date('d/m/Y', strtotime($offerData['event_day'])), 0, 1, 'C');
        $pdf->Ln(10);
    }

    private function renderProductsTable($pdf, $products)
    {
        $pdf->SetFont('dejavusans ', 'B', 8);
        $pdf->SetTextColor(63, 70, 72); // #3F4648
        $pdf->Cell(0, 6, getTranslation('offer', 'detailed_product_information', Language::getCallLanguageCode()), 0, 1, 'L');
        $pdf->Ln(2);

        // En-têtes du tableau avec thème gold/charcoal
        $header = [
            getTranslation('offer', 'product', Language::getCallLanguageCode()),
            getTranslation('offer', 'quantity_short', Language::getCallLanguageCode()),
            getTranslation('offer', 'unit_price_short', Language::getCallLanguageCode()),
            getTranslation('offer', 'total', Language::getCallLanguageCode()) . "\n(" . strtolower(getTranslation('offer', 'gross', Language::getCallLanguageCode())) . ")",
            getTranslation('offer', 'discount', Language::getCallLanguageCode()),
            getTranslation('offer', 'unit_price_short', Language::getCallLanguageCode()) . "\n(" . strtolower(getTranslation('offer', 'after_discount', Language::getCallLanguageCode())) . ")",

            getTranslation('offer', 'total', Language::getCallLanguageCode()) . "\n(" . strtolower(getTranslation('offer', 'net', Language::getCallLanguageCode())) . ")",
            getTranslation('offer', 'total', Language::getCallLanguageCode()) . "\n(" . strtolower(getTranslation('offer', 'gross', Language::getCallLanguageCode())) . ")"
        ];

        $this->fillProfessionalTable($pdf, $header, $products);
    }

    private function fillProfessionalTable($pdf, $header, $data)
    {
        $currencySymbol = CurrencySymbol::getSymbol();


        // Configuration des colonnes
        $w = [65, 12, 18, 18, 19, 24, 18, 18];
        $pdf->SetFont('dejavusans ', 'B', 7);
        $pdf->SetFillColor(63, 70, 72); // #3F4648 - Charcoal pour l'en-tête
        $pdf->SetTextColor(255, 255, 255); // Blanc sur charcoal
        $pdf->SetDrawColor(221, 221, 221); // #DDDDDD - Bordures grises

        // En-têtes
        for ($i = 0; $i < count($header); $i++) {
            $align = ($i == 0) ? 'L' : 'C';

            // MultiCell automatycznie obsługuje \n
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->MultiCell($w[$i], 7, $header[$i], 1, $align, 1, 0, '', '', true, 0, false, true, 7, 'M', true);
            $pdf->SetXY($x + $w[$i], $y); // ustaw pozycję obok
        }
        $pdf->Ln();
        // Lignes de données avec alternance
        $pdf->SetFont('dejavusans ', '', 7);
        $pdf->SetTextColor(63, 70, 72); // #3F4648 - Texte charcoal

        $totalSummaryGross = 0.00;
        $totalSummaryNet = 0.00;
        $fill = false;

        foreach ((array)$data as $item) {
            // Couleur de fond alternée
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245); // Gris très clair
            } else {
                $pdf->SetFillColor(255, 255, 255); // Blanc
            }

            $discountDisplay = ($item['id_discount_type'] == 1)
                ? $item['discount_value'] . '%'
                : number_format($item['discount_value'], 2) . $currencySymbol;

            $totalSummaryGross += $item['final_total_price'];
            $totalSummaryNet += $item['final_total_price_net'];
            $unitPriceAfterDiscount = ($item['final_total_price'] / $item['quantity']);


            // Ajustement de la hauteur pour les noms de produits longs
            $cellHeight = 5;
            if (strlen($item['product_name']) > 40) {
                $cellHeight = 6;
            }

            $pdf->Cell($w[0], $cellHeight, $item['product_name'], 1, 0, 'L', $fill);
            $pdf->Cell($w[1], $cellHeight, number_format($item['quantity'], 0), 1, 0, 'C', $fill);
            $pdf->Cell($w[2], $cellHeight, number_format($item['unit_price'], 2) . $currencySymbol, 1, 0, 'C', $fill);
            $pdf->Cell($w[3], $cellHeight, number_format($item['total_price_gross'], 2) . $currencySymbol, 1, 0, 'C', $fill);
            $pdf->Cell($w[4], $cellHeight, $discountDisplay, 1, 0, 'C', $fill);
            $pdf->Cell($w[5], $cellHeight, number_format($unitPriceAfterDiscount, 2) . $currencySymbol, 1, 0, 'C', $fill);
            $pdf->Cell($w[6], $cellHeight, number_format($item['final_total_price_net'], 2) . $currencySymbol, 1, 0, 'C', $fill);
            $pdf->Cell($w[7], $cellHeight, number_format($item['final_total_price'], 2) . $currencySymbol, 1, 0, 'C', $fill);
            $pdf->Ln();

            $fill = !$fill;
        }

        // Total général avec gold accent
        $pdf->SetFont('dejavusans ', 'B', 7);
        $pdf->SetFillColor(205, 155, 54); // #CD9B36 - Gold pour le total
        $pdf->SetTextColor(255, 255, 255); // Blanc sur gold

        $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], 7, getTranslation('offer', 'total_general', Language::getCallLanguageCode()), 1, 0, 'R', 1);
        $pdf->Cell($w[6], 7, number_format($totalSummaryNet, 2) . $currencySymbol, 1, 0, 'C', 1);
        $pdf->Cell($w[7], 7, number_format($totalSummaryGross, 2) . $currencySymbol, 1, 1, 'C', 1);

        $pdf->Ln(10);
    }

    private function renderDeliveryInfo($pdf, $offerData, $currencySymbol)
    {
        $pdf->SetFont('dejavusans ', 'B', 10);
        $pdf->SetTextColor(63, 70, 72); // #3F4648
        $pdf->Cell(0, 6, getTranslation('offer', 'delivery_information', Language::getCallLanguageCode()), 0, 1, 'L');

        $pdf->SetDrawColor(221, 221, 221); // #DDDDDD
        $pdf->SetFillColor(250, 250, 250); // Fond très légèrement gris

        if ($offerData['in_store_pickup'] == 1) {
            $deliveryInfo = '<div style="padding: 6px;">' .
                '<strong style="color: #CD9B36; font-size: 9pt;">' . getTranslation('offer', 'in_store_pickup', Language::getCallLanguageCode()) . '</strong><br/><br/>' .
                '<span style="color: #3F4648; font-size: 8pt;">' . htmlspecialchars($offerData['conditions']) .
                '</span> </div>';
        } else {
            $deliveryInfo = '<div style="padding: 6px;"><strong style="color: #CD9B36; font-size: 9pt;">' . getTranslation('offer', 'delivery', Language::getCallLanguageCode()) . '</strong><br/><br/> ' .
                '<span style="color: #3F4648; font-size: 8pt;">' .
                '<strong>' . getTranslation('offer', 'delivery_address', Language::getCallLanguageCode()) . ':</strong><br/>' .
                htmlspecialchars($offerData['delivery_street']) . '<br/>' .
                htmlspecialchars($offerData['delivery_zip']) . ' ' . htmlspecialchars($offerData['delivery_city']) . '<br/><br/>' .
                '<strong>' . getTranslation('offer', 'shipping_cost', Language::getCallLanguageCode()) . ':</strong> ' . number_format($offerData['delivery_cost'], 2) . $currencySymbol . '<br/><br/>' .
                '<strong>' . getTranslation('offer', 'conditions', Language::getCallLanguageCode()) . ':</strong><br/>' .
                htmlspecialchars($offerData['conditions']) . '</span></div>';
        }

        $pdf->writeHTMLCell(0, 0, 15, $pdf->GetY(), $deliveryInfo, 1, 1, true, true, 'L', true);
        $pdf->Ln(5);
    }

    private function renderComments($pdf, $offerData)
    {
        if (!empty($offerData['description'])) {
            $pdf->SetFont('dejavusans ', 'B', 10);
            $pdf->SetTextColor(63, 70, 72); // #3F4648
            $pdf->Cell(0, 6, getTranslation('offer', 'comment', Language::getCallLanguageCode()), 0, 1, 'L');

            $pdf->SetDrawColor(221, 221, 221); // #DDDDDD
            $pdf->SetFillColor(252, 248, 227); // Fond légèrement doré très subtil

            $commentInfo = '<div style="padding: 6px;"><span style="color: #3F4648; font-style: italic; font-size: 8pt;">' . htmlspecialchars($offerData['description']) . '</span></div>';

            $pdf->writeHTMLCell(0, 0, 15, $pdf->GetY(), $commentInfo, 1, 1, true, true, 'L', true);
            $pdf->Ln(5);
        }
    }

    private function renderFooter($pdf, $shopData)
    {
        // Positionnement en bas de page
        $pdf->SetY(-35);

        // Ligne de séparation en gold
        $pdf->SetDrawColor(205, 155, 54); // #CD9B36
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        $pdf->SetFont('dejavusans ', '', 7);
        $pdf->SetTextColor(120, 120, 120); // Gris moyen pour le footer


        $footerText = getTranslation('offer', 'general_terms_available_upon_request', Language::getCallLanguageCode()) . " " .
            getTranslation('offer', 'document_generated_on', Language::getCallLanguageCode()) . " " . date('d/m/Y à H:i');

        $pdf->MultiCell(0, 4, $footerText, 0, 'C', 0, 1);

        // Informations bancaires si disponibles - avec accent gold
        if (!empty($shopData['bank_iban'])) {
            $pdf->Ln(2);
            $pdf->SetFont('dejavusans ', '', 7);
            $pdf->SetTextColor(205, 155, 54); // #CD9B36 - Gold pour les infos bancaires
            $bankInfo = 'IBAN: ' . $shopData['bank_iban'] . ' - ' . $shopData['bank_name'];
            $pdf->Cell(0, 3, $bankInfo, 0, 1, 'C');
        }
    }
}
