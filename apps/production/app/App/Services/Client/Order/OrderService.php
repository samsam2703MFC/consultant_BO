<?php

namespace App\Services\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;


use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\CollectorRepository;
use App\Repositories\Client\Order\OrderProductRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Document\Proforma\ProformaProductRepository;
use App\Repositories\Document\Proforma\ProformaRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Product\Price\FinalProductCustomerPriceRepository;
use App\Repositories\Utils\OutboxRepository;
use App\Repositories\Client\Order\OrderChangelogRepository;
use App\Services\Document\ProformaService;
use App\Services\Document\ProformaContentComparator;
use App\Services\Document\ProformaSnapshotPolicy;
use App\Services\Integration\PickingListAuditService;
use App\Services\Notification\AppNotificationService;
use App\Services\Utils\TCPDFService;
use DateTimeImmutable;
use DateTimeZone;

class OrderService
{
    private OrderRepository $orderRepository;
    private OrderProductRepository $orderProductRepository;
    private TransactionManager $transactionManager;
    private ProformaProductRepository $proformaProductRepository;
    private CollectorRepository $collectorRepository;
    private ClientRepository $clientRepository;
    private FinalProductRepository $productRepository;
    private ProformaRepository $proformaRepository;
    private ProformaService $proformaService;
    private OutboxRepository $outboxRepository;
    private OrderChangelogRepository $orderChangelogRepository;

    public function __construct(
        OrderRepository           $orderRepository,
        OrderProductRepository    $orderProductRepository,
        TransactionManager        $transactionManager,
        ProformaProductRepository $proformaProductRepository,
        CollectorRepository       $collectorRepository,
        ProformaRepository        $proformaRepository,
        ClientRepository          $clientRepository,
        FinalProductRepository    $finalProductRepository,
        ProformaService           $proformaService,
        OutboxRepository          $outboxRepository,
        private InTransitPusher $inTransitPusher,
        private FinalProductCustomerPriceRepository $finalProductCustomerPriceRepository,
        OrderChangelogRepository $orderChangelogRepository,
        private PickingListAuditService $pickingListAuditService,
        private AppNotificationService $appNotificationService,
    )
    {
        $this->orderRepository = $orderRepository;
        $this->orderProductRepository = $orderProductRepository;
        $this->transactionManager = $transactionManager;
        $this->proformaProductRepository = $proformaProductRepository;
        $this->collectorRepository = $collectorRepository;
        $this->proformaRepository = $proformaRepository;
        $this->clientRepository = $clientRepository;
        $this->productRepository = $finalProductRepository;
        $this->proformaService = $proformaService;
        $this->outboxRepository = $outboxRepository;
        $this->orderChangelogRepository = $orderChangelogRepository;
    }

    public function getPending()
    {
        return array_map(function (array $order): array {
            $context = $this->getProformaContext($order);
            $order['proforma_status'] = $context['status'];
            $order['proforma_stale'] = $context['is_stale'];
            $order['proforma_quantity_difference'] = $context['has_quantity_difference'];
            return $order;
        }, $this->orderRepository->getPending());
    }

    public function getArchived(array $filters = []): array
    {
        return $this->orderRepository->getArchived($filters);
    }

    public function getClientsWithOrders(): array
    {
        return $this->orderRepository->getClientsWithOrders();
    }    public function validateOrderFromApi($data)
    {
        $existedByReference = $this->orderRepository->first(['reference' => $data['order_key']]);
        if (isset($existedByReference) && !empty($existedByReference)) {
            throw new CustomException("ORDER_ALREADY_EXISTS", 'errors', 500);
        }

        $client = $this->clientRepository->first(['franchisee_code' => $data['franchisee_code']]);
        if (!isset($client) || empty($client)) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
        }

        if (!isset($data['products']) || empty($data['products'])) {
            throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
        }
    }

