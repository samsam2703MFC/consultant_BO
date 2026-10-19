<?php

namespace App\Controllers\Web\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Client\Order\OrderCollectorService;
use App\Services\Client\Order\OrderSupplementPusherService;
use App\Services\Client\Order\OrderService;
use App\Services\Client\PriceList\ExternalPriceListService;
use App\Services\Finances\ClientProductPriceService;
use App\Services\Product\ProductService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends BaseController
{


    public function __construct(
        private OrderService   $orderService,
        private ClientService  $clientService,
        private ProductService $productService,
        private OrderCollectorService $orderCollectorService,
        private ClientProductPriceService $clientProductPriceService,
        private ExternalPriceListService $externalPriceListService,
        private OrderSupplementPusherService $supplementPusherService
    )
    {
    }

    public function overview()
    {

        $this->loadOrdersFromFranchisee();
        $pending = $this->orderService->getPending();

        // Oblicz liczbę części w każdej grupie batch (tylko wśród zamówień oczekujących)
        $batchCounts = [];
        foreach ($pending as $order) {
            if (!empty($order['order_batch_key'])) {
                $batchCounts[$order['order_batch_key']] = ($batchCounts[$order['order_batch_key']] ?? 0) + 1;
            }
        }

        // Wstrzyknij batch_total do każdego zamówienia
        foreach ($pending as &$order) {
            $order['batch_total'] = !empty($order['order_batch_key'])
                ? ($batchCounts[$order['order_batch_key']] ?? 1)
                : null;
        }
        unset($order);

        $data['pending']     = $pending;
        $data['batchCounts'] = $batchCounts;
        $data['clients']     = $this->clientService->getAll();
        $data['orderStatusCodes'] = [
            'in_transit' => CustomerOrderStatus::IN_TRANSIT,
            'archived' => CustomerOrderStatus::ARCHIVED,
        ];
        $this->view("client/order/overview", $data);
    }

    public function archive()
    {
        $filters = [
            'client_id' => $_GET['client_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'document' => $_GET['document'] ?? '',
            'sort' => $_GET['sort'] ?? 'shipping_date',
            'direction' => $_GET['direction'] ?? 'desc',
        ];
        $orders = $this->orderService->getArchived($filters);
        $groups = [];
        foreach ($orders as $order) {
            $key = !empty($order['id_wdt']) ? 'wdt-' . $order['id_wdt'] : 'order-' . $order['id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['wdt_id' => $order['id_wdt'], 'orders' => [], 'weight' => 0];
            }
            $groups[$key]['orders'][] = $order;
            $groups[$key]['weight'] += (float) ($order['total_sum_kg'] ?? 0);
        }
        $data['filters'] = $filters;
        $data['groups'] = array_values($groups);
        $data['clients'] = $this->orderService->getClientsWithOrders();
        $this->view("client/order/archive", $data);
    }
    private function loadOrdersFromFranchisee()
    {
//        $options = array(
//            'http' => array(
//                'method' => "GET",
//                'header' => ["auth: cHJvZHVjdGlvbjoxMjM=",
//                    "User-Type: Production"]
//            )
//        );
//
//        $context = stream_context_create($options);
//        $lastInsertedOrderId = $this->orderService->getLastInsertedIdOfOrderFromCollector();
//
//        $api_url = APIPREFIXE . "SLFoodOrderCollector/getDataFromCollector/{$lastInsertedOrderId[0]['lastInsertedOrder']}";
//
//        $json_data = file_get_contents($api_url, false, $context);
//        $convertedToArrObj = json_decode(json_encode(json_decode($json_data)), true);
//
//        $this->orderService->insertNewOrderFromCollector($convertedToArrObj);
    }

//    public function newOrder()
//    {
//        $data['customers'] = $this->clientService->getAll();
//
//        // Load price_list for the first client by default
//        // Or for pre-selected client from POST
//        $selectedClientId = $_POST['id_customer'] ?? null;
//
//        // If no client selected yet, use first from list
//        if (empty($selectedClientId) && !empty($data['customers'])) {
//            $selectedClientId = $data['customers'][0]['id'];
//        }
//
//        // Load price list for selected client
//        $data['price_list'] = [];
//        if (!empty($selectedClientId)) {
//            $client = $this->clientService->getById($selectedClientId);
//            if (!empty($client)) {
//                if (!is_null($client['franchisee_uid'])) {
//                    $data['price_list'] = $this->externalPriceListService->getActiveByClient($client['id']);
//                } else {
//                    $data['price_list'] = $this->clientProductPriceService->getActiveByClient($client['id']);
//                }
//            }
//        }
//
//        if ($_SERVER['REQUEST_METHOD'] == "POST") {
//            if ($this->orderService->validate($_POST)) {
//                if (empty($_POST['order_description'])) $_POST['order_description'] = "";
//
//
//                $this->handleAction(function () {
//                    $this->orderService->insertNewOrder($_POST);
//                    header('Location: ' . ROOT . '/client-orders');
//                    exit;
//                }, default: false);
//
//            } else {
//                $oldData = $this->getOldDataFromPostRequest($data);
//                $data = array_merge($data, $oldData);
//            }
//        }
//
//        $this->view("client/order/new", $data);
//    }

    #[Route('GET', '/clients/{id:\d+}/orders/create')]
    public function createOrderForClient($id)
    {
        $data['client'] = $this->clientService->getById($id);

        $data['client_type'] = "standard";
        if(!is_null($data['client']['franchisee_uid'])){
            $data['client_type'] = "franchisee";
            $data['price_list'] = $this->externalPriceListService->getActiveByClient($data['client']['id']);
        } else {
            $data['price_list'] = $this->clientProductPriceService->getActiveByClient($data['client']['id']);
        }



        if ($_SERVER['REQUEST_METHOD'] == "POST") {

            if ($this->orderService->validate($_POST)) {
                if (empty($_POST['order_description'])) $_POST['order_description'] = "";

                $this->handleAction(function () {
                    $newOrderId = $this->orderService->insertNewOrder($_POST);
                    if (!empty($_POST['order_type']) && $_POST['order_type'] === 'SUPPLEMENT' && $newOrderId) {
                        $this->supplementPusherService->push((int)$newOrderId);
                    }
                    header('Location: ' . ROOT . '/client-orders');
                    exit;
                }, default: false);

            } else {
                $oldData = $this->getOldDataFromPostRequest($data);
                $data = array_merge($data, $oldData);
            }
        }

        $this->view("client/order/new", $data);
    }

    public function editOrder($id)
    {
        $data['row'] = $this->orderService->getById($id);


        $data['client'] = $this->clientService->getById($data['row']['id_customer']);
//        $data['final_products'] = $this->productService->getAll();

        $data['row_products'] = $this->orderService->getProducts($id);
        $data['proforma_context'] = $this->orderService->getProformaContext($data['row'], $data['row_products']);
        $data['client_type'] = "standard";
        if(!is_null($data['client']['franchisee_uid'])){
            $data['client_type'] = "franchisee";
            $data['price_list'] = $this->externalPriceListService->getActiveByClient($data['client']['id']);
        } else {
            $data['price_list'] = $this->clientProductPriceService->getActiveByClient($data['client']['id']);
        }

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            if ($this->orderService->validate($_POST)) {

                $postData = $_POST;
                $postData['id_customer'] = $data['client']['id'];

                $result = $this->orderService->updateOrderWithProducts($id, $postData);

                if ($result) {
                    if (($data['row']['order_type'] ?? '') === 'SUPPLEMENT') {
                        $this->supplementPusherService->push((int)$id);
                    }
                    if (!empty($result['supplement_id'])) {
                        $this->supplementPusherService->push((int)$result['supplement_id']);
                    }
                    header('Location: ' . ROOT . '/client-orders');
                    exit;
                }
            } else {
                $data['row_products'] = [];
                $oldData = $this->getOldDataFromPostRequest($data);
                $data = array_merge($data, $oldData);
            }
        }

        $this->view("client/order/edit", $data);
    }

    public function deleteOrder($id)
    {
        $data['row'] = $this->orderService->getById($id);
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $success = $this->handleAction(function () use ($id) {
                $this->orderService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/client-orders');
                exit;
            }
        }

        $this->view("client/order/delete", $data);
    }

    private function getOldDataFromPostRequest($data)
    {
        $newData = [];
        if (!empty($_POST['products'])) {
            $newData['old_products'] = [];

            // Convert products array to format expected by view
            foreach ($_POST['products'] as $product) {
                $id_product = $product['id_product'];

                // Find product details from price_list
                $productDetails = null;
                if (!empty($data['price_list'])) {
                    foreach ($data['price_list'] as $item) {
                        $itemId = is_object($item) ? $item->getIdProduct() : $item['id_product'];
                        if ($itemId == $id_product) {
                            $productDetails = $item;
                            break;
                        }
                    }
                }

                if ($productDetails) {
                    $newData['old_products'][] = [
                        'id_product' => $id_product,
                        'product_name' => is_object($productDetails) ? $productDetails->getProductName() : $productDetails['product_name'],
                        'quantity_carton' => $product['quantity'],
                        'price_net' => $product['price'] ?? (is_object($productDetails) ? $productDetails->getPriceNet() : $productDetails['price_net'])
                    ];
                }
            }
        }
        return $newData;
    }

    public function ajaxUpdateOrder($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->orderService->update($id, $data);

        return $this->handleAjaxResponse($result, 'Zamówienie zaktualizowane', 'Nie udało się zaktualizować zamówienia');
    }

    public function ajaxNotifyIntegrated($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->handleAction(function () use ($id) {
            return $this->orderService->notifyIntegrated($id);
        }, default: false);

        return $this->handleAjaxResponse($result, 'Zamówienie zaktualizowane', 'Nie udało się zaktualizować zamówienia');
    }

    public function ajaxGetOrdersFromFranchise()
    {

        $result = $this->orderCollectorService->runManualCollector();
        return $this->handleAjaxResponse($result, 'Zamówienie zaktualizowane', 'Nie udało się zaktualizować zamówienia');
    }

    public function ajaxGetPickingList()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $resp = $this->handleAction(function () use ($data) {

            if ($this->orderService->checkOrderQuantityForPickingList($data)) {
                return $this->orderService->getPickingList($data, [
                    'source' => 'web',
                    'user_id' => user('id') !== '' ? (int) user('id') : null,
                ]);
            } else return false;
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy produkcyjnej'], 500);
        }

        $pdfData = $resp['file_string'];
        $filename = htmlspecialchars($resp['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length' => strlen($pdfData),
            ]
        );
    }

    public function showChangelog($id)
    {
        $data['row']       = $this->orderService->getById($id);
        $data['changelog'] = $this->orderService->getChangelog($id);
        $this->view("client/order/changelog", $data);
    }


    public function ajaxPushSupplement($id)
    {
        $result = $this->handleAction(function () use ($id) {
            return $this->supplementPusherService->push((int)$id);
        }, default: false);

        return $this->handleAjaxResponse($result, 'Domówienie zostało przesłane', 'Nie udało się przesłać domówienia');
    }
}
