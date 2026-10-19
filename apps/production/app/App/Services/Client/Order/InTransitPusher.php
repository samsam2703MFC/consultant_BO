<?php
namespace App\Services\Client\Order;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderProductRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Client\Order\ShipmentOutboxRepository;
use App\Repositories\Document\Cmr\CMRRepository;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierVehicleRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;

class InTransitPusher
{
    public function __construct(
        private OrderRepository $orderRepository,
        private WDTProductRepository $WDTProductRepository,
        private ClientRepository $clientRepository,
        private ShippingLogRepository $shippingLogRepository,
        private CMRRepository $cmrRepository,
        private ProductCarrierRepository $carrierRepository,
        private ProductCarrierVehicleRepository $carrierVehicleRepository,
        private ShipmentOutboxRepository $outboxRepository
    )
    {
    }

    public function getCarrierPayload($idWdt, $shippingDate)
    {
        $shippingLog = $this->shippingLogRepository->first(['shipment_date' => $shippingDate, 'id_wdt' => $idWdt]);
        if (!$shippingLog) {
            throw new CustomException("MISSING_SHIPPING_LOG", 'errors', 404);
        }

        $cmr = $this->cmrRepository->first(['id' => $shippingLog['id_cmr']]);
        if (!$cmr) {
            throw new CustomException("MISSING_CMR", 'errors', 404);
        }

        $carrier = $this->carrierRepository->first(['id' => $cmr['id_carrier']]);
        if (!$carrier) {
            return [
                'name' => 'Produkcja',
                'phone' => '',
                'driver_phone' => '',
                'regis_num' => ''
            ];
        }

        $vehicle = $this->carrierVehicleRepository->first(['id' => $cmr['id_carrier_vehicle']]);
        if (!$vehicle){
            $regis_num = '';
            $driverPhone = '';

        } else {
            $regis_num = $vehicle['regis_num'] ?? '';
            $driverPhone = $vehicle['driver_phone'] ?? '';
        }

        return    [
            'name' => $carrier['name'] ?? '',
            'phone' => $carrier['phone'] ?? '',
            'driver_phone' => $driverPhone,
            'regis_num' => $regis_num
        ];
    }

    public function pushInTransit($idOrder)
    {
        //1) Pobierz order + client + credentials
        $order = $this->orderRepository->first(['id' => $idOrder]);
        if (!$order || empty($order['id_customer'])) {
            throw new CustomException("MISSING_ORDER", 'errors', 404);
        }
        if (empty($order['reference'])) {
            // wymagany przez odbiorcę
            throw new CustomException("MISSING_ORDER_KEY", 'errors', 500);
        }
        if (empty($order['id_wdt'])) {
            // u Ciebie to warunek biznesowy – zostawiam
            throw new CustomException("MISSING_INVOICE", 'errors', 404);
        }

        $carrierPayload = $this->getCarrierPayload($order['id_wdt'], $order['expected_shipping_date']);

        $client = $this->clientRepository->first(['id' => $order['id_customer']]);
        if (!$client) {
            throw new CustomException("MISSING_CLIENT", 'errors', 404);
        } if (empty($client['franchisee_uid'])) {
            throw new CustomException("CLIENT_NOT_INTEGRATED", 'errors', 404);
        }

        $credentials = json_decode($client['credentials'] ?? '', true);
        $secretHex   = $credentials['secret'] ?? null;                 // ten sam klucz co w PricelistPusher
        $baseUrl     = $credentials['webhooks_base_url'] ?? null;

        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }
        if (!$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        // 2) Zbuduj snapshot (upewnij się, że kolejność jest stabilna!)
        $items = $this->WDTProductRepository->getGroupedByProducts($order['id_wdt']);

        $products = [];

        foreach ($items as &$it) {
            $products[] = [
                'sku' => $it['id_product'],
                'name' => $it['name'],
                'quantity' => $it['quantity'],
                'pack_size' => $it['ppc'],
                'id_unit' => 5,
                'price_net' => $it['price_carton']
            ];
        }
        usort($products, fn($a,$b) => strcmp($a['sku'],$b['sku']));     // stabilna kolejność


        $payload = [
            'order_key'     => $order['reference'],
            'sent_at'       => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'expected_date' => $order['expected_shipping_date'] ?? null,          // jeżeli masz
            'carrier'       => $carrierPayload,                        // jeżeli masz
            'products'      => $products,
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 4) Idempotencja eventu – hash treści (deterministyczny)
        $contentHash = hash('sha256', json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $eventId     = hash('sha256', $client['franchisee_uid'].'|'.$order['reference'].'|'.$contentHash);

        // 5) HMAC – obowiązkowy
        $signature = hash_hmac('sha256', $raw, hex2bin($secretHex));

        $webhookEndpoint = '/webhooks/material-orders/shipment/sent';

        // 6) POST
        $url = rtrim($baseUrl, '/') . $webhookEndpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Event-ID: '.$eventId,
                'X-Event-Type: shipment.sent',
                'X-Shop-Uid: '.$client['franchisee_uid'],
                'X-Signature: '.$signature,
            ],
            CURLOPT_POSTFIELDS     => $raw,
        ]);
        $body = curl_exec($ch);
        
        $err  = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== 0 || $code < 200 || $code >= 300) {
            $this->outboxRepository->enqueue([
                'id_order'     => (int)$order['id'],
                'shop_uid'     => $client['franchisee_uid'],
                'url'          => rtrim($baseUrl, '/') . $webhookEndpoint,
                'event_id'     => $eventId,                 // TEN SAM przy retry
                'payload_json' => $raw                     // dokładny JSON
            ]);
            return false;
        }

        return true;
    }
}