<?php
namespace App\Controllers\Web\Material\Supplier;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Supplier\MaterialSupplierService;

class MaterialSupplierController extends BaseController
{

    private MaterialSupplierService $supplierService;

    public function __construct(
        MaterialSupplierService $supplierService
    )
    {
        $this->supplierService = $supplierService;
    }

    #[Route('GET', '/benchmarks/competitors')]
    public function overview()
    {
        $data['rows'] = $this->supplierService->getActive();

        $this->view("supplier/material/overview", $data);
    }

    public function newSupplier()
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            //$this->errors = $this->supplierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->supplierService->insert($_POST);
                    header('Location: ' . ROOT . '/material-suppliers');
                    exit;
                }, default: false);
            }
        }
        $this->view("supplier/material/new");
    }

    public function editSupplier($id)
    {
        $data['row'] = $this->supplierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
           // $this->errors = $this->supplierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id) {
                    $this->supplierService->update($id, $_POST);
                    header('Location: ' . ROOT . '/material-suppliers');
                    exit;
                }, default: false);
            }
        }
        $this->view("supplier/material/edit", $data);
    }

    public function deleteSupplier($id)
    {
        $data['row'] = $this->supplierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->supplierService->delete($id);
                header('Location: ' . ROOT . '/material-suppliers');
                exit;
            }, default: false);
        }

        $this->view("supplier/material/delete", $data);
    }


}