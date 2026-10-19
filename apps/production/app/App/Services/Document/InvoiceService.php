<?php

namespace App\Services\Document;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Document\Invoice\InvoiceProductRepository;
use App\Repositories\Document\Invoice\InvoiceRepository;
use App\Services\Utils\TCPDFService;

class InvoiceService implements IDocument
{
    private InvoiceRepository $invoiceRepository;
    private TransactionManager $transactionManager;
    private ClientRepository $clientRepository;
    private ErpCompanyDataRepository $erpCompanyDataRepository;
    private InvoiceProductRepository $invoiceProductRepository;

    public function __construct(
        InvoiceRepository        $invoiceRepository,
        TransactionManager       $transactionManager,
        ClientRepository         $clientRepository,
        ErpCompanyDataRepository $erpCompanyDataRepository,
        InvoiceProductRepository $invoiceProductRepository
    )
    {
        $this->invoiceRepository = $invoiceRepository;
        $this->transactionManager = $transactionManager;
        $this->clientRepository = $clientRepository;
        $this->erpCompanyDataRepository = $erpCompanyDataRepository;
        $this->invoiceProductRepository = $invoiceProductRepository;
    }

    public function insertNewInvoice($data)
    {
        return $this->transactionManager->execute(function () use ($data) {


            $erp_company_data = $this->erpCompanyDataRepository->first(['id' => 1]);
            $customer_data = $this->clientRepository->first(['id' => $data['id_client']]);

            $resInvNum = $this->invoiceRepository->getInvoiceNumer();
            if (!$resInvNum) {
                throw new CustomException("INVOICE_NUMBER_ERROR", 'errors', 404);
            }
            $invNum = $resInvNum[0]['inv_num'];

            $resInsert = $this->invoiceRepository->insert([
                "doc_num" => $invNum,
                "id_customer" => $customer_data['id'],
                "consignor_name" => $erp_company_data['proforma_company_name'],
                "consignor_address_1" => $erp_company_data['proforma_company_address_1'],
                "consignor_address_2" => $erp_company_data['proforma_company_address_2'],
                "consignor_nip" => $erp_company_data['proforma_company_nip'],
                "consignor_bank" => $erp_company_data['proforma_company_bank'],
                "consignor_swift" => $erp_company_data['proforma_company_swift'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => $customer_data['fac_street'] . " " . $customer_data['fac_street_num'],
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'],
                "consignee_nip" => $customer_data['tax_number'],
                "invoiced_date" => date("Y-m-d"),
            ]);
            if (!$resInsert) {
                throw new CustomException("INVOICE_INSERT_ERROR", 'errors', 404);
            }
            $inserted_invoice_id = $resInsert;

            foreach ($data['invoice_product'] as $product) {
                $product['single_price'] = str_replace(",", ".", $product['single_price']);
                $product['quantity'] = str_replace(",", ".", $product['quantity']);
                $product['ppc'] = str_replace(",", ".", $product['ppc']);

                $resInsertProduct = $this->invoiceProductRepository->insertSelect([
                    "id_document_invoice" => $inserted_invoice_id,
                    "quantity" => $product['quantity'],
                    "single_price" => $product['single_price'],
                    "tax_perc" => $product['tax_perc'],
                    "ppc" => $product['ppc'],
                    "id_product" => $product['id']
                ]);
                if (!$resInsertProduct) {
                    throw new CustomException("INVOICE_PRODUCT_INSERT_ERROR", 'errors', 404);
                }
            }

            return $inserted_invoice_id;
        });
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {

        $pdf->setMargins(5, 5, 5);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        #region left_data
// left top side
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['invoiceData']['consignor_name'], 0, 'L', 0, 0, 10, 25, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(55, 5, $data['invoiceData']['consignor_address_1'] . "\n" . $data['invoiceData']['consignor_address_2'], 0, 'L', 0, 0, 10, 30, true);

        $pdf->MultiCell(15, 5, "<b>NIP:</b>", 0, 'L', 0, 0, 10, 40, true, 0, true);
        $pdf->MultiCell(45, 5, $data['invoiceData']['consignor_nip'], 0, 'L', 0, 0, '', '', true, 0, true);

//end left top side
#endregion

        #region right_data
// right top side
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(15, 5, "Num:", 'B', 'L', 0, 0, 110, 25, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['invoiceData']['doc_num'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->MultiCell(15, 5, "Data:", 'B', 'L', 0, 0, 110, 33, true, 0, false, true, 5, 'M');
        $pdf->MultiCell(60, 5, $data['invoiceData']['invoiced_date'], 'B', 'L', 0, 0, '', '', true, 0, false, true, 5, 'M');

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->MultiCell(85, 5, $data['invoiceData']['consignee_name'], 0, 'L', 0, 0, 110, 45, true, 0, true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(85, 5, $data['invoiceData']['consignee_address_1'], 0, 'L', 0, 0, 110, 50, true, 0, true);
        $pdf->MultiCell(85, 5, $data['invoiceData']['consignee_address_2'], 0, 'L', 0, 0, 110, 55, true, 0, true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 5, "NIP/TVA: " . $data['invoiceData']['consignee_nip'], 0, 'L', 0, 0, 110, 61, true, 0, true);
// end right top side

        #endregion

        $pdf->ln(10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 12);


        $headerPL = array(
            "Kod Towaru",
            "Opis towaru",
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


        $pdf->fillInvoiceData($headerPL, $headerBE, $data['invoiceProductData']);


        $pdf->Ln(10);
//pomocnicza komórka bo grupuje wszystkie poniższe dane w jednej linii
        $pdf->MultiCell(60, 5, "", 0, 'L', 0, 1, '', '', true);

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(60, 5, "Data fakturowania:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(70, 5, $data['invoiceData']['invoiced_date'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();


        $pdf->MultiCell(60, 5, "Paiement Pro Forma", 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Compte en Banque:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(100, 5, $data['invoiceData']['consignor_bank'], 0, 'L', 0, 1, '', '', true);
        $pdf->MultiCell(60, 5, "Code BIC Swift:", 0, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(80, 5, $data['invoiceData']['consignor_swift'], 0, 'L', 0, 1, '', '', true);
        $pdf->Ln();

        $pdf->SetFont('helvetica', 'I', 6);

        $total_net = 0;
        $tax_val = 0;
        foreach ($data['invoiceProductData'] as $productItem) {
            $total_net += ($productItem['quantity'] * $productItem['single_price']);
            if ($productItem['tva_perc'] != 0) {
                $tax_val += (($productItem['single_price'] * $productItem['quantity']) * ($productItem['tva_perc'] / 100));
            }
        }




        if($data['invoiceData']['doc_num'] == "FAC-00006/2026"){
            $total_net = number_format(($total_net + $tax_val), 2);

            if ($tax_val == 0) {
                $tax_val = "-";
            }
            $pdf->MultiCell(24, 3, "Wartosc netto", '', 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $total_net, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
            $pdf->Ln();

            $pdf->MultiCell(24, 3, "Wartosc vat", '', 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $tax_val, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
            $pdf->Ln();

            $cor = $total_net - 115;
            $pdf->MultiCell(24, 3, "Korekta wartosci", '', 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "-115", 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
            $pdf->Ln();

            $pdf->MultiCell(24, 3, "Ogolem", 0, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $cor, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
        } else {
            $total_net = number_format(($total_net + $tax_val), 2);

            if ($tax_val == 0) {
                $tax_val = "-";
            }
            $pdf->MultiCell(24, 3, "Wartosc netto", '', 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $total_net, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
            $pdf->Ln();

            $pdf->MultiCell(24, 3, "Wartosc vat", '', 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $tax_val, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
            $pdf->Ln();

            $pdf->MultiCell(24, 3, "Ogolem", 0, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, $total_net, 1, 'L', 0, 0, '', '', true);
            $pdf->MultiCell(15, 3, "  EUR", 0, 'L', 0, 0, '', '', true);
        }


        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $invoiceData = $this->invoiceRepository->first(['id' => $id]);
        if (!$invoiceData) {
            throw new CustomException("INVOICE_NOT_FOUND", 'errors', 404);
        }

        $invoiceProductData = $this->invoiceProductRepository->getByInvoiceId($id);
        if (!$invoiceProductData) {
            throw new CustomException("INVOICE_PRODUCTS_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'invoiceData' => $invoiceData,
            'invoiceProductData' => $invoiceProductData
        ]);

        return ['file_string' => $pdf, "filename" => 'FAKTURA_' . $invoiceData['doc_num'] . '.pdf'];
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

    public function getExisted()
    {
        return $this->invoiceRepository->getExisted();
    }
}