<?php

namespace App\Api\Services\Client;

use App\Api\Core\Language;
use App\Api\Core\TransactionManager;
use App\Api\Exceptions\CustomException;
use App\Api\Repositories\Client\ClientRepository;
use App\Api\Repositories\Client\OfferRepository;
use App\Api\Repositories\Client\OfferProductRepository;
use App\Api\Repositories\Client\OrderProductRepository;
use App\Api\Repositories\Client\OrderRepository;
use App\Api\Repositories\Shop\ShopRepository;
use App\Api\Repositories\Utils\OfferPdf;
use CurrencySymbol;

class OfferService
{
    private $offerRepository;
    private $offerProductRepository;
    private $clientRepository;
    private $transactionManager;
    private $shopRepository;

    public function __construct(
        OfferRepository        $offerRepository,
        OfferProductRepository $offerProductRepository,
        ClientRepository       $clientRepository,
        TransactionManager     $transactionManager,
        ShopRepository         $shopRepository,
        private OrderRepository $orderRepository,
        private OrderProductRepository $orderProductRepository
    )
    {
        $this->offerRepository = $offerRepository;
        $this->offerProductRepository = $offerProductRepository;
        $this->clientRepository = $clientRepository;
        $this->transactionManager = $transactionManager;
        $this->shopRepository = $shopRepository;
    }

    public function getByShop($idShop, $includeProducts, $includeClient)
    {
        $offers = $this->offerRepository->getByShop($idShop);

        if ($includeProducts) {
            foreach ($offers as &$offer) {
                $products = $this->offerProductRepository->getProductsByOfferId($offer['id']);
                $offer['products'] = $products;
            }
        }

        if ($includeClient) {
            foreach ($offers as &$offer) {
                $client = $this->clientRepository->first(['id' => $offer['id_client']]);
                $offer['client'] = $client;
            }
        }

        return $offers;
    }

    public function insert($data)
    {
        if (isset($data['delivery_cost']) && $data['delivery_cost'] === '') {
            $data['delivery_cost'] = 0.00;
        }


        return $this->transactionManager->execute(function () use ($data) {
            $offerId = $this->offerRepository->insert($data);
            if (!$offerId) {
                throw new CustomException("FAILED_INSERT_OFFER", "client", 401);
            }

            if (isset($data['products']) && !empty($data['products'])) {
                $resProducts = $this->offerProductRepository->insertProductsForOffer($offerId, $data['products']);
                if (!$resProducts) {
                    throw new CustomException("FAILED_INSERT_PRODUCTS", "client", 401);
                }
            }

            return $offerId;
        });
    }

    public function update($id, $data)
    {
        return $this->transactionManager->execute(function () use ($id, $data) {
            $updated = $this->offerRepository->update($id, $data);
            if (!$updated) {
                throw new CustomException("FAILED_UPDATE_OFFER", "client", 401);
            }

            if (isset($data['products']) && !empty($data['products'])) {
                $resDelete = $this->offerProductRepository->delete($id, 'id_offer');
                if (!$resDelete) {
                    throw new CustomException("FAILED_DELETE_PRODUCTS", "client", 401);
                }

                $resProducts = $this->offerProductRepository->insertProductsForOffer($id, $data['products']);
                if (!$resProducts) {
                    throw new CustomException("FAILED_INSERT_PRODUCTS", "client", 401);
                }
            }

            return true;
        });
    }

    public function convertToOrder($idOffer, $data)
    {
        if(!isset($data['pick_up_datetime'])){
            throw new CustomException("MISSING_PICKUP_DATETIME", "client", 401);
        }


        return $this->transactionManager->execute(function () use ($idOffer, $data) {

            $idOrder = $this->orderRepository->insertFromOffer($idOffer, $data['pick_up_datetime'], $data['id_employee']);
            if (!$idOrder) {
                throw new CustomException("FAILED_CONVERT_OFFER_TO_ORDER", "client", 401);
            }

            $resProducts = $this->orderProductRepository->insertFromOffer($idOrder, $idOffer);
            if (!$resProducts) {
                throw new CustomException("FAILED_INSERT_ORDER_PRODUCTS", "client", 401);
            }

            $resUpdate = $this->offerRepository->update($idOffer, ['status' => 1]);
            if (!$resUpdate) {
                throw new CustomException("FAILED_UPDATE_OFFER_STATUS", "client", 401);
            }

            return true;
        });
    }

