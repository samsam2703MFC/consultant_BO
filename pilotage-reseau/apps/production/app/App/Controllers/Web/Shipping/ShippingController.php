<?php
namespace App\Controllers\Web\Shipping;


use App\Core\Web\BaseController;
use App\Repositories\Stock\Complaint\CartonStockComplaintRepository;
use App\Services\Shipping\CartonStockComplaintService;
use App\Services\Shipping\Pallet\PalletDeliveryService;
use App\Services\Shipping\ShippingService;
use Symfony\Component\HttpFoundation\Request;

class ShippingController extends BaseController
{

    public function __construct(
        private ShippingService $shippingService,
        private PalletDeliveryService $palletDeliveryService,
        private CartonStockComplaintService $cartonStockComplaintService,
        private \App\Repositories\Client\Order\OrderRepository $orders
    )
    {
    }


    public function ajaxGetClients()
    {
        if(isset($_GET['shipping_date'])){
            $resp = $this->palletDeliveryService->getCustomerByShippingDate($_GET['shipping_date']);
        } else {
            $resp = [];
        }

        if (!$resp) {
            // jeżeli coś poszło nie tak, zwracamy JSON z kodem błędu
            return $this->json(['message' => 'Brak danych dla tej wartości'], 404);
        }

        return $this->json($resp);
    }

    public function ajaxGetPendingProducts()
    {
        $clientId     = isset($_GET['client_id'])    ? (int)$_GET['client_id']    : null;
        $shippingDate = isset($_GET['shipping_date']) ? $_GET['shipping_date']     : null;

        if (!$clientId || !$shippingDate) {
            return $this->json(['message' => 'Brak wymaganych parametrów: client_id, shipping_date'], 400);
        }

        $products   = $this->palletDeliveryService->getPendingProductsPreview($shippingDate, $clientId);
        $otherDates = $this->palletDeliveryService->getOtherActiveOrderDatesByCustomer($shippingDate, $clientId);

        return $this->json([
            'products'      => $products,
            'has_pallets'   => count($products) > 0,
            'all_complete'  => count($products) > 0 && count(array_filter($products, fn($p) => (int)$p['quantity_diff'] !== 0)) === 0,
            'other_dates'   => $otherDates,
            // [442] Zaległe zamówienia klienta (data przed dniem wysyłki, nadal otwarte) : do zamknięcia razem z wysyłką.
            'late_orders'   => array_map(fn($o) => ['id' => (int) $o['id'], 'shipping_date' => $o['shipping_date'], 'cartons' => (float) $o['cartons'],
                'freebie' => (int) $o['is_freebie'] === 1, 'reference' => $o['reference']], $this->orders->lateOpenOrders($clientId, (string) $shippingDate)),
        ]);
    }



    public function ajaxGetComplaintCustomersByDate()
    {
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $resp = $this->cartonStockComplaintService->getCustomerByShippingDate($date);

        if (!$resp) {
            // jeżeli coś poszło nie tak, zwracamy JSON z kodem błędu
            return $this->json(['message' => 'Brak danych dla tej wartości'], 404);
        }

        return $this->json($resp);
    }

    public function ajaxNewShipping()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $idShippingLog = $this->safeFetch([$this->shippingService, 'insertNewShipping'], $this->errors, [$data]);

        if (!$idShippingLog) {
            return $this->json(['message' => implode(", ", $this->errors) ?: 'Nie udało się utworzyć dokumentów wysyłki'], 500);
        }

        return $this->json(['id' => $idShippingLog, 'shop_notices' => $this->shippingService->lastShopNotices()]);
    }

    public function ajaxNewComplaint()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $idShippingLog = $this->safeFetch([$this->shippingService, 'insertNewComplaint'], $this->errors, [$data]);

        if (!$idShippingLog) {
            return $this->json(['message' => implode(", ", $this->errors) ?: 'Nie udało się utworzyć dokumentów wysyłki'], 500);
        }

        return $this->json(['id' => $idShippingLog]);
    }
}
