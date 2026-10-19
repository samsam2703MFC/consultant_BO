<?php
namespace App\Services\Client\PriceList;

use App\Exceptions\CustomException;
use App\Models\Client\PriceList\PriceListItemModel;
use App\Repositories\Client\ClientRepository;
use App\Services\Product\ProductService;

class PriceListService
{

    public function __construct(
        private ExternalPriceListService $externalPriceListService,
        private ProductService $productService,
        private ClientRepository $clientRepository
    )
    {
    }


    public function getPriceListByClient($clientId, $date = null)
    {
        $client = $this->clientRepository->first(['id' => $clientId]);
        if(!$client){
            throw new CustomException("MISSING_CLIENT", 'errors', 404);
        }

        $products = $this->productService->getAll();
        $productList = [];
        if(!empty($productList)){
            foreach($productList as $product){
                $productList[] = new PriceListItemModel([
                    'id_product' => $product['id'],
                    'sku' => $product['id'],
                    'product_name' => $product['name'],
                    'price_net' => $product['standard_price'],
                    'vat_rate' => 0,
                    'package_size' => $product['ppc'],
                    'package_unit' => 'pcs',
                    'is_active' => true,
                    'valid_from' => null
                ]);
            }
        }

        if($client['franchisee_uid'] == null){
            return $productList;
        }


        $externalPriceList = $this->externalPriceListService->getPriceListForClientByDate($clientId, $date);
        foreach ($externalPriceList as $externalPriceListItem){
            $foundIndex = null;
            foreach ($productList as $index => $productListItem){
                if($productListItem->getIdProduct() == $externalPriceListItem->getIdProduct()){
                    $foundIndex = $index;
                    break;
                }
            }

            if($foundIndex !== null){
                $productList[$foundIndex] = $externalPriceListItem;
            } else {
                $productList[] = $externalPriceListItem;
            }
        }

        return $productList;
    }
}