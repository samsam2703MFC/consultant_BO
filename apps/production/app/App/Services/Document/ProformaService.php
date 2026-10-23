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

    /** Podstawa zwolnienia z VAT drukowana na proformie (tekst bez zmian, wymagany przez klientów belgijskich). */
    public const VAT_EXEMPTION = "Exonération de TVA, article 262 ter, I du Code Général des Impôts, et autoliquidation.";

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $proforma = $data['proformaData'];
        $lang = $data['lang'] ?? 'fr';
        $L = fn(string $key) => DocumentLabels::get($key);
        $S = fn(string $key) => DocumentLabels::sub($key, $lang);
        $P = fn(string $key) => DocumentLabels::pair($key, $lang);
        $money = fn($v) => DocumentLabels::money($v);

        $pdf->brandPage($L('doc_proforma'), ['ref' => $proforma['doc_num'], 'lang' => $lang, 'company' => DocumentLabels::companyLine($proforma)]);
        $pdf->brandHeader($L('doc_proforma'), [
            [$P('number'), $proforma['doc_num']],
            [$P('date'), $proforma['established_date']],
        ], $S('doc_proforma'));

        $pdf->brandParties([
            'label' => $P('seller'),
            'name' => $proforma['consignor_name'],
            'lines' => [$proforma['consignor_address_1'], $proforma['consignor_address_2'], 'NIP: ' . DocumentLabels::taxId($proforma['consignor_nip'])],
        ], [
            'label' => $P('buyer'),
            'name' => $proforma['consignee_name'],
            'lines' => [$proforma['consignee_address_1'], $proforma['consignee_address_2'], 'NIP/TVA: ' . DocumentLabels::taxId($proforma['consignee_nip'])],
        ]);

        $columns = [
            ['label' => $L('code'), 'sub' => $S('code'), 'w' => 10],
            ['label' => $L('description'), 'sub' => $S('description'), 'w' => 30],
            ['label' => $L('stat_code'), 'sub' => $S('stat_code'), 'w' => 11],
            ['label' => $L('cartons'), 'sub' => $S('cartons'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_carton'), 'sub' => $S('price_carton'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('net_value'), 'sub' => $S('net_value'), 'w' => 13, 'align' => 'R'],
            ['label' => $L('vat_rate'), 'sub' => $S('vat_rate'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('weight_carton'), 'sub' => $S('weight_carton'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('weight'), 'sub' => $S('weight'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('ppc'), 'sub' => $S('ppc'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('price_piece'), 'sub' => $S('price_piece'), 'w' => 10, 'align' => 'R'],
        ];
        $rows = [];
        $net = 0.0;
        $weight = 0.0;
        $cartons = 0;
        foreach ($data['proformaProductData'] as $item) {
            $rows[] = [$item['id_product'], $item['product_name'], $item['code_stat'], DocumentLabels::number($item['quantity']), $money($item['price_per_carton']),
                $money($item['total_price_net']), DocumentLabels::number($item['tva_perc']) . ' %', DocumentLabels::number($item['single_carton_weight_kg'], 2) . ' kg',
                DocumentLabels::number($item['total_weight_kg'], 2) . ' kg', DocumentLabels::number($item['ppc']), $money($item['price_per_piece'])];
            $net += (float) $item['total_price_net'];
            $weight += (float) $item['total_weight_kg'];
            $cartons += (int) $item['quantity'];
        }
        $rows[] = ['_total' => ['', $P('total'), '', DocumentLabels::number($cartons), '', $money($net), '', '', DocumentLabels::number($weight, 2) . ' kg', '', '']];
        $pdf->brandTable($columns, $rows, ['font' => 7, 'head_font' => 6.5]);

        // Proforma = przedpłata bez VAT (zwolnienie poniżej) : razem = netto
        $pdf->brandSummary([
            [$P('total_weight'), DocumentLabels::number($weight, 2) . ' kg'],
            [$P('payment'), $P('payment_proforma')],
            [$P('bank'), $proforma['consignor_bank']],
            [$P('swift'), $proforma['consignor_swift']],
            [$P('payment_reference'), $proforma['doc_num']],
        ], [
            [$P('net'), $money($net)],
            [$P('total'), $money($net)],
        ]);
        $pdf->brandNote(self::VAT_EXEMPTION, 'C');

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
            'proformaProductData' => $proformaProductData,
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
