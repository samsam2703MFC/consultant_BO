<?php

namespace App\Services\Document;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Document\Proforma\ProformaProductRepository;
use App\Repositories\Document\Proforma\ProformaRepository;
use App\Services\Utils\TCPDFService;

class ProformaService implements IDocument
{
    private ProformaRepository $proformaRepository;
    private ProformaProductRepository $proformaProductRepository;
    private TransactionManager $transactionManager;
    private OrderRepository $orderRepository;
    private ErpCompanyDataRepository $erpCompanyDataRepository;
    private ClientRepository $clientRepository;

    private array $proformaStatuses = [
        "issued",
        "paid",
        "cancelled"
    ];

    public function __construct(
        ProformaRepository        $proformaRepository,
        ProformaProductRepository $proformaProductRepository,
        TransactionManager        $transactionManager,
        OrderRepository           $orderRepository,
        ErpCompanyDataRepository  $erpCompanyDataRepository,
        ClientRepository          $clientRepository
    )
    {
        $this->proformaRepository = $proformaRepository;
        $this->proformaProductRepository = $proformaProductRepository;
        $this->transactionManager = $transactionManager;
        $this->orderRepository = $orderRepository;
        $this->erpCompanyDataRepository = $erpCompanyDataRepository;
        $this->clientRepository = $clientRepository;
    }

    public function getGenerated()
    {
        return $this->proformaRepository->getGenerated();
    }

    private function getUUIDv4()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // wersja 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // wariant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function insertFromOrder($idOrder)
    {
        return $this->transactionManager->execute(function () use ($idOrder) {
            $order = $this->orderRepository->getById($idOrder);
            if (is_null($order)) {
                throw new CustomException("ORDER_NOT_FOUND", 'errors', 404);
            }

            if (!is_null($order['id_proforma'])) {
                throw new CustomException("PROFORMA_ALREADY_GENERATED", 'errors', 500);
            }

            $proformaNumber = $this->proformaRepository->getProformaNum()[0]['inv_num'];
            $clientData = $this->clientRepository->getById($order['id_customer']);
            $erpCompanyData = $this->erpCompanyDataRepository->first(['id' => 1]);

            $uuid = $this->getUUIDv4();

            $resInsert = $this->proformaRepository->insert([
                "doc_num" => $proformaNumber,
                "id_customer" => $clientData['id_customer'],
                "consignor_name" => $erpCompanyData['proforma_company_name'],
                "consignor_address_1" => $erpCompanyData['proforma_company_address_1'],
                "consignor_address_2" => $erpCompanyData['proforma_company_address_2'],
                "consignor_nip" => $erpCompanyData['proforma_company_nip'],
                "consignee_name" => $clientData['company'],
                "consignee_address_1" => $clientData['fac_street'] . " " . $clientData['fac_street_num'],
                "consignee_address_2" => $clientData['fac_zip'] . " " . $clientData['fac_town'],
                "consignee_nip" => $clientData['tax_number'],
                "consignor_bank" => $erpCompanyData['proforma_company_bank'],
                "consignor_swift" => $erpCompanyData['proforma_company_swift'],
                "established_date" => date("Y/m/d"),
                "status" => "issued",
                "uuid" => $uuid
            ]);
            if (!$resInsert) {
                throw new CustomException("PROFORMA_INSERTION_FAILED", 'errors', 500);
            }


            $proformaInsertedId = $resInsert;

            $isFreebie = !empty($order['is_freebie']);
            $resInsertProducts = $this->proformaProductRepository->insertFromOrder($proformaInsertedId, $idOrder, $isFreebie);
            if (!$resInsertProducts) {
                throw new CustomException("PROFORMA_PRODUCTS_INSERTION_FAILED", 'errors', 500);
            }

            $resUpdateOrder = $this->orderRepository->update($idOrder, ["id_proforma" => $proformaInsertedId]);
            if (!$resUpdateOrder) {
                throw new CustomException("ORDER_UPDATE_FAILED", 'errors', 500);
            }

            return $proformaInsertedId;
        });
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);

        $pdf->AddPage();
        $pdf->setMargins(1, 1, 1);

        $pdf->Image(__DIR__ . "/../../../public/resources/images/logo.jpg", 180, 5, 19, 26, 'JPG', '', '', false, 300, '', false, false, 0);


#region right data
        $pdf->SetFont('dejavusans', '', 11);
// right side over table


