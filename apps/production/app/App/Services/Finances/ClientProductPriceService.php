<?php

namespace App\Services\Finances;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Material\Price\PriceListSyncQueueRepository;
use App\Repositories\Product\Price\FinalProductCustomerPriceRepository;

class ClientProductPriceService
{
    private FinalProductCustomerPriceRepository $finalProductCustomerPriceRepository;
    private TransactionManager $transactionManager;
    private ClientRepository $clientRepository;
    private PriceListSyncQueueRepository $priceListSyncQueueRepository;

    public function __construct(
        FinalProductCustomerPriceRepository $finalProductCustomerPriceRepository,
        TransactionManager                  $transactionManager,
        ClientRepository                    $clientRepository,
        PriceListSyncQueueRepository        $priceListSyncQueueRepository
    )
    {
        $this->finalProductCustomerPriceRepository = $finalProductCustomerPriceRepository;
        $this->transactionManager = $transactionManager;
        $this->clientRepository = $clientRepository;
        $this->priceListSyncQueueRepository = $priceListSyncQueueRepository;
    }

    public function getProductPriceByCustomer($id)
    {
        return $this->finalProductCustomerPriceRepository->getProductPriceByCustomer($id);
    }

    public function getActiveByClient($clientId)
    {
        return $this->finalProductCustomerPriceRepository->getActiveByClient($clientId);
    }

    public function selectProductPriceArrByCustomer($idClient)
    {
        return $this->finalProductCustomerPriceRepository->selectProductPriceArrByCustomer($idClient);
    }

    public function increatePriceByPercentage($id, $percentage)
    {
        $multiplier = (100 + $percentage) / 100;
        $res = $this->finalProductCustomerPriceRepository->increatePriceByMultipier($id, $multiplier);
        if (!$res) {
            throw new CustomException("UPDATE_PRICE_FAILED", 'errors', 500);
        }

        return $res;
    }

    public function updateForClient($idClient, $idProduct, $data)
    {
        return $this->transactionManager->execute(function () use ($idClient, $idProduct, $data) {

            $exist = $this->finalProductCustomerPriceRepository->first(['id_customer' => $data['id_customer'], 'id_final_product' => $data['id_final_product']]);
            if (!$exist) {
                $data['available'] = 0;
                $res = $this->finalProductCustomerPriceRepository->insert($data);
            } else {
                $res = $this->finalProductCustomerPriceRepository->updatePrice($data['id_customer'], $data['id_final_product'], $data['price']);
            }

            return $res;


            $client = $this->clientRepository->first(['id' => $idClient]);
            if (is_null($client['credentials'])) return $res;

//            $this->priceListSyncQueueRepository->schedulePricelistSync($idClient);

            return $res;
        });
    }

    public function updateAvailable($idClient, $idProduct, $data)
    {
        return $this->transactionManager->execute(function () use ($idClient, $idProduct, $data) {

            $exist = $this->finalProductCustomerPriceRepository->first(['id_customer' => $idClient, 'id_final_product' => $idProduct]);
            if (!$exist) {
                $res = $this->finalProductCustomerPriceRepository->insert([
                    'id_customer' => $idClient,
                    'id_final_product' => $idProduct,
                    'price' => 0,
                    'available' => $data['available']]);
            } else {
                $res = $this->finalProductCustomerPriceRepository->updateAvailable($idClient, $idProduct, $data['available']);
            }

            return $res;


            $client = $this->clientRepository->first(['id' => $idClient]);
            if (is_null($client['credentials'])) return $res;

//            $this->priceListSyncQueueRepository->schedulePricelistSync($idClient);

            return $res;
        });
    }

    public function getByClientAndProduct($idClient, $idProduct)
    {
        $res = $this->finalProductCustomerPriceRepository->getByClientAndProduct($idClient, $idProduct);
        if (!$res) {
            throw new CustomException("PRODUCT_PRICE_NOT_FOUND", 'errors', 404);
        }

        return $res[0];
    }

