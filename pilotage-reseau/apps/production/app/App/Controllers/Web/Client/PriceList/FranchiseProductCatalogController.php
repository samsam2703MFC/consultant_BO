<?php

namespace App\Controllers\Web\Client\PriceList;

use App\Core\Web\BaseController;
use App\Services\Client\PriceList\FranchiseProductCatalogService;

class FranchiseProductCatalogController extends BaseController
{
    public function __construct(private FranchiseProductCatalogService $catalogService)
    {
    }

    public function overview()
    {
        $products = $this->catalogService->getOverview();

        $this->view('client/price_list/franchise_product_catalog', [
            'products' => $products,
            'statistics' => [
                'all' => count($products),
                'problems' => count(array_filter($products, static fn(array $product) => $product['has_problem'])),
                'discrepancies' => count(array_filter($products, static fn(array $product) => $product['has_discrepancy'])),
                'missing_price' => count(array_filter($products, static fn(array $product) => $product['missing_price_count'] > 0)),
            ],
        ]);
    }
}
