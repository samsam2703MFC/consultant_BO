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
use App\Services\Utils\DocumentLabels;
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
        "cancelled",
        // Stara proforma bez płatności (ponad 90 dni) : poza przypomnieniami i należnościami.
        "archived"
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

    /** Podstawa zwolnienia z VAT drukowana na proformie (tekst bez zmian, wymagany przez klientów belgijskich). */
    public const VAT_EXEMPTION = "Exonération de TVA, article 262 ter, I du Code Général des Impôts, et autoliquidation.";

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $proforma = $data['proformaData'];
        $lang = $data['lang'] ?? 'fr';
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);

        // Proforma = przedpłata bez VAT (zwolnienie w uwadze pod sumami) : VAT 0, razem = netto.
        // Pozycje według kategorii produktu (ProformaProductRepository::getByIdProforma) z nagłówkiem kategorii.
        $lines = [];
        foreach ((array) $data['proformaProductData'] as $item) {
            $lines[] = ['code' => $item['id_product'], 'name' => $item['product_name'], 'stat' => $item['code_stat'], 'quantity' => $item['quantity'],
                'price' => $item['price_per_carton'], 'net' => $item['total_price_net'], 'vat_rate' => 0, 'vat' => 0.0, 'gross' => $item['total_price_net'],
                'weight' => $item['total_weight_kg'], 'ppc' => $item['ppc'], 'price_piece' => $item['price_per_piece'], 'group' => (string) ($item['category_name'] ?? '')];
        }

        return InvoiceLayout::render($pdf, [
            'title_key' => 'doc_proforma',
            'number' => $proforma['doc_num'],
            'issue_date' => $proforma['established_date'],
            'delivery_date' => $data['deliveryDate'] ?? $proforma['established_date'],
            'lang' => $lang,
            'company_line' => DocumentLabels::companyLine($proforma),
            'seller' => ['name' => $proforma['consignor_name'],
                'lines' => [$proforma['consignor_address_1'], $proforma['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($proforma['consignor_nip'])]],
            'buyer' => ['name' => $proforma['consignee_name'],
                'lines' => [$proforma['consignee_address_1'], $proforma['consignee_address_2'], 'NIP/TVA: ' . DocumentLabels::taxId($proforma['consignee_nip'])]],
            'payment' => [
                [$P('payment'), $P('payment_proforma')],
                [$P('bank'), (string) $proforma['consignor_bank']],
                [$P('swift'), (string) $proforma['consignor_swift']],
                [$P('payment_reference'), (string) $proforma['doc_num']],
            ],
            'note' => self::VAT_EXEMPTION,
        ], $lines);
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
            'proformaProductData' => $proformaProductData,
            'deliveryDate' => $this->proformaRepository->expectedShippingDate($id),
            'lang' => $this->clientRepository->communicationLang((int) $proformaData['id_customer'])]);

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
            'proformaProductData' => $proformaProductData,
            'deliveryDate' => $this->proformaRepository->expectedShippingDate($id),
            'lang' => $this->clientRepository->communicationLang((int) $proformaData['id_customer'])]);

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
