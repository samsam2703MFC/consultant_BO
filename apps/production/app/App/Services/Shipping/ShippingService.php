<?php

namespace App\Services\Shipping;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Repositories\Document\Cmr\CMRPackagesRepository;
use App\Repositories\Document\Cmr\CMRRepository;
use App\Repositories\Document\Wdt\WDTProductRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Document\Wz\WZProductRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierVehicleRepository;
use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Repositories\Shipping\PaletDeliveryRepository;
use App\Repositories\Stock\Complaint\CartonStockComplaintRepository;
use App\Services\Client\PriceList\ExternalPriceListService;
use App\Services\Document\WZService;
use App\Services\Document\DifferenceSettlementService;

class ShippingService
{

    public function __construct(
        private ShippingLogRepository                    $shippingLogRepository,
        private TransactionManager                       $transactionManager,
        private ClientRepository                         $clientRepository,
        private ProductCarrierRepository                 $carrierRepository,
        private ProductCarrierVehicleRepository          $carrierVehicleRepository,
        private ErpCompanyDataRepository                 $erpCompanyDataRepository,
        private WZRepository                             $wzRepository,
        private WZProductRepository                      $wzProductRepository,
        private CMRRepository                            $cmrRepository,
        private CMRPackagesRepository                    $cmrPackagesRepository,
        private WDTRepository                            $wdtRepository,
        private WDTProductRepository                     $wdtProductRepository,
        private OrderShippingDifferenceRepository        $orderShippingDifferenceRepository,
        private OrderShippingDifferenceProductRepository $orderShippingDifferenceProductRepository,
        private PaletDeliveryRepository                  $paletDeliveryRepository,
        private OrderRepository                          $clientOrderRepository,
        private ExternalPriceListService                 $externalPriceListService,
        private ComplaintShipmentLogRepository           $complaintShipmentLogRepository,
        private CartonStockComplaintRepository           $cartonStockComplaintRepository,
        private DifferenceSettlementService              $differenceSettlementService,
        private ShipmentPackagingService                 $shipmentPackagingService
    )
    {
    }