        $pdf->writeHTMLCell(91, 6, 5, 19, "Date: <b>{$data['proformaData']['established_date']}</b>", 0, 'L', 0, true, 'L', true, true, 15, 'M');

        $pdf->writeHTMLCell(91, 6, 5, 25, "PROFORMA NUM: <b>{$data['proformaData']['doc_num']}</b>", 0, 'L', 0, true, 'L', true);


#region left PL data
// left side over table
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->writeHTMLCell(90, 5, 5, 45, "Sprzedawca", "B", 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->writeHTMLCell(90, 5, 5, 51, "{$data['proformaData']['consignor_name']}", 0, 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTMLCell(90, 7, 5, 63, "{$data['proformaData']['consignor_address_1']}<br>{$data['proformaData']['consignor_address_2']}", 0, 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->writeHTMLCell(90, 2, 5, 73, "{$data['proformaData']['consignor_nip']}", 0, 'L', 0, true, 'L', true);
// end left side over table
#endregion


        $pdf->SetFont('dejavusans', '', 8);
        $pdf->writeHTMLCell(90, 5, 110, 45, "Nabywca", "B", 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->writeHTMLCell(90, 5, 110, 51, "{$data['proformaData']['consignee_name']}", 0, 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTMLCell(90, 7, 110, 63, "{$data['proformaData']['consignee_address_1']} <br>{$data['proformaData']['consignee_address_2']}", 0, 'L', 0, true, 'L', true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->writeHTMLCell(90, 2, 110, 73, "{$data['proformaData']['consignee_nip']}", 0, 'L', 0, true, 'L', true);

//$pdf->SetFont('dejavusans', '', 5);
//$pdf->writeHTMLCell(30, 2,118,72, $customerData['vat'], 0,'L',0, true,0,true);
// end right side over table
#endregion

        $pdf->Ln(10);


#region table

        $headerPL = array(
            "Kod Towaru",
            "Opis towaru",
            "PKWiU",
            "Ilość",
            "Cena/kart netto",
            "Wartość netto",
            "VAT",
            "Wart./brutto",
            "Stawka VAT",
            "Waga op.",
            "Waga",
            "Il/Krt",
            "Cena/szt"
        );
        $headerBE = array(
            "Ref. Article",
            "Description",
            "Code Stat.",
            "Quant.",
            "Prix carton net",
            "Total Net",
            "TVA",
            "Prix brut",
            "Taux TVA",
            "Poid cart",
            "Poid Brut",
            "Qt/Crt",
            "Prix/unit"
        );

#endregion


        $pdf->FillTableToProforma($headerPL, $headerBE, $data['proformaProductData']);

        $sum_price_net = 0;
        $sum_price_brut = 0;
        $sum_weight = 0;
        foreach ($data['proformaProductData'] as $product_item) {
            $sum_price_net = $sum_price_net + $product_item['total_price_net'];

            if (!is_null($product_item['price_brut'])) {
                $sum_price_brut = $sum_price_brut + $product_item['price_brut'];
            }

            $sum_weight = $sum_weight + $product_item['total_weight_kg'];
        }

        $tva_sum = $sum_price_brut - $sum_price_net;

        $pdf->setMargins(5, 5, 5);

        $pdf->Ln(10);
//pomocnicza komórka bo grupuje wszystkie poniższe dane w jednej linii
        $pdf->MultiCell(60, 5, "", 0, 'L', 0, 1, '', '', true);
        $pdf->SetFont('helvetica', 'I', 8);

        $pdf->MultiCell(60, 5, "Waga lacznie:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $sum_weight . " kg", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();


        $pdf->MultiCell(60, 5, "Paiement Pro Forma", 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Compte en Banque:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(100, 5, $data['proformaData']['consignor_bank'], 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Code BIC Swift:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(80, 5, $data['proformaData']['consignor_swift'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();


        $formatted_net = number_format($sum_price_net, "2");
        $formatted_vat = number_format($tva_sum, "2");
        $formatted_brut = number_format($sum_price_net, "2");

        $pdf->SetFont('helvetica', 'I', 9);

        $pdf->MultiCell(24, 4, "Net", '', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(55, 4, " {$formatted_net} €", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

//        $pdf->MultiCell(24, 4, "TVA", '', 'L', 0, 0, '', '', true);
//        $pdf->MultiCell(55, 4, " {$formatted_vat} €", 0, 'L', 0, 1, '', '', true);
//        $pdf->Ln();

        $pdf->MultiCell(24, 4, "Total", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(55, 4, " {$formatted_brut} €", 0, 'L', 0, 1, '', '', true);
        $pdf->Ln(15);


        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->MultiCell(200, 6, "Exonération de TVA, article 262 ter, I du Code Général des Impôts, et autoliquidation.", 0, 'C', 0, 0, '', '', true);

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $proformaData = $this->proformaRepository->first(['id' => $id]);
        if (!$proformaData) {
            throw new CustomException("PROFORMA_NOT_FOUND", 'errors', 404);
        }

        $proformaProductData = $this->proformaProductRepository->getByIdProforma($id);
        if (!$proformaProductData) {
            throw new CustomException("PROFORMA_PRODUCTS_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'proformaData' => $proformaData,
            'proformaProductData' => $proformaProductData]);

        $clientName = htmlspecialchars($proformaData['consignee_name']);

        $clientName = str_replace('&', '_', $clientName);
        $clientName = preg_replace('/[^A-Za-z0-9\-]/', '', $clientName);


        $filename = preg_replace('/[^\w\-]/u', '_', $clientName . '_' . $proformaData['doc_num'] . '.pdf');

        return ['file_string' => $pdf, "filename" => "PROFORMA_" . $filename];
    }

    public function generateDocumentPDF(int $id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }


    public function getWithProducts($idProforma)
    {
        $proforma = $this->proformaRepository->first(['id' => $idProforma]);
        $proforma['products'] = $this->proformaProductRepository->getByIdProforma($idProforma);

        return $proforma;
    }

    public function update($id, $data)
    {
        $proforma = $this->proformaRepository->first(['id' => $id]);
        if (!$proforma) {
            throw new CustomException("PROFORMA_NOT_FOUND", 'errors', 404);
        }
        if (ProformaSnapshotPolicy::isPaid($proforma)) {
            throw new CustomException("PAID_PROFORMA_IMMUTABLE", 'errors', 422);
        }
        if (strtolower(trim((string) ($proforma['status'] ?? ''))) === 'cancelled') {
            throw new CustomException("CANCELLED_PROFORMA_IMMUTABLE", 'errors', 422);
        }
        return $this->proformaRepository->update($id, $data);
    }

    /** Freezes the existing document snapshot. It must be used by payment integrations. */
    public function markPaid(int $id): bool
    {
        return $this->transactionManager->execute(function () use ($id) {
            $proforma = $this->proformaRepository->first(['id' => $id]);
            if (!$proforma) {
                throw new CustomException("PROFORMA_NOT_FOUND", 'errors', 404);
            }
            if (ProformaSnapshotPolicy::isPaid($proforma)) {
                return true;
            }
            if (strtolower(trim((string) ($proforma['status'] ?? ''))) === 'cancelled') {
                throw new CustomException("CANCELLED_PROFORMA_CANNOT_BE_PAID", 'errors', 422);
            }
            if (empty($this->proformaProductRepository->getByIdProforma($id))) {
                throw new CustomException("PROFORMA_PRODUCTS_NOT_FOUND", 'errors', 422);
            }
            return (bool) $this->proformaRepository->update($id, ['status' => 'paid']);
        });
    }

    public function getById($id)
    {
        return $this->proformaRepository->first(['id' => $id]);
    }


    public function buildDocumentFromUUID(TCPDFService $pdf, string $uuid): array
    {
        $proformaData = $this->proformaRepository->first(['uuid' => $uuid]);
        if (!$proformaData) {
            throw new CustomException("PROFORMA_NOT_FOUND", 'errors', 404);
        }

        $proformaProductData = $this->proformaProductRepository->getByIdProforma($proformaData['id']);
        if (!$proformaProductData) {
            throw new CustomException("PROFORMA_PRODUCTS_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'proformaData' => $proformaData,
            'proformaProductData' => $proformaProductData]);

        return ['file_string' => $pdf, "filename" => 'PROFORMA_' . $proformaData['doc_num'] . '.pdf'];
    }

    public function generateDocumentPDFBytesByUuid(string $uuid): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromUUID($pdf, $uuid);

        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }

}
