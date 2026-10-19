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

    public function profitability($id)
    {

        $product = $this->productService->getById($id);

        $data['product_data'] = $product;
        $data['product_data']['price'] = $this->clientProductPriceService->getProductPriceByCustomer($id);
        $data['recipe_price'] = $this->recipeService->getRecipeSummary($product['id_recipe'])['total_ingredient_cost'];
        $data['employee_cost'] = $this->productionEmployeeService->getCostBySection($product['id_section']);
        $data['sales_data_last_30_days'] = $this->wdtService->getSoldDataInLast30Days($id);
        $data['section_cost'] = $this->productionSectionService->getById($product['id_section']);

        $data['sum_direct_cost'] = ($data['recipe_price'] + $data['employee_cost']);
        $data['sum_indirect_fixed_cost'] = $data['section_cost']['depreciation'];
        $data['sum_indirect_variable_cost'] = ($data['section_cost']['water_cost'] + $data['section_cost']['power_supply_cost'] + $data['section_cost']['fuel_cost'] + $data['section_cost']['other_cost']);

        $this->view("product/product/profitability", $data);
    }
}
