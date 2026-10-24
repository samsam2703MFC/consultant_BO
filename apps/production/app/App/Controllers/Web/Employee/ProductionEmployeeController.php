<?php
namespace App\Controllers\Web\Employee;

use App\Core\Web\BaseController;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Employee\Section\ProductionSectionService;

class ProductionEmployeeController extends BaseController
{

    private ProductionEmployeeService $productionEmployeeService;
    private ProductionSectionService $productionSectionService;

    public function __construct(
        ProductionEmployeeService $productionEmployeeService,
        ProductionSectionService $productionSectionService
    )
    {
        $this->productionEmployeeService = $productionEmployeeService;
        $this->productionSectionService = $productionSectionService;
    }

    public function overview()
    {
        $data['rows'] = $this->productionEmployeeService->getAllWithSection();
        $data['production_sections'] = $this->productionSectionService->getAll();

        $this->view("employee/production/overview", $data);
    }

    public function newEmployee()
    {
        $data['production_sections'] = $this->productionSectionService->getAll();

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->productionEmployeeService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->productionEmployeeService->insert($_POST);
                    header('Location: ' . ROOT . '/production-employees');
                    exit;
                }, default: false);
            }
        }
        $this->view("employee/production/new", $data);
    }

    public function editEmployee($id)
    {
        $data['row'] = $this->productionEmployeeService->getById($id);
        $data['production_sections'] = $this->productionSectionService->getAll();

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->productionEmployeeService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id) {
                    $this->productionEmployeeService->update($id, $_POST);
                    header('Location: ' . ROOT . '/production-employees');
                    exit;
                }, default: false);
            }
        }
        $this->view("employee/production/edit", $data);
    }

    public function deleteEmployee($id)
    {
        $data['row'] = $this->productionEmployeeService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->productionEmployeeService->delete($id);
                header('Location: ' . ROOT . '/production-employees');
                exit;
            }, default: false);
        }

        $this->view("employee/production/delete", $data);
    }


}