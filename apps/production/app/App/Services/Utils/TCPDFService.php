<?php

namespace App\Services\Utils;

use TCPDF;

class TCPDFService extends TCPDF
{
    protected $last_page_flag = false;
    public $footer_info_1 = "";
    public $footer_info_2 = "";
    public $footer_string = "";

    public function Close()
    {
        $this->last_page_flag = true;
        parent::Close();
    }

    public function customHeader($data = [], $display_logo = true, $page_orientation = "p")
    {
        $this->SetFont('dejavusans', '', 10);
        if ($display_logo) {
            $this->Image(ROOT . "/resources/images/logo.jpg", 187, 3, 20, 28, 'JPG', '', '', false, 300, '', false, false, 0);
            $this->writeHTMLCell(60, 1, 3, 48, "", 0, 1, 0, true, 'L', true);
        }

        if (isset($data['header_subtitle']) && $data['header_title'] != "") {
            $this->SetFont('dejavusans', 'B', 18);

            switch (strtolower($page_orientation)) {
                case "p":
                    $this->writeHTMLCell(180, 11, 15, '', $data['header_title'], 0, 1, 0, true, 'C');
                    break;
                case "l":
                    $this->writeHTMLCell(180, 11, 58, '', $data['header_title'], 0, 1, 0, true, 'C');
                    break;
            }
        }
        if (isset($data['header_subtitle']) && $data['header_subtitle'] != "") {
            $this->SetFont('dejavusans', '', 15);

            switch (strtolower($page_orientation)) {
                case "p":
                    $this->writeHTMLCell(180, 10, 15, '', $data['header_subtitle'], 0, 1, 0, true, 'C');
                    break;
                case "l":
                    $this->writeHTMLCell(180, 10, 58, '', $data['header_subtitle'], 0, 1, 0, true, 'C');
                    break;
            }
        }
    }

    public function customFooter($data = [])
    {
        $this->footer_info_1 = $data['info_1'];
        $this->SetFont('dejavusans', '', 9);
    }

    public function Header()
    {
    }

    public function Footer()
    {
        if ($this->last_page_flag) {
            // Position at 40 mm from bottom
            $this->SetY(-35);
            // Set font
            $this->SetFont('dejavusans', 'I', 10);
            // Page number

            $this->setCellPaddings($left = '1', $top = '', $right = '', $bottom = '');

            $this->MultiCell(80, 15, $this->footer_info_1, 0, 'L', 0, 0, 5, '', true, 0, false, true, 7);
            $this->Ln();
//            $this->MultiCell(80, 7, $this->footer_info_2, '', 'L', 0, 0, 5, '', true, 0, false, true, 7);
//            $this->Ln();
            $this->SetY(-10);
            $this->MultiCell(150, 12, "Data wydruku: <b>" . date("Y/m/d") . "</b>", '0', 'L', 0, 0, 4, '', true, 0, true, true, 12);

        }
    }

    public function createHederToCell($number, $text)
    {

        $this->SetFont('dejavusans', '', 6);

        $this->MultiCell(6, 6, "<b>$number</b>", 'TL', "C", 0, '', '', '', true, false, true, 'M');
        $this->MultiCell(89, 6, "$text", 'TR', 'L', 0, 0, '', '', true);
        $this->SetFont('dejavusans', '', 10);
    }

    public function fillPriceList($header, $productData)
    {
        $w = array(70, 110, 28);

        $this->SetFont('dejavusans', 'B', 12);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();
        $rowH = 6;


        $this->SetFont('dejavusans', '', 10);
        $last_inserted_id_category = 0;
        for ($i = 0; $i < count($productData); $i++) {

            if ($productData[$i]['price'] < 10) continue;


            if ($productData[$i]['id_category'] != $last_inserted_id_category) {

                $this->Cell($w[0], $rowH, $productData[$i]['category_name'], 'LR', 0, 'L');
                $this->Cell($w[1], $rowH, "", 'LR', 0, 'L');
                $this->Cell($w[2], $rowH, "", 'LR', 0, 'L');
                $this->Ln();
            }

            $this->Cell($w[0], $rowH, "", 'LR', 0, 'L');
            $this->Cell($w[1], $rowH, $productData[$i]['final_product_name'], 'LR', 0, 'L');
            $this->Cell($w[2], $rowH, $productData[$i]['price'] . " €", 'LR', 0, 'C');
            $this->Ln();
            $last_inserted_id_category = $productData[$i]['id_category'];
        }

        $this->Cell(array_sum($w), 0, '', 'T');
    }