    public function insertNewShipping($data)
    {
        return $this->transactionManager->execute(function () use ($data) {

            $carrier_sec_data = [
                'id_carrier_sec' => null,
                'carrier_sec_name' => "",
                'carrier_sec_address_1' => "",
                'carrier_sec_address_2' => "",
                'carrier_sec_regis_num' => "",
                'id_carrier_sec_vehicle' => null
            ];

            if (isset($data['id_carrier_sec']) && $data['id_carrier_sec'] != "-1") {
                $carrier_sec_resp = $this->carrierRepository->first(['id' => $data['id_carrier_sec']]);
                $carrier_sec_vehicle_resp = $this->carrierVehicleRepository->first(['id' => $data['id_carrier_sec_vehicle']]);
                $carrier_sec_data = [
                    'id_carrier_sec' => $data['id_carrier_sec'],
                    'carrier_sec_name' => $carrier_sec_resp['name'],
                    'carrier_sec_address_1' => $carrier_sec_resp['street'] . ", " . $carrier_sec_resp['street_num'],
                    'carrier_sec_address_2' => $carrier_sec_resp['zip'] . ", " . $carrier_sec_resp['town'],
                    'carrier_sec_regis_num' => $carrier_sec_vehicle_resp['regis_num'],
                    'id_carrier_sec_vehicle' => $data['id_carrier_sec_vehicle']
                ];
            }

            $carrier_data = $this->carrierRepository->first(['id' => $data['id_carrier']]);
            $carrier_vehicle_data = $this->carrierVehicleRepository->first(['id' => $data['id_carrier_vehicle']]);

            $erp_company_data = $this->erpCompanyDataRepository->first(['id' => 1]);
            $customer_data = $this->clientRepository->first(['id' => $data['id_customer']]);

            $resInsertSelectWz = $this->wzRepository->insertSelect([
                "id_customer" => $data['id_customer'],
                "consignor_name" => $erp_company_data['wz_company_name'],
                "consignor_address_1" => $erp_company_data['wz_company_address_1'],
                "consignor_address_2" => $erp_company_data['wz_company_address_2'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => ($customer_data['fac_street'] . ", " . $customer_data['fac_street_num']),
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "consignor_nip" => $erp_company_data['wz_company_nip'],
                "consignor_bank_1" => $erp_company_data['wz_company_bank_1'],
                "consignor_bank_2" => $erp_company_data['wz_company_bank_2'],
                "consignor_swift" => $erp_company_data['wz_company_swift'],
                "consignee_nip" => $customer_data['tax_number'],
                "established_date" => $data['shipping_date']
            ]);
            if (!$resInsertSelectWz) {
                throw new CustomException("INSERT_WZ_FAILED", 'errors', 500);
            }
            $wzInsertedId = $resInsertSelectWz;

            $resInsertWzProduct = $this->wzProductRepository->insertSelectForStandardShipping([
                "id_customer" => $data['id_customer'],
                "shipping_date" => $data['shipping_date'],
                "id_wz" => $wzInsertedId
            ]);
            if (!$resInsertWzProduct) {
                throw new CustomException("INSERT_WZ_PRODUCT_FAILED", 'errors', 500);
            }

            $resInsertCMR = $this->cmrRepository->insertSelect([
                "id_document_wz" => $wzInsertedId,
                "id_customer" => $data['id_customer'],
                "id_carrier" => $data['id_carrier'],
                "id_carrier_sec" => $carrier_sec_data['id_carrier_sec'],
                "consignor_name" => $erp_company_data['cmr_company_name'],
                "consignor_address_1" => $erp_company_data['cmr_company_address_1'],
                "consignor_address_2" => $erp_company_data['cmr_company_address_2'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => $customer_data['fac_street'] . ", " . $customer_data['fac_street_num'],
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "delivery_place" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "taking_over_place" => $erp_company_data['cmr_taking_over_place'],
                "carrier_name" => $carrier_data['name'],
                "carrier_address_1" => $carrier_data['street'] . ", " . $carrier_data['street_num'],
                "carrier_address_2" => $carrier_data['zip'] . ", " . $carrier_data['town'],
                "carrier_regis_num" => $carrier_vehicle_data['regis_num'],
                "id_carrier_vehicle" => $data['id_carrier_vehicle'],
                "carrier_sec_name" => $carrier_sec_data['carrier_sec_name'],
                "carrier_sec_address_1" => $carrier_sec_data['carrier_sec_address_1'],
                "carrier_sec_address_2" => $carrier_sec_data['carrier_sec_address_2'],
                "carrier_sec_regis_num" => $carrier_sec_data['carrier_sec_regis_num'],
                "id_carrier_sec_vehicle" => $carrier_sec_data['id_carrier_sec_vehicle'],
                "sender_instructions" => $erp_company_data['cmr_sender_instruction'],
                "carrier_reservation_observation" => $erp_company_data['cmr_carrier_reservation_observation'],
                "established_in" => $erp_company_data['cmr_established_in'],
                "established_date" => $data['shipping_date']
            ]);

            if (!$resInsertCMR) {
                throw new CustomException("INSERT_CMR_FAILED", 'errors', 500);
            }

            $cmrInsertedId = $resInsertCMR;

            $resInsertCMRPackages = $this->cmrPackagesRepository->insertSelectBasedOnWz([
                'id_document_cmr' => $cmrInsertedId,
                'id_document_wz' => $wzInsertedId
            ]);
            if (!$resInsertCMRPackages) {
                throw new CustomException("INSERT_CMR_PACKAGES_FAILED", 'errors', 500);
            }

            $resInsertWDT = $this->wdtRepository->insertSelectForStandardShipping([
                "id_customer_num" => $data['id_customer'],
                "id_customer" => $data['id_customer'],
                "consignor_name" => $erp_company_data['wdt_company_name'],
                "consignor_address_1" => $erp_company_data['wdt_company_address_1'],
                "consignor_address_2" => $erp_company_data['wdt_company_address_2'],
                "consignor_nip" => $erp_company_data['wdt_company_nip'],
                "consignor_bank" => $erp_company_data['wdt_company_bank_1'],
                "consignor_swift" => $erp_company_data['wdt_company_swift'],
                "established_date" => $data['shipping_date'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => ($customer_data['fac_street'] . ", " . $customer_data['fac_street_num']),
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "consignee_nip" => $customer_data['tax_number'],
                "invoiced_date" => $data['shipping_date'],
                "shipping_date" => $data['shipping_date'],
                "id_customer_pd" => $data['id_customer'],
                "shipping_date_pd" => $data['shipping_date'],
                "shipping_date_week" => $data['shipping_date'],
                "id_customer_total_net_price_1" => $data['id_customer'],
                "id_customer_total_net_price_2" => $data['id_customer'],
                "shipping_date_total_net_price" => $data['shipping_date'],
                "id_document_wz" => $wzInsertedId
            ]);
            if (!$resInsertWDT) {
                throw new CustomException("INSERT_WDT_FAILED", 'errors', 500);
            }
            $wdtInsertedId = $resInsertWDT;

            if ($customer_data['franchisee_uid'] == null) {
                $resInsertWDTProduct = $this->wdtProductRepository->insertSelectForStandardShipping([
                    'id_document_wdt' => $wdtInsertedId,
                    'shipping_date' => $data['shipping_date'],
                    'id_customer' => $data['id_customer'],
                    'id_customer_price' => $data['id_customer'],
                ]);
            } else {
                $products = $this->getProductsWithExternalPrices($wdtInsertedId, $data['shipping_date'], $data['id_customer'], $data['id_customer']);
                $resInsertWDTProduct = true;
                foreach ($products as $product) {
                    $inserted = $this->wdtProductRepository->insert($product);
                    if (!$inserted) {
                        $resInsertWDTProduct = false;
                        break;
                    }
                }
            }

            if (!$resInsertWDTProduct) {
                throw new CustomException("INSERT_WDT_PRODUCT_FAILED", 'errors', 500);
            }

            $resInsertDifference = $this->orderShippingDifferenceRepository->insert([
                'id_customer' => $data['id_customer'],
                'consignor_name' => $erp_company_data['cmr_company_name'],
                'consignor_address_1' => $erp_company_data['cmr_company_address_1'],
                'consignor_address_2' => $erp_company_data['cmr_company_address_2'],
                'consignor_nip' => $erp_company_data['wdt_company_nip'],
                'consignee_name' => $customer_data['company'],
                'consignee_address_1' => ($customer_data['fac_street'] . ", " . $customer_data['fac_street_num']),
                'consignee_address_2' => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                'consignee_nip' => $customer_data['tax_number'],
                'established_date' => $data['shipping_date']
            ]);
            if (!$resInsertDifference) {
                throw new CustomException("INSERT_ORDER_SHIPPING_DIFFERENCE_FAILED", 'errors', 500);
            }

            $diffInsertedId = $resInsertDifference;

            $resInsertShipmentLog = $this->shippingLogRepository->insert([
                'shipment_date' => $data['shipping_date'],
                'id_customer' => $data['id_customer'],
                'id_wz' => $wzInsertedId,
                'id_cmr' => $cmrInsertedId,
                'id_wdt' => $wdtInsertedId,
                'id_diff' => $diffInsertedId,
                'id_employee' => 1
            ]);
            if (!$resInsertShipmentLog) {
                throw new CustomException("INSERT_SHIPMENT_LOG_FAILED", 'errors', 500);
            }
            $shipmentLogId = $resInsertShipmentLog;

            $this->shipmentPackagingService->createSnapshot(
                (int)$shipmentLogId,
                (int)$wzInsertedId,
                1
            );

            $resUpdatePalettDelivery = $this->paletDeliveryRepository->updateStatusByDateAndCustomer($data['shipping_date'], $data['id_customer'], 3);
            if (!$resUpdatePalettDelivery) {
                throw new CustomException("UPDATE_PALET_DELIVERY_FAILED", 'errors', 500);
            }

            $resUpdateOrder = $this->clientOrderRepository->updateWdtIdByShippingDateAndCustomer($wdtInsertedId, $data['shipping_date'], $data['id_customer']);
            if (!$resUpdateOrder) {
                throw new CustomException("UPDATE_ORDER_FAILED", 'errors', 500);
            }

            $resInsertShippingDifferenceProduct = $this->differenceSettlementService->storeForWdt((int) $diffInsertedId, (int) $wdtInsertedId);
            if (!$resInsertShippingDifferenceProduct) {
                throw new CustomException("INSERT_SHIPPING_DIFFERENCE_PRODUCT_FAILED", 'errors', 500);
            }

            return $shipmentLogId;
        });
    }

    public function insertNewComplaint($data)
    {
        return $this->transactionManager->execute(function () use ($data) {

            $carrier_sec_data = [
                'id_carrier_sec' => null,
                'carrier_sec_name' => "",
                'carrier_sec_address_1' => "",
                'carrier_sec_address_2' => "",
                'carrier_sec_regis_num' => "",
                'id_carrier_sec_vehicle' => null
            ];

            if (isset($data['id_carrier_sec']) && $data['id_carrier_sec'] != "-1") {
                $carrier_sec_resp = $this->carrierRepository->first(['id' => $data['id_carrier_sec']]);
                $carrier_sec_vehicle_resp = $this->carrierVehicleRepository->first(['id' => $data['id_carrier_sec_vehicle']]);
                $carrier_sec_data = [
                    'id_carrier_sec' => $data['id_carrier_sec'],
                    'carrier_sec_name' => $carrier_sec_resp['name'],
                    'carrier_sec_address_1' => $carrier_sec_resp['street'] . ", " . $carrier_sec_resp['street_num'],
                    'carrier_sec_address_2' => $carrier_sec_resp['zip'] . ", " . $carrier_sec_resp['town'],
                    'carrier_sec_regis_num' => $carrier_sec_vehicle_resp['regis_num'],
                    'id_carrier_sec_vehicle' => $data['id_carrier_sec_vehicle']
                ];
            }

            $carrier_data = $this->carrierRepository->first(['id' => $data['id_carrier']]);
            $carrier_vehicle_data = $this->carrierVehicleRepository->first(['id' => $data['id_carrier_vehicle']]);

            $erp_company_data = $this->erpCompanyDataRepository->first(['id' => 1]);
            $customer_data = $this->clientRepository->first(['id' => $data['id_customer']]);

            $resInsertSelectWz = $this->wzRepository->insertSelect([
                "id_customer" => $data['id_customer'],
                "consignor_name" => $erp_company_data['wz_company_name'],
                "consignor_address_1" => $erp_company_data['wz_company_address_1'],
                "consignor_address_2" => $erp_company_data['wz_company_address_2'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => ($customer_data['fac_street'] . ", " . $customer_data['fac_street_num']),
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "consignor_nip" => $erp_company_data['wz_company_nip'],
                "consignor_bank_1" => $erp_company_data['wz_company_bank_1'],
                "consignor_bank_2" => $erp_company_data['wz_company_bank_2'],
                "consignor_swift" => $erp_company_data['wz_company_swift'],
                "consignee_nip" => $customer_data['tax_number'],
                "established_date" => $data['shipping_date']
            ]);
            if (!$resInsertSelectWz) {
                throw new CustomException("INSERT_WZ_FAILED", 'errors', 500);
            }
            $wzInsertedId = $resInsertSelectWz;

            $resInsertWzProduct = $this->wzProductRepository->insertSelectForComplaintShipping([
                "id_customer" => $data['id_customer'],
                "shipping_date" => $data['shipping_date'],
                "id_wz" => $wzInsertedId
            ]);
            if (!$resInsertWzProduct) {
                throw new CustomException("INSERT_WZ_PRODUCT_FAILED", 'errors', 500);
            }

            $resInsertCMR = $this->cmrRepository->insertSelect([
                "id_document_wz" => $wzInsertedId,
                "id_customer" => $data['id_customer'],
                "id_carrier" => $data['id_carrier'],
                "id_carrier_sec" => $carrier_sec_data['id_carrier_sec'],
                "consignor_name" => $erp_company_data['cmr_company_name'],
                "consignor_address_1" => $erp_company_data['cmr_company_address_1'],
                "consignor_address_2" => $erp_company_data['cmr_company_address_2'],
                "consignee_name" => $customer_data['company'],
                "consignee_address_1" => $customer_data['fac_street'] . ", " . $customer_data['fac_street_num'],
                "consignee_address_2" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "delivery_place" => $customer_data['fac_zip'] . " " . $customer_data['fac_town'] . ", " . $customer_data['fac_country'],
                "taking_over_place" => $erp_company_data['cmr_taking_over_place'],
                "carrier_name" => $carrier_data['name'],
                "carrier_address_1" => $carrier_data['street'] . ", " . $carrier_data['street_num'],
                "carrier_address_2" => $carrier_data['zip'] . ", " . $carrier_data['town'],
                "carrier_regis_num" => $carrier_vehicle_data['regis_num'],
                "id_carrier_vehicle" => $data['id_carrier_vehicle'],
                "carrier_sec_name" => $carrier_sec_data['carrier_sec_name'],
                "carrier_sec_address_1" => $carrier_sec_data['carrier_sec_address_1'],
                "carrier_sec_address_2" => $carrier_sec_data['carrier_sec_address_2'],
                "carrier_sec_regis_num" => $carrier_sec_data['carrier_sec_regis_num'],
                "id_carrier_sec_vehicle" => $carrier_sec_data['id_carrier_sec_vehicle'],
                "sender_instructions" => $erp_company_data['cmr_sender_instruction'],
                "carrier_reservation_observation" => $erp_company_data['cmr_carrier_reservation_observation'],
                "established_in" => $erp_company_data['cmr_established_in'],
                "established_date" => $data['shipping_date']
            ]);

            if (!$resInsertCMR) {
                throw new CustomException("INSERT_CMR_FAILED", 'errors', 500);
            }

            $cmrInsertedId = $resInsertCMR;

            $resInsertCMRPackages = $this->cmrPackagesRepository->insertSelectBasedOnWz([
                'id_document_cmr' => $cmrInsertedId,
                'id_document_wz' => $wzInsertedId
            ]);
            if (!$resInsertCMRPackages) {
                throw new CustomException("INSERT_CMR_PACKAGES_FAILED", 'errors', 500);
            }


            $resInsertShipmentLog = $this->complaintShipmentLogRepository->insert([
                'shipment_date' => $data['shipping_date'],
                'id_customer' => $data['id_customer'],
                'id_wz' => $wzInsertedId,
                'id_cmr' => $cmrInsertedId,
                'id_employee' => 1
            ]);
            if (!$resInsertShipmentLog) {
                throw new CustomException("INSERT_SHIPMENT_LOG_FAILED", 'errors', 500);
            }
            $shipmentLogId = $resInsertShipmentLog;

            $resUpdatePalettDelivery = $this->cartonStockComplaintRepository->updateStatusByDateAndCustomer($data['shipping_date'], $data['id_customer'], 2);
            if (!$resUpdatePalettDelivery) {
                throw new CustomException("UPDATE_PALET_DELIVERY_FAILED", 'errors', 500);
            }

            return $shipmentLogId;
        });
    }


    private function getProductsWithExternalPrices($id_document_wdt, $shipping_date, $id_customer, $id_customer_price)
    {
        $priceList = $this->externalPriceListService->getPriceListForClientByDate($id_customer, $shipping_date);
        $shippingProducts = $this->wdtProductRepository->selectForStandardShipping([
            'id_document_wdt'   => $id_document_wdt,
            'shipping_date'     => $shipping_date,
            'id_customer'       => $id_customer,
            'id_customer_price' => $id_customer_price,
        ]);

        // Brak listy cenowej – zwróć produkty z cenami standardowymi
        if (empty($priceList)) {
            return $shippingProducts;
        }

        // Zbuduj lookup: id_product => PriceListItemModel
        // Uwzględniamy tylko aktywne pozycje z ceną > 0
        $priceMap = [];
        foreach ($priceList as $item) {
            if ($item->getIsActive() && $item->getPriceNet() > 0) {
                $priceMap[(int)$item->getIdProduct()] = $item;
            }
        }

        // Mapuj ceny na produkty zamówienia
        foreach ($shippingProducts as &$product) {
            $productId = (int)$product['id_product'];

            if (!isset($priceMap[$productId])) {
                // Produkt nie istnieje w liście cenowej – zostaje cena standardowa
                continue;
            }

            /** @var \App\Models\Client\PriceList\PriceListItemModel $priceItem */
            $priceItem   = $priceMap[$productId];
            $priceNet    = (float)$priceItem->getPriceNet();
            $vatRate     = (float)$priceItem->getVatRate();
            $quantity    = (int)$product['quantity'];
            $ppc         = (int)$product['ppc'];

            $totalNet    = $priceNet * $quantity;
            $totalTva    = $totalNet * ($vatRate / 100);
            $priceBrut   = $priceNet * (1 + $vatRate / 100);
            $pricePerPiece = $ppc > 0 ? round($priceNet / $ppc, 2) : 0;

            $product['price_carton']        = $priceNet;
            $product['total_net']           = $totalNet;
            $product['total_tva']           = $totalTva;
            $product['price_brut']          = $priceBrut;
            $product['tva_perc']            = $vatRate;
            $product['price_per_piece']     = $pricePerPiece;
        }
        unset($product);

        return $shippingProducts;
    }
}
