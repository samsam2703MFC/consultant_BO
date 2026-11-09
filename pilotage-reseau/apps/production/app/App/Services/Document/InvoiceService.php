<?php

namespace App\Services\Document;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Document\Invoice\InvoiceProductRepository;
use App\Repositories\Document\Invoice\InvoiceRepository;
use App\Services\Utils\DocumentLabels;
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

    /** Korekty kwoty wpisane ręcznie dla pojedynczych faktur (numer => kwota). */
    private const MANUAL_CORRECTIONS = ['FAC-00006/2026' => -115.0];

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $invoice = $data['invoiceData'];
        $lang = $data['lang'] ?? 'fr';
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);

        $lines = [];
        foreach ((array) $data['invoiceProductData'] as $item) {
            $net = (float) $item['single_price'] * (float) $item['quantity'];
            $vat = (float) $item['tva_perc'] != 0 ? $net * ((float) $item['tva_perc'] / 100) : 0.0;
            $lines[] = ['code' => $item['id_product'], 'name' => $item['product_name'], 'stat' => '', 'quantity' => $item['quantity'],
                'price' => $item['single_price'], 'net' => $net, 'vat_rate' => $item['tva_perc'], 'vat' => $vat, 'gross' => $net + $vat,
                'weight' => (float) $item['quantity'] * (float) $item['single_weight'], 'ppc' => $item['ppc'],
                'price_piece' => (float) $item['ppc'] == 0 ? 0 : (float) $item['single_price'] / (float) $item['ppc']];
        }

        return InvoiceLayout::render($pdf, [
            'title_key' => 'doc_invoice',
            'number' => $invoice['doc_num'],
            'issue_date' => $invoice['invoiced_date'],
            'delivery_date' => $invoice['invoiced_date'],
            'lang' => $lang,
            'company_line' => DocumentLabels::companyLine($invoice),
            'seller' => ['name' => $invoice['consignor_name'],
                'lines' => [$invoice['consignor_address_1'], $invoice['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($invoice['consignor_nip'])]],
            'buyer' => ['name' => $invoice['consignee_name'],
                'lines' => [$invoice['consignee_address_1'], $invoice['consignee_address_2'], 'NIP/TVA: ' . $invoice['consignee_nip']]],
            'payment' => [
                [$P('payment'), $P('payment_proforma')],
                [$P('bank'), (string) $invoice['consignor_bank']],
                [$P('swift'), (string) $invoice['consignor_swift']],
                [$P('payment_reference'), (string) $invoice['doc_num']],
            ],
            'correction' => self::MANUAL_CORRECTIONS[$invoice['doc_num']] ?? 0.0,
        ], $lines);
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
            'invoiceProductData' => $invoiceProductData,
            'lang' => $this->clientRepository->communicationLang((int) $invoiceData['id_customer'])
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