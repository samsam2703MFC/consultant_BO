<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Repositories\Client\ClientRepository;
use App\Services\Document\WDTService;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Finances\ClientProductPriceService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Product\ProductService;
use App\Services\Recipe\RecipeService;
use App\Services\Utils\CompanyCostService;
use Symfony\Component\HttpFoundation\Request;


/**
 * product class
 */
class ProductController extends BaseController
{

    public function __construct(
        private ProductCategoryService   $productCategoryService,
        private ProductionSectionService $productionSectionService,
        private ProductService $productService,
        private RecipeService $recipeService,
        private ProductionEmployeeService $productionEmployeeService,
        private WDTService $wdtService,
        private ClientProductPriceService $clientProductPriceService
    )
    {
    }



    public function newProduct($idCategory = null)
    {
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['category_product'] = $this->productCategoryService->findAll();
        if(!is_null($idCategory)){
            $_POST['id_category'] = $idCategory;
        }

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            if ($this->productService->validate($_POST)) {
                $this->handleAction(function () {
                    $this->productService->insert($_POST);
                    header('Location: ' . ROOT . '/product-categories/' . $_POST['id_category'] . '/products');
                    exit;
                }, default: false);
            }
        }

        $this->view("product/product/new", $data);
    }

    public function editProduct($id)
    {
        $data['row'] = $this->productService->first($id);
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['category_product'] = $this->productCategoryService->findAll();
        // Jeden kreator produktu : edycja otwiera kartę produktu (produkt, logistyka, receptura, tryb,
        // grupa, etapy i czasy, zatwierdzenie) ; karta powstaje sama, jeśli jej jeszcze nie ma.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $data['row'] && empty($_GET['classic'])) {
            header('Location: ' . ROOT . '/workshop/products#/product/' . (int) $id);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->productService->validate($_POST)) {
                $success = $this->handleAction(function () use ($id) {
                    $this->productService->update($id, $_POST);
                    return true;
                }, default: false);

                if ($success) {
                    header('Location: ' . ROOT . '/product-categories/' . $_POST['id_category'] . '/products');
                    exit;
                }
            }
        }

        $this->view("product/product/edit", $data);
    }

    public function ajaxUpdateProduct($id)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->productService->update($id, $data);

        return $this->handleAjaxResponse($result, 'Produkt zaktualizowany', 'Nie udało się zaktualizować produktu');
    }

    public function deleteProduct($id)
    {
        $data['row'] = $this->productService->first($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->productService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/product-categories/' . $_POST['id_category'] . '/products');
                exit;
            }
        }

        $this->view("product/product/delete", $data);
    }

    public function filterByCategory($idCategory)
    {
        $data['rows'] = $this->productService->getByIdCategory($idCategory);
        $data['category'] = $this->productCategoryService->getById($idCategory);


        $this->view("product/product/filter", $data);
    }

    public function showAllProducts()
    {
        $data['rows'] = $this->productService->getAll("fp.name");

        $this->view("product/product/filter", $data);
    }

}