    public function delete($id)
    {
        return $this->transactionManager->execute(function () use ($id) {
            $deleted = $this->offerRepository->delete($id);
            if (!$deleted) {
                throw new CustomException("FAILED_DELETE_OFFER", "client", 401);
            }

            $resDelete = $this->offerProductRepository->delete($id, 'id_offer');
            if (!$resDelete) {
                throw new CustomException("FAILED_DELETE_PRODUCTS", "client", 401);
            }

            return true;
        });
    }

    public function getById($id, $includeProducts, $includeClient)
    {
        $offer = $this->offerRepository->first(['id' => $id]);

        if ($includeProducts) {
            $products = $this->offerProductRepository->getProductsByOfferId($offer['id']);
            $offer['products'] = $products;
        }

        if ($includeClient) {
            $client = $this->clientRepository->first(['id' => $offer['id_client']]);
            $offer['client'] = $client;
        }

        return $offer;
    }


    public function getPDF($id)
    {
        $currencySymbol = CurrencySymbol::getSymbol();

        $offerData = $this->getById($id, true, true);
        if (!$offerData || empty($offerData)) {
            throw new CustomException("MISSING_OFFER_DATA", "product", 404);
        }

        if (!isset($offerData['products']) || empty($offerData['products'])) {
            throw new CustomException("MISSING_OFFER_PRODUCTS", "product", 404);
        }

        if (!isset($offerData['client']) || empty($offerData['client'])) {
            throw new CustomException("MISSING_OFFER_CLIENT", "product", 404);
        }

        $client = $offerData['client'];
        $shopData = $this->shopRepository->getById($offerData['id_shop']);

        if (!$shopData || empty($shopData)) {
            throw new CustomException("MISSING_SHOP_DATA", "product", 404);
        }

        // Le devis aux couleurs de L'Atelier by (version C2, « une colonne »)
        $pdf = new OfferPdf($offerData, $shopData, $currencySymbol);
        $pdf->render();

        $filename = $this->generateFileName($offerData, $client);

        return [
            'pdf' => $pdf->Output($filename . '.pdf', 'S'),
            'filename' => $filename
        ];
    }

    private function generateFileName($offerData, $client)
    {
        $clientName = $client['is_b2b'] ? $client['company_name'] : $client['firstname'] . '_' . $client['lastname'];
        $filename = getTranslation('offer', 'offer', Language::getCallLanguageCode()) . "_{$offerData['id']}_{$clientName}";

        // Nettoyage du nom de fichier
        $filename = preg_replace('/[^\w\-_.]/', '_', $filename);
        $filename = preg_replace('/_{2,}/', '_', $filename);

        return $filename;
    }
}

