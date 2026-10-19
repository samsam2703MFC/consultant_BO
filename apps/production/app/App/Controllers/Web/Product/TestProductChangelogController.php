<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\Test\TestProductChangelogService;
use App\Services\Product\Test\TestProductStageService;

class TestProductChangelogController extends BaseController
{
    private TestProductChangelogService $testProductChangelogService;
    private TestProductStageService $testProductStageService;

    public function __construct(
        TestProductChangelogService $testProductChangelogService,
        TestProductStageService     $testProductStageService
    )
    {
        $this->testProductChangelogService = $testProductChangelogService;
        $this->testProductStageService = $testProductStageService;
    }

    public function overview($idProduct)
    {
        $data['rows'] = $this->testProductChangelogService->selectChangelogForProduct($idProduct);
        $data['id_product'] = $idProduct;

        $this->view("product/test_product_changelog/overview", $data);
    }

    public function newLog($idProduct)
    {
        $data['test_product_stages'] = $this->testProductStageService->findAll();
        $data['id_product'] = $idProduct;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_test_product'] = $idProduct;

            if ($this->testProductChangelogService->validateInsert($_POST)) {
                $dataToInsert = $_POST;

                $success = $this->handleAction(function () use ($dataToInsert) {
                    $this->testProductChangelogService->insert($dataToInsert);
                    return true;
                }, default: false);

                if ($success) {
                    header('Location: ' . ROOT . '/test-products/' . $idProduct . '/changelogs');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $this->view("product/test_product_changelog/new", $data);
    }

    public function editLog($idProduct, $id)
    {
        $data['test_product_stages'] = $this->testProductStageService->findAll();
        $data['row'] = $this->testProductChangelogService->getById($id);
        $data['id_product'] = $idProduct;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_test_product'] = $idProduct;
            $_POST['id'] = $id;

            if ($this->testProductChangelogService->validate($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    $this->testProductChangelogService->update($id, $dataToUpdate);
                    return true;
                }, default: false);

                if ($success) {
                    header('Location: ' . ROOT . '/test-products/' . $idProduct . '/changelogs');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $this->view("product/test_product_changelog/edit", $data);
    }

    public function deleteLog($idProduct, $id)
    {
        $data['test_product_stages'] = $this->testProductStageService->findAll();
        $data['row'] = $this->testProductChangelogService->getById($id);
        $data['id_product'] = $idProduct;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_test_product'] = $idProduct;

            $success = $this->handleAction(function () use ($id) {
                $this->testProductChangelogService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/test-products/' . $idProduct . '/changelogs');
                exit;
            }
        }

        $this->view("product/test_product_changelog/delete", $data);
    }

//    public function getPDF($id = null)
//    {
//        $userModel = new UserModel();
//        if(!$userModel->logged_in() || $id == null)
//            redirect('login');
//
//        $testProductChangelogModel = new TestProductChangelogModel();
//        $testProductChangelogModel->getFullChangelogForProductPDF(['id_test_product' => $id]);
//    }
//
//    public function getChangelogPDF($id = null)
//    {
//        $userModel = new UserModel();
//        if(!$userModel->logged_in() || $id == null)
//            redirect('login');
//
//        $testProductChangelogModel = new TestProductChangelogModel();
//        $testProductChangelogModel->getSelectedChangelogPDF(['id' => $id]);
//
//    }
}