//    public function insertFromApiWithProforma($data)
    public function insertOrderFromCollector($data)
    {
        return $this->transactionManager->execute(function () use ($data) {
            $client = $this->clientRepository->first(['franchisee_uid' => $data['franchisee_uid']]);
            if (!isset($client) || empty($client)) {
                throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
            }

            $existedOrder = $this->orderRepository->first(['id_customer'=>$client['id'], 'reference' => $data['order_key']]);
            if (isset($existedOrder) && !empty($existedOrder)) {
                return $existedOrder['id'];
            }

            $clientCredentials = json_decode($client['credentials'] ?? '', true);
            $webhookBaseUrl = $clientCredentials['webhooks_base_url'] ?? "";

            $idOrder = $this->orderRepository->insert([
                'id_customer' => $client['id'],
                'reference' => $data['order_key'],
                'order_batch_key' => $data['order_batch_key'] ?? null,
                'batch_index' => $data['batch_index'] ?? null,
                'invoice_num' => '',
                'status' => CustomerOrderStatus::PENDING,
                'order_description' => $data['order_description'] ?? "",
                'expected_shipping_date' => $data['expected_date'],
                'create_order_date' => $data['order_date'],
                'standard_price_list' => 0
            ]);
            if (!$idOrder) throw new CustomException("INSERT_ORDER_ERROR", 'errors', 500);

            $this->orderChangelogRepository->log([
                'id_order'    => $idOrder,
                'id_user'     => null,
                'action_type' => 'create',
                'source'      => 'api',
            ]);

            foreach ($data['products'] as $orderProduct) {
                //$product = $this->finalProductCustomerPriceRepository->getByClientAndProduct($client['id'], $orderProduct['sku']);
                $product = $this->productRepository->first(['id' => $orderProduct['sku']]);

                if (!isset($product) || empty($product)) {
                    continue;
                }

                $resInsertProduct = $this->orderProductRepository->insert([
                    'id_order' => $idOrder,
                    'id_product' => $product['id'],
                    'quantity_carton' => $orderProduct['pack_quantity'],
                    'expected_quantity_carton' => $orderProduct['pack_quantity'],
                    'reason_of_missing' => "",
 //                   'purchase_price' => $product['single_price']
                    'purchase_price' => $orderProduct['pack_price_net']
                ]);
                if (!$resInsertProduct) {
                    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
                }
            }

            try{
                $idProforma = $this->proformaService->insertFromOrder($idOrder);
            } catch (CustomException $e) {
                throw new CustomException("INSERT_PROFORMA_ERROR", 'errors', 500);
            }


            $proforma = $this->proformaService->getById($idProforma);
            $s3Key = sprintf('proformas/%s/%s/%s/%s.pdf', $data['franchisee_uid'], date('Y'), date('m'), $proforma['uuid']);

            $dedup = $data['order_key'].'|'.$proforma['uuid'];

            $this->outboxRepository->insert([
                'type'         => 'proforma.upload',
                'payload_json' => json_encode([
                    'order_key'       => $data['order_key'],
                    'franchisee_uid' => $client['franchisee_uid'],
                    'franchisee_code' => $client['franchisee_code'],
                    'proforma_uuid'   => $proforma['uuid'],
                    's3_key'          => $s3Key,
                    'secret'          => $data['secret'] ?? '',
                    'callback_url'    => $webhookBaseUrl . $data['callback_url']
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                'dedup_key'    => $dedup
            ]);

            $this->appNotificationService->publish(
                'new_order',
                'info',
                'Wpłynęło nowe zamówienie',
                'Zamówienie od ' . ($client['company'] ?? ('klienta #' . $client['id'])) . '. Proforma została wygenerowana.',
                '/client-orders',
                ['id_order' => (int) $idOrder, 'id_proforma' => (int) $idProforma, 'order_key' => $data['order_key']],
                'new_order:' . $data['franchisee_uid'] . ':' . $data['order_key']
            );


            return $idOrder;
        });
    }

    public function insertNewOrder($data)
    {
        return $this->transactionManager->execute(function () use ($data) {

            $data['standard_price_list'] = isset($data['standard_price_list']) ? 1 : 0;

            $orderType = (isset($data['order_type']) && $data['order_type'] === 'SUPPLEMENT')
                ? 'SUPPLEMENT'
                : 'STANDARD';

            $resInsertOrder = $this->orderRepository->insert([
                'id_customer' => $data['id_customer'],
                'reference' => '',
                'invoice_num' => '',
                'status' => CustomerOrderStatus::PENDING,
                'order_description' => $data['order_description'],
                'expected_shipping_date' => $data['expected_shipping_date'],
                'create_order_date' => date('Y-m-d H:i:s'),
                'standard_price_list' => $data['standard_price_list'],
                'order_type' => $orderType,
                'is_freebie' => !empty($data['is_freebie']) ? 1 : 0,
                'parent_order_id' => $data['parent_order_id'] ?? null,
                'supplement_push_status' => $orderType === 'SUPPLEMENT' ? 'pending' : null,
            ]);
            if (!$resInsertOrder) {
                throw new CustomException("INSERT_ORDER_ERROR", 'errors', 500);
            }

            $idOrder = $resInsertOrder;

            $this->orderChangelogRepository->log([
                'id_order'    => $idOrder,
                'id_user'     => user('id') ?: null,
                'action_type' => 'create',
                'source'      => 'web',
            ]);

            if (!isset($data['products']) || empty($data['products'])) {
                throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
            }

            if ($data['standard_price_list'] == 1) {
                $res = $this->orderProductRepository->insertForOrderWithStandardPrice($idOrder, $data['products']);
                if (!$res) {
                    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
                }
            } else {
                foreach ($data['products'] as $product) {
                    $res = $this->orderProductRepository->insert([
                        'id_order' => $idOrder,
                        'id_product' => $product['id_product'],
                        'quantity_carton' => $product['quantity'],
                        'expected_quantity_carton' => $product['quantity'],
                        'purchase_price' => $product['price'],
                    ]);
                    if (!$res) {
                        throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
                    }
                }
                //$res = $this->orderProductRepository->insertForOrderWithClientPrice($idOrder, $data['id_customer'], $data['products']);

            }

            return $idOrder;
        });
    }

    public function getById($id)
    {
        return $this->orderRepository->getById($id);
    }

    public function getProducts($idOrder)
    {
        return $this->orderProductRepository->getById($idOrder);
    }

    public function validate($data)
    {
        $errors = [];

        if (empty($data['expected_shipping_date'])) {
            $errors['empty_shipping_date'] = "Wybierz datę wysyłki";
        }

        if (empty($data['products'])) {
            $errors['empty_data'] = "Wprowadź przynajmniej jeden produkt";
        }


        if (empty($errors)) {
            return true;
        }

        return false;
    }

    public function updateOrderWithProducts($id, $data)
    {
        return $this->transactionManager->execute(function () use ($id, $data) {
            $orderBefore   = $this->orderRepository->getById($id);
            $productsBefore = $this->orderProductRepository->getById($id);

            if (!$orderBefore) {
                throw new CustomException("ORDER_NOT_FOUND", 'errors', 404);
            }

            if (!isset($data['order_description'])) $data['order_description'] = "";
            if (!isset($data['standard_price_list'])) $data['standard_price_list'] = 0;
            if (!isset($data['is_freebie'])) $data['is_freebie'] = 0;

            $proformaContext = $this->getProformaContext($orderBefore, $productsBefore);
            $productsToSave = $data['products'] ?? [];
            $supplementProducts = [];

            if ($proformaContext['is_paid'] && ($data['paid_change_mode'] ?? 'current') === 'supplement') {
                [$productsToSave, $supplementProducts] = $this->splitProductsForSupplement($productsBefore, $productsToSave);
                if (empty($supplementProducts)) {
                    throw new CustomException("SUPPLEMENT_REQUIRES_POSITIVE_CHANGE", 'errors', 422);
                }
            }

            $resUpdate = $this->orderRepository->update($id, [
                'expected_shipping_date' => $data['expected_shipping_date'],
                'order_description' => $data['order_description'],
                'standard_price_list' => $data['standard_price_list'],
                'is_freebie' => $data['is_freebie']
            ]);
            if (!$resUpdate) {
                throw new CustomException("UPDATE_ORDER_ERROR", 'errors', 500);
            }

            $resDeleteProducts = $this->orderProductRepository->delete($id, 'id_order');
            if (!$resDeleteProducts) {
                throw new CustomException("DELETE_ORDER_PRODUCT_ERROR", 'errors', 500);
            }

            if ($data['standard_price_list'] == 1) {
                $res = $this->orderProductRepository->insertForOrderWithStandardPrice($id, $productsToSave);
                if (!$res) {
                    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
                }
            } else {
                foreach ($productsToSave as $product) {
                    $res = $this->orderProductRepository->insert([
                        'id_order' => $id,
                        'id_product' => $product['id_product'],
                        'quantity_carton' => $product['quantity'],
                        'expected_quantity_carton' => $product['quantity'],
                        'purchase_price' => $product['price']
                    ]);
                    if (!$res) {
                        throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
                    }
                }
//                $res = $this->orderProductRepository->insertForOrderWithClientPrice($id, $data['id_customer'], $data['products']);
            }

            $this->orderChangelogRepository->log([
                'id_order'      => $id,
                'id_user'       => user('id') ?: null,
                'action_type'   => 'update',
                'source'        => 'web',
                'changed_fields' => json_encode([
                    'before' => [
                        'expected_shipping_date' => $orderBefore['expected_shipping_date'] ?? null,
                        'order_description'      => $orderBefore['order_description'] ?? null,
                        'standard_price_list'    => $orderBefore['standard_price_list'] ?? null,
                        'is_freebie'             => $orderBefore['is_freebie'] ?? null,
                        'products'               => array_map(fn($p) => [
                            'id_product'      => $p['id_product'],
                            'quantity_carton' => $p['quantity_carton'],
                            'purchase_price'  => $p['purchase_price'],
                        ], $productsBefore ?: []),
                    ],
                    'after' => [
                        'expected_shipping_date' => $data['expected_shipping_date'],
                        'order_description'      => $data['order_description'],
                        'standard_price_list'    => $data['standard_price_list'],
                        'is_freebie'             => $data['is_freebie'],
                        'products'               => array_map(fn($p) => [
                            'id_product'      => $p['id_product'],
                            'quantity_carton' => $p['quantity'],
                            'purchase_price'  => $p['price'],
                        ], $productsToSave),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);


            // Proforma is always a document snapshot. Editing an order must never overwrite it.
            if (empty($supplementProducts)) {
                return ['updated' => true, 'supplement_id' => null];
            }

            $supplementId = $this->insertNewOrder([
                'id_customer' => $orderBefore['id_customer'],
                'order_description' => 'Domówienie do zamówienia #' . $id,
                'expected_shipping_date' => $data['expected_shipping_date'],
                'standard_price_list' => 0,
                'order_type' => 'SUPPLEMENT',
                'parent_order_id' => $id,
                'products' => $supplementProducts,
            ]);
            $this->proformaService->insertFromOrder($supplementId);

            return ['updated' => true, 'supplement_id' => $supplementId];
        });
    }

    public function getProformaContext(array $order, ?array $orderProducts = null): array
    {
        $context = ['has_proforma' => false, 'status' => null, 'is_paid' => false, 'is_stale' => false, 'has_quantity_difference' => false];
        if (empty($order['id_proforma'])) {
            return $context;
        }

        $proforma = $this->proformaRepository->first(['id' => $order['id_proforma']]);
        if (!$proforma) {
            return $context;
        }

        $status = strtolower(trim((string) ($proforma['status'] ?? 'issued')));
        $context['has_proforma'] = true;
        $context['status'] = $status;
        $context['is_paid'] = ProformaSnapshotPolicy::isPaid($proforma);
        if ($status === 'cancelled') {
            return $context;
        }

        $orderProducts ??= $this->orderProductRepository->getById($order['id']);
        $snapshot = $this->proformaProductRepository->getByIdProforma($proforma['id']);
        $context['has_quantity_difference'] = ProformaContentComparator::quantitiesDiffer($orderProducts, $snapshot);
        $context['is_stale'] = ProformaContentComparator::differs(
            $orderProducts,
            $snapshot,
            !empty($order['is_freebie'])
        );
        if ($context['is_paid']) {
            // A paid document is not "outdated"; its intentional difference is settled after shipping.
            $context['is_stale'] = false;
        }
        return $context;
    }

    public function regenerateUnpaidProforma(int $idOrder): int
    {
        return $this->transactionManager->execute(function () use ($idOrder): int {
            $order = $this->orderRepository->getById($idOrder);
            if (!$order || empty($order['id_proforma'])) {
                throw new CustomException("PROFORMA_NOT_FOUND", 'errors', 404);
            }
            $context = $this->getProformaContext($order);
            if ($context['is_paid']) {
                throw new CustomException("PAID_PROFORMA_CANNOT_BE_REGENERATED", 'errors', 409);
            }
            if (!$context['is_stale']) {
                throw new CustomException("PROFORMA_NOT_STALE", 'errors', 422);
            }
            $oldProformaId = (int) $order['id_proforma'];
            if (!$this->proformaRepository->update($oldProformaId, ['status' => 'cancelled'])) {
                throw new CustomException("PROFORMA_CANCELLATION_FAILED", 'errors', 500);
            }
            if (!$this->orderRepository->update($idOrder, ['id_proforma' => null])) {
                throw new CustomException("ORDER_UPDATE_FAILED", 'errors', 500);
            }
            return (int) $this->proformaService->insertFromOrder($idOrder);
        });
    }

    private function splitProductsForSupplement(array $before, array $requested): array
    {
        $beforeByProduct = [];
        foreach ($before as $product) {
            $beforeByProduct[(int) $product['id_product']] = $product;
        }
        $main = [];
        $supplement = [];
        foreach ($requested as $product) {
            $productId = (int) ($product['id_product'] ?? 0);
            $requestedQuantity = (float) ($product['quantity'] ?? 0);
            if (!$productId || $requestedQuantity <= 0) continue;
            $existing = $beforeByProduct[$productId] ?? null;
            if (!$existing) {
                $supplement[] = $product;
                continue;
            }
            $currentQuantity = (float) $existing['quantity_carton'];
            if ($requestedQuantity > $currentQuantity) {
                $extra = $product;
                $extra['quantity'] = $requestedQuantity - $currentQuantity;
                $supplement[] = $extra;
                $product['quantity'] = $currentQuantity;
            }
            $main[] = $product;
            unset($beforeByProduct[$productId]);
        }
        foreach ($beforeByProduct as $remaining) {
            // A missing row means deletion (a reduction to zero), so it stays deleted from main order.
        }
        return [$main, $supplement];
    }

    public function delete($id)
    {
        return $this->transactionManager->execute(function () use ($id) {
            $orderSnapshot    = $this->orderRepository->getById($id);
            $productsSnapshot = $this->orderProductRepository->getById($id);

            $resDelete = $this->orderRepository->delete($id);
            if (!$resDelete) {
                throw new CustomException("DELETE_ORDER_ERROR", 'errors', 500);
            }

            $resDeleteProducts = $this->orderProductRepository->delete($id, 'id_order');
            if (!$resDeleteProducts) {
                throw new CustomException("DELETE_ORDER_PRODUCT_ERROR", 'errors', 500);
            }

            $this->orderChangelogRepository->log([
                'id_order'       => $id,
                'id_user'        => user('id') ?: null,
                'action_type'    => 'delete',
                'source'         => 'web',
                'changed_fields' => json_encode([
                    'snapshot' => array_merge($orderSnapshot ?? [], ['products' => $productsSnapshot ?? []])
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;

        });

    }

    public function getLastInsertedIdOfOrderFromCollector()
    {
        return $this->orderRepository->getLastInsertedIdOfOrderFromCollector();
    }

    public function checkOrderQuantityForPickingList($orderIds)
    {
        $errors = [];

        if (empty($orderIds) || !isset($orderIds)) {
            $errors['empty_order_list'] = "Wybierz przynajmniej jedno zamówienie";
        }

        if (empty($errors)) {
            return true;
        }

        return false;
    }

    /** @param list<int> $orderIds */
    public function areAllPendingOrderIds(array $orderIds): bool
    {
        return $this->orderRepository->hasAllPendingIds($orderIds);
    }

    public function insertNewOrderFromCollector($data)
    {
        return $this->transactionManager->execute(function () use ($data) {

            foreach ($data['collector']['data'] as $collectorData) {
                if (!isset($collectorData['id_customer']) || $collectorData['id_customer'] == "" || $collectorData['id_customer'] == null) continue;

                $resInsert = $this->orderRepository->insert([
                    'id_customer' => $collectorData['id_customer'],
                    'invoice_num' => $collectorData['invoice_num'],
                    'status' => CustomerOrderStatus::PENDING,
                    'expected_shipping_date' => $collectorData['delivery_date'],
                    'create_order_date' => $collectorData['timestamp'],
                    'id_franchisee_collector' => $collectorData['id']
                ]);
                if (!$resInsert) {
                    throw new CustomException("INSERT_ORDER_FROM_COLLECTOR_ERROR", 'errors', 500);
                }
            }

            foreach ($data['products']['data'] as $collectorData) {
                $resInsertProducts = $this->orderProductRepository->insertFromCollector($collectorData);
                if (!$resInsertProducts) {
                    throw new CustomException("INSERT_ORDER_PRODUCT_FROM_COLLECTOR_ERROR", 'errors', 500);
                }

            }

            foreach ($data['products']['data'] as $collectorData) {
                $resInsertCollector = $this->collectorRepository->insertFromOrder($collectorData);
                if (!$resInsertCollector) {
                    throw new CustomException("INSERT_COLLECTOR_FROM_ORDER_ERROR", 'errors', 500);
                }
            }

            return true;
        });
    }

    /** @param list<int|string> $orderIds @param array<string, mixed>|null $auditContext */
    public function getPickingList($orderIds, ?array $auditContext = null)
    {
        $orderDataArr = $this->getSelectedOrdersData($orderIds);
        $pdf = new TCPDFService('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetMargins(5, '5', 5);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->SetFont('helvetica', '', 12);

        $printedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
            ->format('d-m-Y H:i:s');

        foreach ($orderDataArr as $item) {
            $pdf->AddPage();

            $pdf->SetFont('helvetica', '', 10);
            $pdf->writeHTMLCell(75, 5, 5, 5, "Data wydruku:   " . $printedAt, 0, 1, 0, true, 'L', true);
            $pdf->writeHTMLCell(75, 5, 5, 10, "Data wysylki:   {$item['shipping_date']}", 0, 1, 0, true, 'L', true);

            $pdf->SetFont('helvetica', 'B', 17);
            $pdf->writeHTMLCell(160, 5, 25, 20, "Zamowienie", 0, 1, 0, true, 'C', true);

            $pdf->SetFont('helvetica', '', 14);
            $pdf->writeHTMLCell(160, 5, 25, 30, "{$item['customer_data']}", 0, 1, 0, true, 'C', true);

            $pdf->Ln();

            if (!empty($item['order_data']['is_freebie'])) {
                $pdf->SetFont('freeserif', 'B', 13);
                $pdf->SetFillColor(255, 243, 205);
                $pdf->SetTextColor(133, 77, 14);
                $pdf->Cell(200, 10, '*** GRATIS — tego zamówienia NIE należy skanować ***', 1, 1, 'C', true);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Ln(2);
            }

            $pdf->SetFont('', 'B', 12);
            $pdf->Cell(85, 7, 'Kategoria', 1, 0, 'C');
            $pdf->Cell(85, 7, 'Produkt', 1, 0, 'C');
            $pdf->Cell(30, 7, 'Ilosc (karton)', 1, 0, 'C');


            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 9);

            $lastCategory = "";
            foreach ($item['product_data'] as $row) {
                if ($row['sum'] <= 0) {
                    $lastCategory = $row['category_name'];
                    continue;
                }

                $currCategory = $row['category_name'];
                if (strcmp($lastCategory, $currCategory) == 0) $currCategory = "";

                $pdf->Cell(85, 6, $currCategory, 'LR', 0, 'L');
                $pdf->Cell(85, 6, $row['product_name'], 'LR', 0, 'L');
                $pdf->Cell(30, 6, $row['sum'], 'LR', 0, 'C');
                $pdf->Ln();
                $lastCategory = $row['category_name'];
            }

            $pdf->Cell(200, 0, '', 'T');
        }


        $base = $pdf->Output('ORDER_LIST_.pdf', 'S');
        $filename = "PICKING_LIST_" . date("d_m_Y");

        if ($auditContext !== null) {
            $this->pickingListAuditService->record($orderDataArr, $filename . '.pdf', $base, $auditContext);
        }

        return [
            'filename' => $filename . '.pdf',
            'file_string' => $base
        ];
    }

    public function getOrderDataById($idOrder)
    {
        $order_customer_data = $this->getById($idOrder);
        $order_product_data = $this->orderProductRepository->getOrderDataForPDF($idOrder);
        $shipping_date = date_create($order_customer_data['expected_shipping_date']);


        return [
            "customer_data" => $order_customer_data['customer_name'] . " " . $order_customer_data['customer_surname'] . ", " . $order_customer_data['company'],
            "shipping_date" => date_format($shipping_date, "d-m-Y"),
            "product_data" => $order_product_data,
            "order_data" => $order_customer_data
        ];
    }

    /**
     * API representation of a picking list.  It intentionally starts from
     * getSelectedOrdersData(), the same data that powers the PDF, but keeps
     * every selected order as a separate item instead of aggregating products
     * across orders.
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    public function getPickingListPreview(array $orderIds): array
    {
        $orders = [];

        foreach ($this->getSelectedOrdersData($orderIds) as $item) {
            $products = [];
            foreach ($item['product_data'] as $product) {
                // The PDF omits non-positive quantities, so the API view does too.
                if ((float) $product['sum'] <= 0) {
                    continue;
                }

                $products[] = [
                    'category_name' => (string) $product['category_name'],
                    'product_name' => (string) $product['product_name'],
                    'quantity_carton' => (float) $product['sum'],
                ];
            }

            $orders[] = [
                'order_id' => (int) $item['order_data']['id'],
                'customer' => (string) $item['customer_data'],
                'shipping_date' => (string) $item['shipping_date'],
                'is_freebie' => !empty($item['order_data']['is_freebie']),
                'products' => $products,
            ];
        }

        return $orders;
    }

    public function getSelectedOrdersData($data)
    {
        $res_arr = [];

        foreach ($data as $idOrder) {
            array_push($res_arr, $this->getOrderDataById($idOrder));
        }

        return $res_arr;
    }

    public function update($id, $data)
    {
        $result = $this->orderRepository->update($id, $data);

        if ($result) {
            $this->orderChangelogRepository->log([
                'id_order'       => $id,
                'id_user'        => user('id') ?: null,
                'action_type'    => 'update',
                'source'         => 'web',
                'changed_fields' => json_encode(['after' => $data], JSON_UNESCAPED_UNICODE),
            ]);
        }
        return $result;
    }

    public function getChangelog(int $id): array
    {
        return $this->orderChangelogRepository->getByOrderId($id);
    }

    /**
     * Aktualizuje pola zwiazane z cyklem push'a domowienia (SUPPLEMENT)
     * i zapisuje wpis do changelogu z source='system'.
     *
     * Wywolywane wylacznie przez OrderSupplementPusherService — zamiast
     * bezposrednich wywolan OrderRepository::update, ktore omijalyby audyt.
     *
     * @param int    $idOrder       ID zamowienia (customer_order.id)
     * @param array  $data          Subset pol: supplement_push_status,
     *                              supplement_push_at, supplement_order_key
     * @param string $reason        Opcjonalny opis kontekstu (np. tresc bledu)
     * @return bool
     */
    public function markSupplementPushStatus(int $idOrder, array $data, string $reason = ''): bool
    {
        $allowed = ['supplement_push_status', 'supplement_push_at', 'supplement_order_key'];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) {
            return false;
        }

        $result = $this->orderRepository->update($idOrder, $filtered);
        if (!$result) {
            return false;
        }

        $payload = ['after' => $filtered];
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        $this->orderChangelogRepository->log([
            'id_order'       => $idOrder,
            'id_user'        => function_exists('user') ? (user('id') ?: null) : null,
            'action_type'    => 'update',
            'source'         => 'system',
            'changed_fields' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    public function notifyIntegrated($id)
    {
//        $resUpdateStatus = $this->orderRepository->update($id, ['status' => CustomerOrderStatus::IN_TRANSIT]);
//        if (!$resUpdateStatus) {
//            throw new CustomException("UPDATE_ORDER_STATUS_ERROR", 'errors', 500);
//        }

        $resInTransit = $this->inTransitPusher->pushInTransit($id);
        if (!$resInTransit) {
            throw new CustomException("PUSH_IN_TRANSIT_ERROR", 'errors', 500);
        }

        if($resInTransit)
        {
            $res = $this->orderRepository->update($id, ['status' => CustomerOrderStatus::IN_TRANSIT]);
            if (!$res) {
                throw new CustomException("UPDATE_ORDER_STATUS_ERROR", 'errors', 500);
            }
        }

        return true;
    }

//    public function newFromCollector($data)
//    {
//        return $this->transactionManager->execute(function () use ($data) {
//            $client = $this->clientRepository->first(['franchisee_uid' => $data['franchisee_uid']]);
//            if (!isset($client) || empty($client)) {
//                throw new CustomException("CLIENT_NOT_FOUND", 'errors', 500);
//            }
//
//            $idOrder = $this->orderRepository->insert([
//                'id_customer' => $client['id'],
//                'reference' => $data['order_key'],
//                'invoice_num' => '',
//                'status' => CustomerOrderStatus::PENDING,
//                'order_description' => $data['order_description'] ?? "",
//                'expected_shipping_date' => $data['expected_date'],
//                'create_order_date' => $data['order_date'],
//                'standard_price_list' => 0
//            ]);
//            if (!$idOrder) throw new CustomException("INSERT_ORDER_ERROR", 'errors', 500);
//
//            foreach ($data['products'] as $orderProduct) {
//                $product = $this->productRepository->first(['id' => $orderProduct['sku']]);
//                if (!isset($product) || empty($product)) {
//                    continue;
//                }
//
//                $resInsertProduct = $this->orderProductRepository->insert([
//                    'id_order' => $idOrder,
//                    'id_product' => $product['id'],
//                    'quantity_carton' => $orderProduct['pack_quantity'],
//                    'expected_quantity_carton' => $orderProduct['pack_quantity'],
//                    'reason_of_missing' => "",
//                    'purchase_price' => $orderProduct['pack_price_net']
//                ]);
//                if (!$resInsertProduct) {
//                    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
//                }
//            }
//
//            try{
//                $idProforma = $this->proformaService->insertFromOrder($idOrder);
//            } catch (CustomException $e) {
//                throw new CustomException("INSERT_PROFORMA_ERROR", 'errors', 500);
//            }
//
//            $s3Key = sprintf('proformas/%s/%s/%s/%s.pdf', $data['franchisee_uid'], date('Y'), date('m'), $proforma['uuid']);
//
//            $dedup = $data['order_key'].'|'.$proforma['uuid'];
//
//            $this->outboxRepository->insert([
//                'type'         => 'proforma.upload',
//                'payload_json' => json_encode([
//                    'order_key'       => $data['order_key'],
//                    'franchisee_uid' => $data['franchisee_code'],
//                    'franchisee_code' => $data['franchisee_code'],
//                    'proforma_uuid'   => $proforma['uuid'],
//                    's3_key'          => $s3Key,
//                    'callback_url'    => $data['callback_url'],
//                    'auth_token'      => $data['auth_token']
//                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
//                'dedup_key'    => $dedup
//            ]);
//
//
//            return ['id_order' => $idOrder, 'id_proforma' => $idProforma];
//        });
//    }
}
