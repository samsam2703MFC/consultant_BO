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
        $L = fn(string $key) => DocumentLabels::get($key);
        $S = fn(string $key) => DocumentLabels::sub($key, $lang);
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);
        $money = fn($v) => DocumentLabels::money($v);

        $pdf->brandPage($L('doc_invoice'), ['ref' => $invoice['doc_num'], 'lang' => $lang, 'company' => DocumentLabels::companyLine($invoice)]);
        $pdf->brandHeader($L('doc_invoice'), [
            [$P('number'), $invoice['doc_num']],
            [$P('invoice_date'), $invoice['invoiced_date']],
        ], $S('doc_invoice'));

        $pdf->brandParties([
            'label' => $P('seller'),
            'name' => $invoice['consignor_name'],
            'lines' => [$invoice['consignor_address_1'], $invoice['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($invoice['consignor_nip'])],
        ], [
            'label' => $P('buyer'),
            'name' => $invoice['consignee_name'],
            'lines' => [$invoice['consignee_address_1'], $invoice['consignee_address_2'], 'NIP/TVA: ' . $invoice['consignee_nip']],
        ]);

        $columns = [
            ['label' => $L('code'), 'sub' => $S('code'), 'w' => 10],
            ['label' => $L('description'), 'sub' => $S('description'), 'w' => 31],
            ['label' => $L('quantity'), 'sub' => $S('quantity'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_carton'), 'sub' => $S('price_carton'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('net_value'), 'sub' => $S('net_value'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('vat_rate'), 'sub' => $S('vat_rate'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('vat_value'), 'sub' => $S('vat_value'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('gross_value'), 'sub' => $S('gross_value'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('weight'), 'sub' => $S('weight'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('ppc'), 'sub' => $S('ppc'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_piece'), 'sub' => $S('price_piece'), 'w' => 10, 'align' => 'R'],
        ];
        $rows = [];
        $net = 0.0;
        $vat = 0.0;
        foreach ((array) $data['invoiceProductData'] as $item) {
            $lineNet = (float) $item['single_price'] * (float) $item['quantity'];
            $lineVat = (float) $item['tva_perc'] != 0 ? $lineNet * ((float) $item['tva_perc'] / 100) : 0.0;
            $pricePiece = (float) $item['ppc'] == 0 ? 0 : (float) $item['single_price'] / (float) $item['ppc'];
            $rows[] = [$item['id_product'], $item['product_name'], DocumentLabels::number($item['quantity']), $money($item['single_price']), $money($lineNet),
                DocumentLabels::number($item['tva_perc']) . ' %', $lineVat == 0 ? '—' : $money($lineVat), $money($lineNet + $lineVat),
                DocumentLabels::number((float) $item['quantity'] * (float) $item['single_weight'], 2) . ' kg', DocumentLabels::number($item['ppc']), $money($pricePiece)];
            $net += $lineNet;
            $vat += $lineVat;
        }
        $rows[] = ['_total' => ['', $P('total'), '', '', $money($net), '', $vat == 0 ? '—' : $money($vat), $money($net + $vat), '', '', '']];
        $pdf->brandTable($columns, $rows, ['font' => 7, 'head_font' => 6.5]);

        $totals = [[$P('net'), $money($net)], [$P('vat'), $vat == 0 ? '—' : $money($vat)]];
        $correction = self::MANUAL_CORRECTIONS[$invoice['doc_num']] ?? 0.0;
        if ($correction != 0) $totals[] = [$P('correction'), $money($correction)];
        $totals[] = [$P('total'), $money($net + $vat + $correction)];
        $pdf->brandSummary([
            [$P('invoice_date'), $invoice['invoiced_date']],
            [$P('payment'), $P('payment_proforma')],
            [$P('bank'), $invoice['consignor_bank']],
            [$P('swift'), $invoice['consignor_swift']],
        ], $totals);

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