//    public function getPDF($id)
//    {
//        $currencySymbol = CurrencySymbol::getSymbol();
//
//
//        $offerData = $this->getById($id, true, true);
//        if(!$offerData || empty($offerData)) {
//            throw new CustomException("MISSING_OFFER_DATA", "product", 404);
//        }
//
//
//        if(!isset($offerData['products']) || empty($offerData['products'])) {
//            throw new CustomException("MISSING_OFFER_PRODUCTS", "product", 404);
//        }
//
//        if(!isset($offerData['client']) || empty($offerData['client'])) {
//            throw new CustomException("MISSING_OFFER_CLIENT", "product", 404);
//        }
//
//        $client = $offerData['client'];
//
//
//        $shopData = $this->shopRepository->getById($offerData['id_shop']);
//        if(!$shopData || empty($shopData)) {
//            throw new CustomException("MISSING_SHOP_DATA", "product", 404);
//        }
//
//        $shop_details_string = "{$shopData['representative_name']}<br>{$shopData['street']} {$shopData['street_num']}<br>{$shopData['zip']}, {$shopData['city']}<br>{$shopData['tax_number_prefixe']}{$shopData['tax_number']}";
//
//        $pdf = new TCPDFModel('p', 'mm', 'a4', true, 'UTF-8', false);
//
//        $pdf->setMargins(1, 1, 1);
//        $pdf->setPrintFooter(false);
//        $pdf->setPrintHeader(false);
//
//        $pdf->AddPage();
//
//
//
//        $pdf->Image(SHARED_FILES_URL."/franchise-images/pdf-logo.jpg", 160, 5, 40, '', 'JPG', '','', false, 300, '', false, false, 0);
//
//
//        $pdf->writeHTMLCell(60, 30, 150, 45, $shop_details_string, 0, 1, 0, true, 'L', true);
//
//
//        if($client['is_b2b']){
//            $client['fullname'] = $client['company_name'];
//        } else {
//            $client['fullname'] = $client['firstname'] . " " . $client['lastname'];
//            $client['tax_number'] = "";
//        }
//
//        $pdf->SetFont('freeserif', '', 16);
//        $pdf->MultiCell(90, 10, "<b>Client</b>", 'B', "L", 0, 0, 6, 35, true, 0, true);
//
//        $clientDetailsString = "<h3>{$client['fullname']}</h3><br>{$client['tax_number']}<br><br>Contact: {$client['phone']}";
//
//        $pdf->SetFont('freeserif', '', 10);
//        $pdf->writeHTMLCell(90, 50, 6,47, $clientDetailsString, 0, 0, 0, true);
//
//        $pdf->SetFont('freeserif', '', 18);
//        $pdf->writeHTMLCell(100, 5, 55, 85, "Offre: " . "<b>#{$offerData['id']}</b>", 0, 1, 0, true, 'C', true);
//
//        $pdf->Ln(15);
//
//        $header = array(
//            "Produits",
//            "Quantité",
//            "Prix",
//            "Réduction [%]",
//            "Total");
//        $pdf->fillTableToOffer($header, $offerData['products']);
//
//        $pdf->Ln(25);
//
//
//        $pdf->SetFont('freeserif', '', 16);
//        $pdf->MultiCell(90, 10, "<b>Livraison</b>", 'B', "L", 0, 1, 6, '', true, 0, true);
//        $pdf->Ln(2);
//
//
//
//
//        if($offerData['in_store_pickup']==1){
//            $pdf->writeHTMLCell(90, 5, 5,'',"<h2>Retrait en magasin</h2><br><br>{$offerData['conditions']}", 0, 1, 0, true, 'C');
//            $pdf->Ln(9);
//        } else {
//
//            $deliveryData = "{$offerData['delivery_street']}<br>{$offerData['delivery_zip']} {$offerData['delivery_city']}<br><br><b>Frais de livraison:</b> {$offerData['delivery_cost']}{$currencySymbol}<br><br>{$offerData['conditions']}";
//
//            $pdf->SetFont('freeserif', '', 10);
//            $pdf->writeHTMLCell(90, 50, 6,'', $deliveryData, 0, 1, 0, true);
//        }
//
//
//
//        $pdf->SetFont('freeserif', '', 10);
//        $pdf->MultiCell(180, 25, "<b>Commentaire:</b> {$offerData['description']}", 0, "L", 0, 0, 6, '', true, 0, true);
//
//
//
//        $filename = "{$offerData['id']}_{$offerData['name']}_{$client['fullname']}";
//        $filename = preg_replace('/[^\w\-]/u', '_', $filename);
////        return [
////            'pdf' => $pdf->Output('xxx.pdf', 'I'),
////            'filename' => "offer_{$offerData['id']}"
////        ];
//        return [
//            'pdf' => $pdf->Output('xxx.pdf', 'S'),
//            'filename' => $filename
//        ];
//    }

