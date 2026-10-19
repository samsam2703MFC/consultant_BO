<?php


namespace App\Controllers\Web\Finances\Product;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Material\AllergenService;
use App\Services\Product\ProductService;
use App\Services\Product\Category\ProductCategoryService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PriceStructureController extends BaseController
{
    public function __construct(
        private ProductService $productService,
        private ProductCategoryService $productCategoryService
    )
    {
    }

    #[Route('GET', '/products/price-structure')]
    public function overview()
    {
        // Pobierz wszystkie produkty wraz z kategoriami
        $data['products'] = $this->productService->getAll();

        // Pobierz wszystkie kategorie do filtrowania
        $data['categories'] = $this->productCategoryService->findAll();

        $this->view("finances/price-structure/overview", $data);
    }
}