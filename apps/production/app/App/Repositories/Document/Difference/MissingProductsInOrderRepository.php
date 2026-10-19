<?php

namespace App\Repositories\Document\Difference;
use App\Core\Helpers\BaseRepository;
use MYPDFModel;

/**
 * User class
 */
class MissingProductsInOrderRepository extends BaseRepository
{

    protected string $table = 'carrier_vehicle';

    protected $allowedColumns = [
        'id_carrier',
        'regis_num',
        'driver_name',
        'driver_surname',
        'driver_phone',
        'driver_email'
    ];

    public function getPDF($data)
    {
        $data['company_name'] = "Max & Sandra";
        $data['product_data'] = [];
        $data['total_value'] = -123.00;
        $data['wdt_num'] = 20234502;

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setMargins(15, 5, 15);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();
        $pdf->Image(ROOT . "/resources/images/logo.jpg", 180, 5, 19, 26, 'JPG', '', '', false, 300, '', false, false, 0);

        $border = 0;

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->writeHTMLCell(140, 6, 15, 32, "Bonjour " . $data['company_name'], $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, 45, "Nous avons récemment pris connaissance des écarts entre la commande que nous avons reçue et la facture correspondante. ", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 12, 15, 58, "Nous comprenons que des erreurs peuvent survenir, mais nous tenons à régulariser la situation.", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 6, 15, 71, "Voici un récapitulatif des différences entre la commande et la facture :", $border, 'L', 0, true, 'L', true, true, 15, 'M');

        $pdf->Ln(5);
        $header_first = array(
            "",
            "",
            "Commandée",
            "Facturée",
            ""
        );

        $header_sec = array(
            "Num.",
            "Article",
            "Quantité",
            "Prix",
            "Quantité",
            "Prix",
            "Différence"
        );

        $pdf->FillTableToMissingProductsInOrder($header_first, $header_sec, $data['product_data']);

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->writeHTMLCell(180, 12, 15, '', "Nous sommes conscients de l'importance de maintenir des transactions transparentes et de la satisfaction de nos clients.", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous remercions de votre compréhension et de votre coopération.", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->Ln(5);

        if ($data['total_value'] > 0) {
            $pdf->writeHTMLCell(180, 6, 15, '', "Nou vous remboursons le montant suivant : {$data['total_value']} €  pour que le montant payé en acompte soit adéquat avec le montant de la facture {$data['wdt_num']}", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        } else {
            $data['total_value'] = -$data['total_value'];
            $pdf->writeHTMLCell(180, 6, 15, '', "Nous vous demandons de régler la différences de {$data['total_value']} € avec la mention Facture : <span style='background-color: #0dcaf0'>{$data['wdt_num']}</span>", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        }

        $pdf->Ln(10);
        $pdf->writeHTMLCell(180, 12, 15, '', "Cordialement,", $border, 'L', 0, true, 'L', true, true, 15, 'M');
        $pdf->writeHTMLCell(180, 12, 15, '', "SLFOOD", $border, 'L', 0, true, 'L', true, true, 15, 'M');


        $pdf->Output('ssss.pdf', 'I');
    }


}