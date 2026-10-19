<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Product\ProductService;
use App\Services\Product\Test\TestProductService;
use App\Services\Product\Test\TestProductStatusService;
use App\Services\Product\Test\TestProductTypeService;

class TestProductController extends BaseController
{
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

    public function newProduct()
    {
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['category_product'] = $this->productCategoryService->findAll();
        $data['product_status'] = $this->testProductStatusService->findAll();
        $data['product_type'] = $this->testProductTypeService->findAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->testProductService->validate($_POST)) {
                $dataToInsert = $_POST;

                $newId = $this->handleAction(function () use ($dataToInsert) {
                    return $this->testProductService->insert($dataToInsert);
                }, default: false);

                if ($newId) {
                    header('Location: ' . ROOT . '/test-products');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $this->view("product/test_product/new", $data);
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
                    header('Location: ' . ROOT . '/test-products');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

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

    public function overview()
    {
        $data['rows'] = $this->testProductService->findAll();
        $data['product_status'] = $this->testProductStatusService->findAll();
        $data['product_type'] = $this->testProductTypeService->findAll();

        $this->view("product/test_product/overview", $data);
    }
}