    public function fillStockList($header, $productData)
    {
        $w = array(120, 40, 48);

        $this->SetFont('dejavusans', 'B', 12);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();
        $rowH = 6;


        $this->SetFont('dejavusans', '', 10);
        $total_value_of_stock = 0.00;

        foreach ($productData as $productItem) {
            $mark_random = "";
            if (isset($productItem['random'])) {
                $mark_random = "*";
            }

            $this->Cell($w[0], $rowH, $productItem['name'] . " " . $mark_random, 'LR', 0, 'L');
            $this->Cell($w[1], $rowH, $productItem['count_in_stock'], 'LR', 0, 'C');
            $this->Cell($w[2], $rowH, $productItem['total_material_cost'] . " zl", 'LR', 0, 'C');
            $this->Ln();
            $total_value_of_stock += $productItem['total_material_cost'];
        }

        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln(0);


        $this->Cell($w[0], $rowH, "", 'T', 0, 'L');
        $this->Cell($w[1], $rowH, "Suma:", 'TLB', 0, 'C');
        $this->Cell($w[2], $rowH, number_format($total_value_of_stock, 2) . " zl", 1, 0, 'C');
        $this->Ln();
    }

    public function fillWzData($header, $secHeader, $data)
    {
        $w = array(30, 70, 20, 20, 20, 20, 20);

        $this->SetFont('dejavusans', '', 8);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 6, $header[$i], 'TLR', 0, 'C');
        }
        $this->Ln();
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 6, $secHeader[$i], 'BLR', 0, 'C');
        }
        $this->Ln();
        $rowH = 4;

        $this->setCellPaddings(1, 0.5);
        $this->SetFont('dejavusans', '', 7);
        foreach ($data as $productItem) {
            // Oblicz wysokość wiersza na podstawie nazwy produktu
            $nameLines = $this->getNumLines($productItem['name'], $w[1]);
            $rowH = max(4, $nameLines * 3.5);

            $x = $this->GetX();
            $y = $this->GetY();

            $this->Cell($w[0], $rowH, $productItem['id_product'], 'LR', 0, 'L');
            $this->MultiCell($w[1], $rowH, $productItem['name'], 'LR', 'L', false, 0);
            $this->Cell($w[2], $rowH, $productItem['code'], 'LR', 0, 'L');
            $this->Cell($w[3], $rowH, $productItem['quantity'], 'LR', 0, 'R');
            $this->Cell($w[4], $rowH, $productItem['weight_unit'], 'LR', 0, 'R');
            $this->Cell($w[5], $rowH, $productItem['weight_total'], 'LR', 0, 'R');
            $this->Cell($w[6], $rowH, $productItem['ppc'], 'LR', 0, 'R');
            $this->Ln();
        }

        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
        $this->Ln();
    }

    public function fillTableToProforma($header_pl, $header_be, $product_data)
    {

        $this->SetFont('dejavusans', 'B', 12);
        $w = array(15, 50, 13, 10, 20, 20, 10, 14, 13, 12, 12, 8, 11);

        $this->SetFont('dejavusans', '', 6);
        $num_headers = count($header_pl);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $header_pl[$i], 'TLR', 0, 'C');
        }
        $this->Ln();
        $num_headers = count($header_be);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $header_be[$i], 'LRB', 0, 'C');
        }
        $this->Ln();
        $rowH = 3;


        $this->SetFont('dejavusans', '', 6);

        foreach ($product_data as $item) {
            $this->Cell($w[0], $rowH, $item['id_product'], 'LR', 0, 'L');
            $this->Cell($w[1], $rowH, $item['product_name'], 'LR', 0, 'L');
            $this->Cell($w[2], $rowH, $item['code_stat'], 'LR', 0, 'L');
            $this->Cell($w[3], $rowH, $item['quantity'], 'LR', 0, 'R');
            $this->Cell($w[4], $rowH, "{$item['price_per_carton']} €", 'LR', 0, 'R');
            $this->Cell($w[5], $rowH, "{$item['total_price_net']} €", 'LR', 0, 'R');
            $this->Cell($w[6], $rowH, $item['tva'], 'LR', 0, 'R');
            $this->Cell($w[7], $rowH, "{$item['price_brut']} €", 'LR', 0, 'R');
            $this->Cell($w[8], $rowH, "{$item['tva_perc']}%", 'LR', 0, 'R');
            $this->Cell($w[9], $rowH, "{$item['single_carton_weight_kg']} kg", 'LR', 0, 'R');
            $this->Cell($w[10], $rowH, "{$item['total_weight_kg']} kg", 'LR', 0, 'R');
            $this->Cell($w[11], $rowH, $item['ppc'], 'LR', 0, 'R');
            $this->Cell($w[12], $rowH, "{$item['price_per_piece']} €", 'LR', 0, 'R');
            $this->Ln();
        }
        $this->Cell(array_sum($w), 0, '', 'T');
    }

    public function FillTableToMissingProductsInOrder($header_first, $header_sec, $product_data)
    {
        $this->SetFont('dejavusans', 'B', 12);
        $w = array(20, 58, 34, 34, 34);

        $this->SetFont('dejavusans', '', 8);
        $this->Cell(20, 5, $header_first[0], 'TLR', 0, 'C');
        $this->Cell(58, 5, $header_first[1], 'TLR', 0, 'C');
        $this->Cell(34, 5, $header_first[2], 'TLR', 0, 'C');
        $this->Cell(34, 5, $header_first[3], 'TLR', 0, 'C');
        $this->Cell(34, 5, $header_first[4], 'TLR', 0, 'C');
        $this->Ln();
        $this->Cell(20, 5, $header_sec[0], 'TLRB', 0, 'C');
        $this->Cell(58, 5, $header_sec[1], 'TLRB', 0, 'C');
        $this->Cell(17, 5, $header_sec[2], 'TLRB', 0, 'C');
        $this->Cell(17, 5, $header_sec[3], 'TLRB', 0, 'C');
        $this->Cell(17, 5, $header_sec[4], 'TLRB', 0, 'C');
        $this->Cell(17, 5, $header_sec[5], 'TLRB', 0, 'C');
        $this->Cell(34, 5, $header_sec[6], 'TLRB', 0, 'C');
        $this->Ln();

        $rowH = 4;


        $this->SetFont('dejavusans', '', 8);

        $totalValCents = 0;
        $totalOrderedCents = 0;
        $totalInvoicedCents = 0;

        foreach ($product_data as $item) {
            $orderCents = array_key_exists('ordered_total_amount', $item) && $item['ordered_total_amount'] !== null
                ? \App\Services\Document\DifferenceAmountCalculator::toCents($item['ordered_total_amount'])
                : \App\Services\Document\DifferenceAmountCalculator::toCents($item['price_during_ordering']) * (int) $item['quantity_ordered'];
            $invoiceCents = array_key_exists('invoiced_total_amount', $item) && $item['invoiced_total_amount'] !== null
                ? \App\Services\Document\DifferenceAmountCalculator::toCents($item['invoiced_total_amount'])
                : \App\Services\Document\DifferenceAmountCalculator::toCents($item['price_during_invoicing']) * (int) $item['invoiced_quantity'];
            $differenceCents = $invoiceCents - $orderCents;

            $totalValCents += $differenceCents;
            if ($differenceCents === 0) continue;

            $totalOrderedCents += $orderCents;
            $totalInvoicedCents += $invoiceCents;
            $total_diff = \App\Services\Document\DifferenceAmountCalculator::formatCents($differenceCents);

            // Dynamiczna wysokość wiersza na podstawie nazwy produktu
            $nameLines = $this->getNumLines($item['product_name'], 58);
            $rowH = max(4, $nameLines * 3.5);

            $this->Cell(20, $rowH, $item['id_product'], 'LR', 0, 'L');
            $this->MultiCell(58, $rowH, $item['product_name'], 'LR', 'L', false, 0);
            $this->Cell(17, $rowH, $item['quantity_ordered'], 'LR', 0, 'C');
            $this->Cell(17, $rowH, $item['price_during_ordering'], 'LR', 0, 'C');
            $this->Cell(17, $rowH, $item['invoiced_quantity'], 'LR', 0, 'C');
            $this->Cell(17, $rowH, $item['price_during_invoicing'], 'LR', 0, 'C');
            $this->Cell(34, $rowH, $total_diff, 'LR', 0, 'C');
            $this->Ln();
        }
        $this->Cell(20, $rowH, "", 1, 0, 'L');
        $this->Cell(58, $rowH, "", 1, 0, 'L');
        $this->Cell(17, $rowH, "Total:", 1, 0, 'C');
        $this->Cell(17, $rowH, \App\Services\Document\DifferenceAmountCalculator::formatCents($totalOrderedCents), 1, 0, 'C');
        $this->Cell(17, $rowH, "Total:", 1, 0, 'C');
        $this->Cell(17, $rowH, \App\Services\Document\DifferenceAmountCalculator::formatCents($totalInvoicedCents), 1, 0, 'C');
        $this->Cell(34, $rowH, \App\Services\Document\DifferenceAmountCalculator::formatCents($totalValCents), 1, 0, 'C');
        $this->Ln();


        return $totalValCents;
    }

    public function fillWdtData($header, $secHeader, $data)
    {
        $this->SetFont('dejavusans', 'B', 12);
        $w = array(6, 12, 50, 12, 8, 17, 16, 7, 13, 13, 12, 15, 8, 11);

        $this->SetFont('dejavusans', '', 5);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $header[$i], 'TLR', 0, 'C');
        }
        $this->Ln();
        $num_headers = count($secHeader);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $secHeader[$i], 'LRB', 0, 'C');
        }
        $this->Ln();

        $this->SetFont('dejavusans', '', 6);
        // Data
        $curPaletteNum = 0;
        foreach ((array)$data as $item) {
            // Oblicz wysokość wiersza na podstawie nazwy produktu
            $nameLines = $this->getNumLines($item['name'], $w[2]);
            $rowH = max(3, $nameLines * 2.8);

            if ($curPaletteNum != $item['id_palette']) {
                $this->SetFillColor(236, 236, 236); // Grey
                $this->Cell($w[0], $rowH, $item['id_palette'], 'LR', 0, 'L', 1);
                $this->Cell($w[1], $rowH, "", 'LR', 0, 'L', 1);
                $this->Cell($w[2], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[3], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[4], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[5], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[6], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[7], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[8], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[9], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[10], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[11], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[12], $rowH, "", 'LR', 0, 'C', 1);
                $this->Cell($w[13], $rowH, "", 'LR', 0, 'C', 1);
                $this->Ln();
            }

            $this->Cell($w[0], $rowH, "", 'LR', 0, 'L');
            $this->Cell($w[1], $rowH, $item['id_product'], 'LR', 0, 'L');
            $this->MultiCell($w[2], $rowH, $item['name'], 'LR', 'L', false, 0);
            $this->Cell($w[3], $rowH, $item['code'], 'LR', 0, 'L');
            $this->Cell($w[4], $rowH, $item['quantity'], 'LR', 0, 'R');
            $this->Cell($w[5], $rowH, $item['price_carton'] . " €", 'LR', 0, 'R');
            $this->Cell($w[6], $rowH, $item['total_net'] . " €", 'LR', 0, 'R');
            $this->Cell($w[7], $rowH, "0", 'LR', 0, 'R');
            $this->Cell($w[8], $rowH, "- €", 'LR', 0, 'R');
            $this->Cell($w[9], $rowH, "0%", 'LR', 0, 'R');
            $this->Cell($w[10], $rowH, $item['weight_carton'] . " kg", 'LR', 0, 'R');
            $this->Cell($w[11], $rowH, $item['weight_carton_total'] . " kg", 'LR', 0, 'R');
            $this->Cell($w[12], $rowH, $item['ppc'], 'LR', 0, 'R');
            $this->Cell($w[13], $rowH, $item['price_per_piece'] . " €", 'LR', 0, 'R');
            $this->Ln();
            $curPaletteNum = $item['id_palette'];
        }
        $this->Cell(array_sum($w), 0, '', 'T');
    }

    public function fillInvoiceData($header, $secHeader, $data)
    {
        $this->SetFont('dejavusans', 'B', 12);
        $w = array(15, 50, 10, 15, 15, 10, 15, 15, 15, 15, 10, 15);

        $this->SetFont('dejavusans', '', 5);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $header[$i], 'TLR', 0, 'C');
        }
        $this->Ln();
        $num_headers = count($secHeader);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 4, $secHeader[$i], 'LRB', 0, 'C');
        }
        $this->Ln();

        $this->SetFont('dejavusans', '', 6);
        // Data
        $rowH = 3;
        foreach ((array)$data as $item) {
            if ($item['ppc'] == 0) {
                $price_per_piece = 0;
            } else {
                $price_per_piece = number_format(($item['single_price'] / $item['ppc']), 2, '.', '');
            }
            $tax_val = number_format(($item['single_price'] * $item['quantity']) * ($item['tva_perc'] / 100), 2, '.', '');
            $total_net = number_format(($item['single_price'] * $item['quantity']), 2, '.', '');
            if ($tax_val == 0) {
                $total_brut = "-";
            } else {
                $total_brut = ($item['single_price'] * $item['quantity']) + ($item['single_price'] * $item['quantity']) * ($item['tva_perc'] / 100);
            }
            $this->Cell($w[0], $rowH, $item['id_product'], 'LR', 0, 'L');
            $this->Cell($w[1], $rowH, $item['product_name'], 'LR', 0, 'L');
            $this->Cell($w[2], $rowH, $item['quantity'], 'LR', 0, 'R');
            $this->Cell($w[3], $rowH, $item['single_price'] . " €", 'LR', 0, 'R');
            $this->Cell($w[4], $rowH, $total_net . " €", 'LR', 0, 'R');
            $this->Cell($w[5], $rowH, $tax_val, 'LR', 0, 'R');
            $this->Cell($w[6], $rowH, $total_brut . " €", 'LR', 0, 'R');
            $this->Cell($w[7], $rowH, $item['tva_perc'] . "%", 'LR', 0, 'R');
            $this->Cell($w[8], $rowH, $item['single_weight'] . " kg", 'LR', 0, 'R');
            $this->Cell($w[9], $rowH, ($item['quantity'] * $item['single_weight']) . " kg", 'LR', 0, 'R');
            $this->Cell($w[10], $rowH, $item['ppc'], 'LR', 0, 'R');
            $this->Cell($w[11], $rowH, $price_per_piece . " €", 'LR', 0, 'R');
            $this->Ln();
        }
        $this->Cell(array_sum($w), 0, '', 'T');
    }

    public function fillMissingRecipe($header, $productData)
    {
        $w = array(76, 114, 10);

        $this->SetFont('dejavusans', 'B', 12);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();
        $rowH = 8;

        $this->SetFont('dejavusans', '', 10);
        $this->SetFillColor(220, 220, 220);
        $iterator = 0;
        foreach ($productData as $productItem) {
            $fillCellFlag = 0;
            if ($iterator % 2 == 0) {
                $fillCellFlag = 1;
            }

            $this->Cell($w[0], $rowH, $productItem['category_name'], 1, 0, 'L', $fillCellFlag);
            $this->Cell($w[1], $rowH, $productItem['product_name'], 1, 0, 'L', $fillCellFlag);
            $this->Cell($w[2], $rowH, "", 1, 0, 'C', $fillCellFlag);
            $this->Ln();
            $iterator++;
        }

        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
    }

    public function fillProductionListForSection($header, $sec_header, $product_data, $stock_minimum_flag, $hide_stock_flag)
    {
        $wHead = array(82, 34, 34, 35, 35, 35, 35);
        $this->SetFont('dejavusans', '', 9);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($wHead[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $w = array(
            $wHead[0],
            ($wHead[1] / 2), ($wHead[1] / 2),
            ($wHead[2] / 2), ($wHead[2] / 2),
            $wHead[3],
            ($wHead[4] / 2), ($wHead[4] / 2),
            ($wHead[5] / 2), ($wHead[5] / 2),
            ($wHead[6] / 2), ($wHead[6] / 2));

        $num_headers = count($sec_header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $sec_header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $rowH = 8;
        $this->SetFont('dejavusans', '', 8);
        $this->SetFillColor(220, 220, 220);
        $iterator = 0;

        foreach ($product_data as $productItem) {
            $fillCellFlag = 0;
            if ($iterator % 2 == 1) {
                $fillCellFlag = 1;
            }

            $this->Cell($w[0], $rowH, $productItem['name'], "LR", 0, 'L', $fillCellFlag);

            //$this->Cell($w[1], $rowH, $productItem->stock_minimum_carton, "LR", 0, 'C', $fillCellFlag);
            //$this->Cell($w[1], $rowH, $productItem->stock_minimum_pcs, "LR", 0, 'C', $fillCellFlag);

            $in_stock_carton = $productItem['in_stock_carton'];
            $in_stock_pcs = $productItem['in_stock_pcs'];

            if ($hide_stock_flag) {
                $in_stock_carton = "-";
                $in_stock_pcs = "-";
            }

            $this->Cell($w[1], $rowH, $in_stock_carton, "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[2], $rowH, $in_stock_pcs, "LR", 0, 'C', $fillCellFlag);

            $day_1_carton = ($productItem['day_1_carton'] == 0) ? "-" : $productItem['day_1_carton'];
            $day_1_pcs = ($productItem['day_1_pcs'] == 0) ? "-" : $productItem['day_1_pcs'];
            $this->Cell($w[3], $rowH, $day_1_carton, "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[4], $rowH, $day_1_pcs, "LR", 0, 'C', $fillCellFlag);

            if ($stock_minimum_flag) {
                $to_produce = ($productItem['in_stock_pcs'] - $productItem['day_1_pcs'] - $productItem['stock_minimum_pcs']);
            } else {
                $to_produce = ($productItem['in_stock_pcs'] - $productItem['day_1_pcs']);
            }
            $to_produce = ($to_produce >= 0) ? "-" : abs($to_produce);


            $this->Cell($w[5], $rowH, $to_produce, "LR", 0, 'C', $fillCellFlag);

            $day_2_carton = ($productItem['day_2_carton'] == 0) ? "-" : $productItem['day_2_carton'];
            $day_2_pcs = ($productItem['day_2_pcs'] == 0) ? "-" : $productItem['day_2_pcs'];
            $this->Cell($w[6], $rowH, $day_2_carton, "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[7], $rowH, $day_2_pcs, "LR", 0, 'C', $fillCellFlag);

            $day_3_carton = ($productItem['day_3_carton'] == 0) ? "-" : $productItem['day_3_carton'];
            $day_3_pcs = ($productItem['day_3_pcs'] == 0) ? "-" : $productItem['day_3_pcs'];
            $this->Cell($w[8], $rowH, $day_3_carton, "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[9], $rowH, $day_3_pcs, "LR", 0, 'C', $fillCellFlag);

            $day_4_carton = ($productItem['day_4_carton'] == 0) ? "-" : $productItem['day_4_carton'];
            $day_4_pcs = ($productItem['day_4_pcs'] == 0) ? "-" : $productItem['day_4_pcs'];
            $this->Cell($w[10], $rowH, $day_4_carton, "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[11], $rowH, $day_4_pcs, "LR", 0, 'C', $fillCellFlag);


            $this->Ln();
            $iterator++;
        }

        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
    }

    public function fillComplaintProductionListForSection($header, $sec_header, $product_data)
    {
        $wHead = array(100, 100);
        $this->SetFont('dejavusans', '', 9);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($wHead[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $w = array(
            $wHead[0],
            ($wHead[1] / 2), ($wHead[1] / 2));

        $num_headers = count($sec_header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $sec_header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $rowH = 8;
        $this->SetFont('dejavusans', '', 8);
        $this->SetFillColor(220, 220, 220);
        $iterator = 0;

        foreach ($product_data as $productItem) {
            $fillCellFlag = 0;
            if ($iterator % 2 == 1) {
                $fillCellFlag = 1;
            }

            $this->Cell($w[0], $rowH, $productItem['name'], "LR", 0, 'L', $fillCellFlag);

            //$this->Cell($w[1], $rowH, $productItem->stock_minimum_carton, "LR", 0, 'C', $fillCellFlag);
            //$this->Cell($w[1], $rowH, $productItem->stock_minimum_pcs, "LR", 0, 'C', $fillCellFlag);


            $this->Cell($w[1], $rowH, $productItem['complaint_carton'], "LR", 0, 'C', $fillCellFlag);
            $this->Cell($w[2], $rowH, $productItem['complaint_pcs'], "LR", 0, 'C', $fillCellFlag);

            $this->Ln();
            $iterator++;
        }

        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
    }

    public function fillMaterialRequirementList($header, $elements)
    {
        $w = array(80, 60, 50);
        $this->SetFont('dejavusans', '', 9);
        $num_headers = count($header);
        for ($i = 0; $i < $num_headers; ++$i) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $rowH = 8;
        $this->SetFont('dejavusans', '', 8);
        $this->SetFillColor(220, 220, 220);
        $iterator = 0;

        $total_expected_price = 0;

        foreach ($elements as $product) {

            $unit_name = $product['unit_name'];


            $expected_total_price = round($product['required_quantity'] * $product['unit_price'], 2);

            $this->Cell($w[0], $rowH, $product['name'], "LR", 0, 'L');

            if ($product['id_unit'] == 3) {

                $required_quantity_large = round($product['required_quantity'] / 1000, 2);
                $unit_name_large = "kg";

                $this->Cell($w[1], $rowH, "{$required_quantity_large}{$unit_name_large} ({$product['required_quantity']} {$product['unit_name']})", "LR", 0, 'L');


            } else if ($product['id_unit'] == 4) {
                $required_quantity_large = round($product['required_quantity'] / 1000, 2);
                $unit_name_large = "L";

                $this->Cell($w[1], $rowH, "{$required_quantity_large}{$unit_name_large} ({$product['required_quantity']} {$product['unit_name']})", "LR", 0, 'L');

            } else if ($product['id_unit'] == 5) {

                $this->Cell($w[1], $rowH, "{$product['required_quantity']} {$product['unit_name']}", "LR", 0, 'L');
            }


            $this->Cell($w[2], $rowH, $expected_total_price . "zł", "LR", 0, 'L');

            $total_expected_price += $expected_total_price;

            $this->Ln();
            $iterator++;
        }
        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln(0);

        $this->Cell($w[0], $rowH, "", "LR", 0, 'L');
        $this->Cell($w[1], $rowH, "", "LR", 0, 'L');
        $this->Cell($w[2], $rowH, $total_expected_price . "zł", "LR", 0, 'L');
        $this->Ln();

        $this->Cell(array_sum($w), 0, '', 'T');

        $this->Ln();
    }

}
