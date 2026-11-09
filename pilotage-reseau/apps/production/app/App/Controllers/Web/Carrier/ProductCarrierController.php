<?php
namespace App\Controllers\Web\Carrier;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Carrier\ProductCarrierService;
use App\Services\Carrier\ProductCarrierVehicleService;

class ProductCarrierController extends BaseController
{

    private ProductCarrierService $productCarrierService;
    private ProductCarrierVehicleService $productCarrierVehicleService;


    public function __construct(
        ProductCarrierService $productCarrierService,
        ProductCarrierVehicleService $productCarrierVehicleService
    )
    {
        $this->productCarrierService = $productCarrierService;
        $this->productCarrierVehicleService = $productCarrierVehicleService;
    }

    #[Route('GET', '/product-carriers')]
    public function overview()
    {
        $data['rows'] = $this->productCarrierService->getActive();

        $this->view("carrier/product/overview", $data);
    }

    public function newCarrier()
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->productCarrierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->productCarrierService->insert($_POST);
                    header('Location: ' . ROOT . '/product-carriers');
                    exit;
                }, default: false);
            }
        }
        $this->view("carrier/product/new");
    }

    public function editCarrier($id)
    {
        $data['row'] = $this->productCarrierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->productCarrierService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id) {
                    $this->productCarrierService->update($id, $_POST);
                    header('Location: ' . ROOT . '/product-carriers');
                    exit;
                }, default: false);
            }
        }
        $this->view("carrier/product/edit", $data);
    }

    public function deleteCarrier($id)
    {
        $data['row'] = $this->productCarrierService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->productCarrierService->delete($id);
                header('Location: ' . ROOT . '/product-carriers');
                exit;
            }, default: false);
        }

        $this->view("carrier/product/delete", $data);
    }


}