    public function getByClientAndProductCSV($idClient)
    {
        $client = $this->clientRepository->first(['id' => $idClient]);
        if (!$client) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 404);
        }


        $priceList = $this->finalProductCustomerPriceRepository->selectProductPriceArrByCustomer($idClient);
        if (!$priceList) {
            throw new CustomException("PRODUCT_PRICE_NOT_FOUND", 'errors', 404);
        }

        // Data wystawienia cennika
        $issuedAt = date('Y-m-d H:i');

        $headers = [
            "sku",
            "product_name",
            "gross_price",
            "net_price",
            "pack_quantity_in_base_unit"
        ];

        $output = fopen('php://temp', 'r+');

        // Wiersz informacyjny o dacie
        fputcsv($output, [ "meta_key", "meta_value"], ';');
        fputcsv($output, ["client_uid", $client['franchisee_uid']], ";");
        fputcsv($output, ["generated_at", $issuedAt], ";");
        fputcsv($output, ["currency", "EUR"], ";");
        fputcsv($output, [], ";");

        // Nagłówki kolumn
        fputcsv($output, $headers, ';');

        // Dane produktów
        foreach ($priceList as $row) {
            if ($row['is_available'] == 0 || $row['price'] == 0) continue;

            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['price'],
                $row['price'],
                $row['pieces_per_carton']
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        // Data w nazwie pliku
        $safeCompany = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client['company']);
        $filename = "price_list_{$safeCompany}_" . date('Ymd') . ".csv";

        return [
            'file_string' => $csv,
            'filename' => $filename
        ];
    }

    /**
     * Generate price list for client in JSON format
     * Includes full client data (with franchisee_uid), export timestamp, and products
     */
    public function getByClientAndProductJSON($idClient): array
    {
        $client = $this->clientRepository->first(['id' => $idClient]);
        if (!$client) {
            throw new CustomException("CLIENT_NOT_FOUND", 'errors', 404);
        }

        $priceList = $this->finalProductCustomerPriceRepository->selectProductPriceArrByCustomer($idClient);
        if (!$priceList) {
            throw new CustomException("PRODUCT_PRICE_NOT_FOUND", 'errors', 404);
        }

        // Przygotuj produkty (tylko dostępne i z ceną > 0)
        $products = [];
        foreach ($priceList as $row) {
            if ($row['is_available'] == 0 || $row['price'] == 0) continue;

            $products[] = [
                'sku' => $row['id'],
                'product_name' => $row['name'],
                'gross_price' => (float)$row['price'],
                'net_price' => (float)$row['price'],
                'pack_quantity_in_base_unit' => (int)$row['pieces_per_carton'],
                'is_available' => (int)$row['is_available']
            ];
        }

        // Data i godzina exportu
        $exportedAt = date('Y-m-d H:i:s');

        // Przygotuj pełne dane klienta
        $clientData = [
            'id' => (int)$client['id'],
            'company' => $client['company'] ?? '',
            'franchisee_uid' => $client['franchisee_uid'] ?? null,
            'franchisee_code' => $client['franchisee_code'] ?? null,
            'franchise_network_id' => $client['franchise_network_id'] ? (int)$client['franchise_network_id'] : null,
            'name' => $client['name'] ?? '',
            'surname' => $client['surname'] ?? '',
            'tax_number' => $client['tax_number'] ?? '',
            'email_head' => $client['email_head'] ?? '',
            'phone_head' => $client['phone_head'] ?? '',
            'default_currency' => $client['default_currency'] ?? 'EUR',
            'active' => (int)($client['active'] ?? 1)
        ];

        // Struktura JSON
        $jsonData = [
            'meta' => [
                'generated_at' => $exportedAt,
                'currency' => $client['default_currency'] ?? 'EUR',
                'products_count' => count($products)
            ],
            'client' => $clientData,
            'products' => $products
        ];

        return $jsonData;
    }
}

