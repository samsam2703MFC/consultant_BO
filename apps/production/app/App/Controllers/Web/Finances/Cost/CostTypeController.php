<?php


namespace App\Controllers\Web\Finances\Cost;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Material\AllergenService;
use App\Services\Product\ProductService;
use App\Services\Product\Category\ProductCategoryService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CostTypeController extends BaseController
{
    public function __construct(
        private CostTypeService $costTypeService
    )
    {
    }

    #[Route('GET', '/cost-types')]
    public function overview()
    {
        // Pobierz wszystkie produkty wraz z kategoriami
        $data['products'] = $this->productService->getAll();

        // Pobierz wszystkie kategorie do filtrowania
        $data['categories'] = $this->productCategoryService->findAll();

        $this->view("finances/price-structure/overview", $data);
    }
}