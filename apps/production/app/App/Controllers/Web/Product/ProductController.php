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
            $data['errors'] = $this->productService->validationErrors($_POST);
            if (!$data['errors']) {
                $this->handleAction(function () {
                    $id = $this->productService->insert($_POST);
                    // Dalej w Karcie produktu (tryb, grupa, etapy i czasy) — jedyne miejsce edycji produktu
                    header('Location: ' . ROOT . ($id && $id !== true ? '/workshop/products#/product/' . (int) $id : '/workshop/products#/?category=' . (int) $_POST['id_category']));
                    exit;
                }, default: false);
            }
        }

        $this->view("product/product/new", $data);
    }

    /**
     * Jedna karta produktu : edycja zawsze w Karcie produktu (produkt, logistyka, receptura, tryb, grupa,
     * etapy i czasy, zatwierdzenie — z historią i zmianą grupy) ; dawny formularz klasyczny usunięty.
     */
    public function editProduct($id)
    {
        header('Location: ' . ROOT . ($this->productService->first($id) ? '/workshop/products#/product/' . (int) $id : '/workshop/products'));
        exit;
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
                header('Location: ' . ROOT . '/workshop/products#/?erp=all&category=' . (int) ($data['row']['id_category'] ?? 0));
                exit;
            }
        }

        $this->view("product/product/delete", $data);
    }

}
