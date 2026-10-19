<?php
namespace App\Services\Product\Price;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Product\Price\PriceListRepository;
use App\Services\Utils\TCPDFService;

class ClientPriceListService implements IDocument
{
    private PriceListRepository $priceListRepository;
    private ClientRepository $clientRepository;
    
    public function __construct(
        PriceListRepository $priceListRepository,
        ClientRepository $clientRepository
    )
    {
        $this->priceListRepository = $priceListRepository;
        $this->clientRepository = $clientRepository;
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $pdf->AddPage();
        $pdf->customHeader($data['headerData']);
        $pdf->customFooter(["info_1" => ""]);
        $pdf->setMargins(1, 1, 1);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 12);

        $pdf->Ln(10);
        $pdf->fillPriceList($data['header'], $data['productPriceData']);
        $pdf->Ln(7);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $productPriceData = $this->priceListRepository->getPriceListData(['id_customer' => $id]);
        if (empty($productPriceData)) {
            throw new CustomException("PRICE_LIST_DATA_NOT_FOUND", 'errors', 404);
        }

        $clientData = $this->clientRepository->first(['id' => $id]);
        if (empty($clientData)) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 404);
        }


        if ($clientData['default_document_lang'] == 1) {
            $headerData = ["header_title" => "Lista cenowa", "header_subtitle" => $clientData['company']];
            $header = array(
                "Kategoria",
                "Produkt",
                "Cena");
        } else if ($clientData['default_document_lang'] == 2) {
            $headerData = ["header_title" => "Liste de prix", "header_subtitle" => ""];
            $header = array(
                "Catégorie",
                "Produit",
                "Prix");
        } else {
            $headerData = ["header_title" => "Lista cenowa", "header_subtitle" => $clientData['company']];
            $header = array(
                "Kategoria",
                "Produkt",
                "Cena");
        }

        $this->renderDocument($pdf, [
            'headerData' => $headerData,
            'header' => $header,
            'productPriceData' => $productPriceData]);

        return ['file_string' => $pdf, "filename" => 'PRICE_LIST_' . $clientData['company'] . '.pdf'];    }

    public function generateDocumentPDF(int $id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }
}