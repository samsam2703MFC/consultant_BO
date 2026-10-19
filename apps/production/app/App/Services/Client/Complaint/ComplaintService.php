<?php

namespace App\Services\Client\Complaint;


use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Complaint\ComplaintAttachmentRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Complaint\ComplaintRepository;
use App\Repositories\Complaint\Product\ComplaintProductPendingRepository;
use App\Repositories\Complaint\Product\ComplaintProductToSendLogRepository;
use App\Repositories\Document\DocumentRepository;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\File\FileAttachmentRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;
use App\Repositories\Utils\OutboxRepository;
use App\Services\Client\ClientService;
use App\Services\S3UrlService;
use App\Services\Utils\TCPDFService;

class ComplaintService
{


    public function __construct(
        private ComplaintRepository                 $complaintRepository,
        private ComplaintProductPendingRepository   $complaintProductPendingRepository,
        private ComplaintProductToSendLogRepository $complaintProductToSendLogRepository,
        private ComplaintShipmentLogRepository      $complaintShipmentLogRepository,
        private TransactionManager                  $transactionManager,
        private ClientRepository                    $clientRepository,
        private OrderRepository                     $orderRepository,
        private WDTRepository                       $wdtRepository,
        private FinalProductRepository              $productRepository,
        private WDTProductRepository                $wdtProductRepository,
        private OutboxRepository                    $outboxRepository,
        private ComplaintAttachmentRepository       $complaintAttachmentRepository,
        private S3UrlService                        $s3UrlService

    )
    {
    }

    public function getPendingProducts()
    {
        return $this->complaintProductPendingRepository->getAll();
    }

    public function getById($id)
    {
        return $this->complaintRepository->getById($id);
    }

    public function realise($id, $data)
    {
        return $this->transactionManager->execute(function () use ($id, $data) {

            $resInsertLog = $this->complaintProductPendingRepository->insertToSendLog([
                'carton_qty' => $data['carton_qty'],
                'shipping_date' => $data['shipping_date'],
                'id' => $id
            ]);
            if (!$resInsertLog) {
                throw new CustomException("INSERT_LOG_ERROR", 'errors', 500);
            }

            foreach ($data as $key => $value) {

                if (!in_array($key, ["carton_qty", "id"])) {
                    unset($data[$key]);
                }
            }

            $resReduce = $this->complaintProductPendingRepository->reducePendingProducts([
                'carton_qty' => $data['carton_qty'],
                'id' => $id
            ]);
            if (!$resReduce) {
                throw new CustomException("REDUCE_PENDING_PRODUCTS_ERROR", 'errors', 500);
            }

            return true;
        });
    }

    public function getPendingShipment()
    {
        return $this->complaintProductToSendLogRepository->getUnsent();
    }

    public function update($id, $data)
    {
        return $this->transactionManager->execute(function () use ($id, $data) {

            if(isset($data['acceptance_status']) && $data['acceptance_status'] == "REJECTED"){
                $data['closed_on'] = date("Y-m-d H:i:s");
            }

            $res = $this->complaintRepository->update($id, $data);

            if (!$res) {
                throw new CustomException("UPDATE_COMPLAINT_ERROR", 'errors', 500);
            }

            $complaint = $this->complaintRepository->first(['id' => $id]);

            if (
                isset($data['acceptance_status'])
                && $data['acceptance_status'] == "ACCEPTED"
                && ($complaint['complaint_type'] ?? 'PRODUCT') === 'PRODUCT'
                && $complaint['compensation_method'] == "REPLACEMENT"
            ) {
                $resInsertProds = $this->complaintProductPendingRepository->insertProduct([
                    "id_customer" => $complaint['id_customer'],
                    "id_final_product" => $complaint['id_product'],
                    "pending_product_qty" => $data['accepted_quantity']
                ]);
                if (!$resInsertProds) {
                    throw new CustomException("UPDATE_COMPLAINT_ERROR", 'errors', 500);
                }
            }

            return $res;
        });
    }

    public function sendComplaintUpdate($id)
    {
        $complaint = $this->complaintRepository->first(['id' => $id]);
        if (!$complaint) {
            throw new CustomException("COMPLAINT_NOT_FOUND", 'errors', 500);
        }
        $client = $this->clientRepository->first(['id' => $complaint['id_customer']]);
        if (!isset($client) || empty($client)) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
        }
        $clientCredentials = json_decode($client['credentials'] ?? '', true);
        if(!isset($clientCredentials) || empty($clientCredentials)){
            return true;
        }

        $webhookBaseUrl = $clientCredentials['webhooks_base_url'] ?? "";
        $dedup = $complaint['complaint_key'] . "|" . uuidv4();

