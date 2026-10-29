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

    /**
     * Avis « shipment.sent » à la boutique du franchisé, mis dans la file webhook_outbox_shipments (envoyé par
     * ShipmentOutboxDispatcher : Bin/mail_dispatch.php toutes les 5 min, Bin/shipments_retry.php). Même contenu =
     * même event_id : remettre en file une commande déjà avisée ne crée pas de doublon.
     * @return bool true = en file (ou déjà en file avec ce contenu)
     */
    public function enqueueInTransit($idOrder): bool
    {
        $event = $this->event((int) $idOrder);
        $this->outboxRepository->enqueue($event);
        return true;
    }

    /** @return array{id_order:int, shop_uid:string, url:string, event_id:string, payload_json:string} */
    public function event(int $idOrder): array
    {
        $order = $this->orderRepository->first(['id' => $idOrder]);
        if (!$order || empty($order['id_customer'])) {
            throw new CustomException("MISSING_ORDER", 'errors', 404);
        }
        if (empty($order['reference'])) {
            throw new CustomException("MISSING_ORDER_KEY", 'errors', 500);   // wymagany przez sklep
        }
        if (empty($order['id_wdt'])) {
            throw new CustomException("MISSING_INVOICE", 'errors', 404);
        }

        $carrierPayload = $this->getCarrierPayload($order['id_wdt'], $order['expected_shipping_date']);

        $client = $this->clientRepository->first(['id' => $order['id_customer']]);
        if (!$client) {
            throw new CustomException("MISSING_CLIENT", 'errors', 404);
        }
        if (empty($client['franchisee_uid'])) {
            throw new CustomException("CLIENT_NOT_INTEGRATED", 'errors', 404);
        }

        $credentials = json_decode($client['credentials'] ?? '', true);
        $secretHex = $credentials['secret'] ?? null;                 // ten sam klucz co w PricelistPusher
        $baseUrl = $credentials['webhooks_base_url'] ?? null;
        if (!$secretHex || !preg_match('/^[0-9a-f]{64}$/i', $secretHex) || !$baseUrl || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new CustomException("MISSING_CLIENT_CREDENTIALS", 'errors', 500);
        }

        // Snapshot WDT (kolejność stabilna : ten sam event_id przy ponownym wysłaniu tej samej treści).
        $products = [];
        foreach ($this->WDTProductRepository->getGroupedByProducts($order['id_wdt']) as $it) {
            $products[] = [
                'sku' => $it['id_product'],
                'name' => $it['name'],
                'quantity' => $it['quantity'],
                'pack_size' => $it['ppc'],
                'id_unit' => 5,
                'price_net' => $it['price_carton']
            ];
        }
        usort($products, fn($a, $b) => strcmp((string) $a['sku'], (string) $b['sku']));

        $raw = json_encode([
            'order_key' => $order['reference'],
            'sent_at' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'expected_date' => $order['expected_shipping_date'] ?? null,
            'carrier' => $carrierPayload,
            'products' => $products,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contentHash = hash('sha256', json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'id_order' => (int) $order['id'],
            'shop_uid' => (string) $client['franchisee_uid'],
            'url' => rtrim($baseUrl, '/') . '/webhooks/material-orders/shipment/sent',
            'event_id' => hash('sha256', $client['franchisee_uid'] . '|' . $order['reference'] . '|' . $contentHash),
            'payload_json' => $raw,
        ];
    }
}