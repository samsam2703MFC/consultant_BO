<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\Test\TestProductStageService;
use Symfony\Component\HttpFoundation\Request;

class TestProductStageController extends BaseController
{
    private TestProductStageService $testProductStageService;

    public function __construct(
        TestProductStageService $testProductStageService
    )
    {
        $this->testProductStageService = $testProductStageService;
    }

    public function overview()
    {
        $data['rows'] = $this->testProductStageService->findAll();

        $this->view("product/test_product_stage/overview", $data);
    }

    public function newStage()
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            if ($this->testProductStageService->validate($_POST)) {
                $this->handleAction(function () {
                    $this->testProductStageService->insert($_POST);
                    header('Location: ' . ROOT . '/test-product-stages');
                    exit;
                }, default: false);
            }
        }

        $this->view("product/test_product_stage/new", []);
    }

    public function editStage($id)
    {
        $data['row'] = $this->testProductStageService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id'] = $id;
            if ($this->testProductStageService->validate($_POST)) {
                $success = $this->handleAction(function () use ($id) {
                    $res = $this->testProductStageService->update($id, $_POST);
                    return true;
                }, default: false);

                if ($success) {
                    header('Location: ' . ROOT . '/test-product-stages');
                    exit;
                }
            }
        }

        $this->view("product/test_product_stage/edit", $data);
    }

    public function deleteStage($id)
    {
        $data['row'] = $this->testProductStageService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->testProductStageService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/test-product-stages');
                exit;
            }
        }

        $this->view("product/test_product_stage/delete", $data);
    }

    public function ajaxUpdatePriority()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->testProductStageService->updatePriority($data);

        return $this->handleAjaxResponse($result, 'Produkt zaktualizowany', 'Nie udało się zaktualizować produktu');
    }

}