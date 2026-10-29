<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Product\ProductService;
use App\Services\Product\Test\TestProductService;
use App\Services\Product\Test\TestProductStatusService;
use App\Services\Product\Test\TestProductTypeService;
use Symfony\Component\HttpFoundation\Request;

class TestProductController extends BaseController
{
    use JsonErrors;

    private TestProductService $testProductService;
    private ProductCategoryService $productCategoryService;
    private ProductionSectionService $productionSectionService;
    private ProductService $productService;
    private TestProductStatusService $testProductStatusService;
    private TestProductTypeService $testProductTypeService;

    public function __construct(
        ProductCategoryService   $productCategoryService,
        ProductionSectionService $productionSectionService,
        ProductService           $productService,
        TestProductService       $testProductService,
        TestProductStatusService $testProductStatusService,
        TestProductTypeService   $testProductTypeService

    )
    {
        $this->productCategoryService = $productCategoryService;
        $this->productionSectionService = $productionSectionService;
        $this->productService = $productService;
        $this->testProductService = $testProductService;
        $this->testProductStatusService = $testProductStatusService;
        $this->testProductTypeService = $testProductTypeService;
    }

    public function editProduct($id)
    {
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['category_product'] = $this->productCategoryService->findAll();
        $data['product_status'] = $this->testProductStatusService->findAll();
        $data['product_type'] = $this->testProductTypeService->findAll();
        $data['row'] = $this->testProductService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->testProductService->validate($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    return $this->testProductService->update($id, $dataToUpdate);
                }, default: false);

                if ($success) {
                    // Z karty produktu testowego (?from=card) : powrót na kartę, nie na listę [201].
                    header('Location: ' . ROOT . (($_GET['from'] ?? '') === 'card' ? '/test-products/' . (int) $id . '/tablet' : '/test-products'));
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $data['back_url'] = ROOT . (($_GET['from'] ?? '') === 'card' ? '/test-products/' . (int) $id . '/tablet' : '/test-products');
        $this->view("product/test_product/edit", $data);
    }

    public function deleteProduct($id)
    {
        $data['row'] = $this->testProductService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $success = $this->handleAction(function () use ($id) {
                return $this->testProductService->delete($id);
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/test-products');
                exit;
            }

        }

        $this->view("product/test_product/delete", $data);
    }

    /** PATCH /ajax/test-products/{id} : zmiana statusu z listy produktów testowych. */
    public function ajaxEditProduct($id)
    {
        try {
            $data = $this->getJson(Request::createFromGlobals()) ?: [];
            if (!$this->testProductService->getById((int) $id)) {
                return $this->json(['message' => 'Nie znaleziono produktu testowego.'], 404);
            }
            $statusId = filter_var($data['id_status'] ?? null, FILTER_VALIDATE_INT);
            if ($statusId === false || !in_array($statusId, array_map('intval', array_column($this->testProductStatusService->findAll() ?: [], 'id')), true)) {
                return $this->json(['message' => 'Nieznany status.'], 422);
            }
            $this->testProductService->update((int) $id, ['id_status' => $statusId]);
            return $this->json(['message' => 'Zapisano status.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function overview()
    {
        $data['rows'] = $this->testProductService->findAll();
        $data['product_status'] = $this->testProductStatusService->findAll();
        $data['product_type'] = $this->testProductTypeService->findAll();

        $this->view("product/test_product/overview", $data);
    }
}