        try {
            return $this->outboxRepository->insert([
                'type' => 'complaint.update',
                'payload_json' => json_encode([
                    'complaint_key' => $complaint['complaint_key'],
                    'compensation_method' => $complaint['compensation_method'],
                    'acceptance_status' => $complaint['acceptance_status'],
                    'production_response' => $complaint['production_response'] ?? '',
                    'closed_on' => $complaint['closed_on'] ?? null,
                    'franchisee_uid' => $client['franchisee_uid'],
                    'secret' => $clientCredentials['secret'] ?? '',
                    'callback_url' => $webhookBaseUrl . "/webhooks/material-suppliers/complaints/update"
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'dedup_key' => $dedup
            ]);
        } catch (\Throwable $e) {
            error_log(date('Y-m-d H:i:s') . '[complaint_collector] outbox insert failed: ' . $e->getMessage());
        }

        return false;
    }

    public function updatePendingShipment($id, $data)
    {
        return $this->complaintProductToSendLogRepository->update($id, $data);
    }

    public function getUniqueShippingDate()
    {
        return $this->complaintProductPendingRepository->getUniqueShippingDate();
    }

    public function getGroupedProductReadyToPack($shippingDate)
    {
        $res_arr = [];

        $customers = $this->complaintProductPendingRepository->getUniqueClientsForSelectedShippingDate($shippingDate);


        foreach ($customers as $item) {

            $customer_data = $this->clientRepository->getById($item['id_customer']);

            $customer_name = (empty($customer_data)) ? "" : $customer_data['customer_name'];
            $customer_surname = (empty($customer_data)) ? "" : $customer_data['customer_surname'];
            $company = (empty($customer_data)) ? "" : $customer_data['company'];

            $res_arr[] = [
                "customer_data" => $customer_name . " " . $customer_surname . ", " . $company,
                "shipping_date" => date("d-m-Y", strtotime($shippingDate)),
                "product_data" => $this->complaintProductPendingRepository->getProductDataForSelectedShipingDateAndCustomer(["shipping_date" => $shippingDate, "id_customer" => $item['id_customer']])
            ];

        }

        return $res_arr;
    }

    public function getAll()
    {
        return $this->complaintShipmentLogRepository->getAll();
    }

    public function insertFromCollector(array $data, array $attachments = [])
    {
        return $this->transactionManager->execute(function () use ($data, $attachments) {

            $complaintType = $data['complaint_type'] ?? 'PRODUCT';
            if (!in_array($complaintType, ['PRODUCT', 'NON_PRODUCT'], true)) {
                throw new CustomException("INVALID_COMPLAINT_TYPE", 'errors', 400);
            }

            $exists = !empty($data['complaint_uuid'])
                ? $this->complaintRepository->first(['complaint_uuid' => $data['complaint_uuid']])
                : [];
            if (!$exists) {
                $exists = $this->complaintRepository->first(['complaint_key' => $data['complaint_key']]);
            }
            if ($exists) {
                $this->syncCollectorAttachments((int) $exists['id'], $attachments);
                return $exists['id']; // nic nie robimy ponownie
            }


            $client = $this->clientRepository->first(['franchisee_uid' => $data['franchisee_uid']]);
            if (!isset($client) || empty($client)) {
                throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
            }

            $existedOrder = null;
            if (!empty($data['order_key'])) {
                $existedOrder = $this->orderRepository->first([
                    'reference' => $data['order_key'],
                    'id_customer' => $client['id']
                ]);
            }

            if ($complaintType === 'NON_PRODUCT') {
                return $this->insertNonProductFromCollector($data, $client, $existedOrder, $attachments);
            }

            if (!$existedOrder) {
                throw new CustomException("ORDER_NOT_FOUND", 'errors', 500);
            }

            if ($existedOrder['id_wdt'] == null) {
                throw new CustomException("ORDER_NOT_LINKED_WITH_WDT", 'errors', 500);
            }

            $wdt = $this->wdtRepository->first(['id' => $existedOrder['id_wdt']]);
            if (!isset($wdt) || empty($wdt)) {
                throw new CustomException("WDT_NOT_FOUND", 'errors', 500);
            }

            $wdtProducts = $this->wdtProductRepository->getGroupedByProducts($wdt['id']);
            if (!isset($wdtProducts) || empty($wdtProducts)) {
                throw new CustomException("WDT_PRODUCTS_NOT_FOUND", 'errors', 500);
            }

            $invoiceProduct = null;
            foreach ($wdtProducts as $wdtProduct) {
                if ($wdtProduct['id_product'] == $data['product_sku']) {
                    $invoiceProduct = $wdtProduct;
                    break;
                }
            }
            if (!isset($invoiceProduct) || empty($invoiceProduct)) {
                throw new CustomException("PRODUCT_NOT_FOUND_IN_WDT", 'errors', 500);
            }

            $idComplaint = $this->complaintRepository->insert([
                "complaint_uuid" => $data['complaint_uuid'] ?? null,
                "complaint_key" => $data['complaint_key'],
                "complaint_type" => 'PRODUCT',
                "complaint_reason" => $data['reason_code'],
                "requested_action" => $data['requested_action'],
                "id_customer" => $client['id'],
                "id_order" => $existedOrder['id'],
                "invoice_num" => $wdt['doc_num'],
                "id_product" => $invoiceProduct['id_product'],
                "client_comment" => $data['description'] ?? "",
                "product_quantity" => $data['product_quantity'],
                "single_purchase_price" => $invoiceProduct['price_carton'],
                "acceptance_status" => "NEW",
                "reported_at" => $data['reported_at'] ?? null
            ]);

            $this->syncCollectorAttachments((int) $idComplaint, $attachments);

//            $webhookBaseUrl = $clientCredentials['webhooks_base_url'] ?? "";
//            $dedup = $data['complaint_key'] . "|" . $data['complaint_uuid'];
//
//            try {
//                $this->outboxRepository->insert([
//                    'type' => 'complaint.notify',
//                    'payload_json' => json_encode([
//                        'complaint_key' => $data['complaint_key'],
//                        'franchisee_uid' => $client['franchisee_uid'],
//                        'secret' => $data['secret'] ?? '',
//                        'callback_url' => $webhookBaseUrl . $data['callback_url']
//                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
//                    'dedup_key' => $dedup
//                ]);
//            } catch (\Throwable $e) {
//                error_log('[complaint_collector] outbox insert failed: ' . $e->getMessage());
//            }

            return $idComplaint;
        });
    }

    private function insertNonProductFromCollector(array $data, array $client, ?array $order, array $attachments): int
    {
        $idComplaint = $this->complaintRepository->insert([
            'complaint_uuid' => $data['complaint_uuid'] ?? null,
            'complaint_key' => $data['complaint_key'],
            'complaint_type' => 'NON_PRODUCT',
            'complaint_reason' => $data['reason_code'] ?? null,
            'requested_action' => null,
            'id_customer' => $client['id'],
            'id_order' => $order['id'] ?? null,
            'invoice_num' => null,
            'id_product' => null,
            'client_comment' => $data['description'] ?? '',
            'product_quantity' => null,
            'single_purchase_price' => null,
            'acceptance_status' => 'NEW',
            'reported_at' => $data['reported_at'] ?? null
        ]);

        if (!$idComplaint) {
            throw new CustomException("INSERT_COMPLAINT_ERROR", 'errors', 500);
        }

        $this->syncCollectorAttachments((int) $idComplaint, $attachments);

        return (int) $idComplaint;
    }

    private function syncCollectorAttachments(int $complaintId, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $clientAttachmentId = $attachment['id'] ?? null;
            $objectKey = $attachment['object_key'] ?? null;
            if ($clientAttachmentId === null || !is_string($objectKey) || $objectKey === '') {
                throw new CustomException('INVALID_COMPLAINT_ATTACHMENT', 'errors', 400);
            }

            $existing = $this->complaintAttachmentRepository->first([
                'complaint_id' => $complaintId,
                'client_attachment_id' => $clientAttachmentId,
            ]);
            if ($existing) {
                if (($existing['object_key'] ?? null) !== $objectKey) {
                    throw new CustomException('COMPLAINT_ATTACHMENT_MISMATCH', 'errors', 409);
                }
                continue;
            }

            $inserted = $this->complaintAttachmentRepository->insert([
                'complaint_id' => $complaintId,
                'client_attachment_id' => $clientAttachmentId,
                'object_key' => $objectKey,
            ]);
            if (!$inserted) {
                throw new CustomException('INSERT_COMPLAINT_ATTACHMENT_ERROR', 'errors', 500);
            }
        }
    }

    public function getComplaints()
    {
        $res = $this->complaintRepository->getAll();
        if (!$res) return [];

        foreach ($res as &$item) {
            $item['attachments'] = $this->complaintAttachmentRepository->getByComplaint($item['id']);
        }

        return $res;
    }

    public function markNotified($complaintKey)
    {
        return $this->complaintRepository->update($complaintKey, ["notified_at" => date("Y-m-d H:i:s")], "complaint_key");
    }

}
