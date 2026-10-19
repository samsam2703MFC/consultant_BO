<?php
namespace App\Services\Product;

use App\Exceptions\CustomException;
use App\Services\Utils\TCPDFService;

class ProductCardService
{
    private ProductService $productService;

    public function __construct(
        ProductService $productService
    )
    {
        $this->productService = $productService;
    }

    public function getProductCard($idProduct)
    {
        $productData = $this->productService->getById($idProduct);
        if(empty($productData)){
            throw new CustomException("MISSING_PRODUCT", "product", 404);
        }

        $pdf = new TCPDFService('p', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->AddPage();
        $pdf->customHeader(["header_title" => ""]);
        $pdf->customFooter(["info_1" => ""]);
        $pdf->setMargins(1, 1, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->writeHTMLCell(210, 30, 10, 36, $productData['category_name'], 0, 1, 0, true, 'L', true);

        $pdf->SetFont('helvetica', '', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->writeHTMLCell(210, 30, 10, 42, $productData['name'], 0, 1, 0, true, 'L', true);
        $pdf->Image(ROOT . "/resources/images/default_photo.jpg", 10, 52, 70, 35, '', '', '', false, 300, '', false, false, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->writeHTMLCell(110, 15, 90, 52, "<b>Przechowywanie:</b>", 1, 1, 0, true, 'L', true);

        $pdf->SetLineStyle(array('width' => 0.2, 'color' => array(226, 226, 226)));
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->writeHTMLCell(190, 6, 10, 90, "Skladniki", 1, 1, 0, true, 'C', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 12);
        $header = array("#",
            "Nazwa",
            "Ilość",
            "Alergeny");

        //$pdf->FillIngredientTableInProductCard($header, $productData);
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->writeHTMLCell(190, 6, 10, '', "Przygotowanie (5 min)", 1, 1, 0, true, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $header = array("#",
            "Opis",
            "Czas");

        //$pdf->FillIngredientTableInProductCard($header, $productData);
        $pdf->Ln(5);


        $pdf->Image(ROOT . "/resources/images/default_photo.jpg", 20, 240, 70, 35, '', '', '', false, 300, '', false, false, 1);
        $pdf->Image(ROOT . "/resources/images/default_photo.jpg", 120, 240, 70, 35, '', '', '', false, 300, '', false, false, 1);

        $base = $pdf->Output('xxx.pdf', 'S');
        return [
            'filename' => $productData['name'] . '.pdf',
            'file_string' => $base
        ];
    }
    
}