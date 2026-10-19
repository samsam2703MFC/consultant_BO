<?php
namespace App\Controllers\Web\Carrier;

use App\Core\Web\BaseController;
use App\Services\Carrier\ProductCarrierVehicleService;


class ProductCarrierVehicleController extends BaseController
{

    private ProductCarrierVehicleService $productCarrierVehicleService;


    public function __construct(
        ProductCarrierVehicleService $productCarrierVehicleService
    )
    {
        $this->productCarrierVehicleService = $productCarrierVehicleService;
    }

    public function overview($idCarrier)
    {
        $data['rows'] = $this->productCarrierVehicleService->getByIdCarrier($idCarrier);
        $data['id_carrier'] = $idCarrier;

        $this->view("carrier/product/vehicle/overview", $data);
    }

    public function newVehicle($idCarrier)
    {
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $postData = $_POST;
            $postData['id_carrier'] = $idCarrier;

            $this->errors = $this->productCarrierVehicleService->validate($_POST);


            if (empty($this->errors)) {
                $this->handleAction(function () use ($idCarrier, $postData){
                    $this->productCarrierVehicleService->insert($postData);
                    header('Location: ' . ROOT . "/product-carriers/$idCarrier/vehicles");
                    exit;
                }, default: false);
            }
        }
        $this->view("carrier/product/vehicle/new");
    }

    public function editVehicle($id)
    {
        $data['row'] = $this->productCarrierVehicleService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->productCarrierVehicleService->validate($_POST);
            $postData = $_POST;

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id, $data, $postData) {
                    $this->productCarrierVehicleService->update($id, $postData);
                    header('Location: ' . ROOT . "/product-carriers/{$data['row']['id_carrier']}/vehicles");
                    exit;
                }, default: false);
            }
        }
        $this->view("carrier/product/vehicle/edit", $data);
    }

    public function deleteVehicle($id)
    {
        $data['row'] = $this->productCarrierVehicleService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id, $data) {
                $this->productCarrierVehicleService->delete($id);
                header('Location: ' . ROOT . "/product-carriers/{$data['row']['id_carrier']}/vehicles");
                exit;
            }, default: false);
        }

        $this->view("carrier/product/vehicle/delete", $data);
    }

    public function ajaxGetVehiclesByCarrier($id)
    {
        $resp = $this->productCarrierVehicleService->getByIdCarrier($id);

        if (!$resp) {
            return $this->json(['message' => 'Brak danych dla tej wartości'], 404);
        }

        return $this->json($resp);
    }
}