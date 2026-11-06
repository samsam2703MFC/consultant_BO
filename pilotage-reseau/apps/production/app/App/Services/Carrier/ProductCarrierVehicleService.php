<?php
namespace App\Services\Carrier;

use App\Repositories\Shipping\Carrier\ProductCarrierVehicleRepository;

class ProductCarrierVehicleService
{
    
    private ProductCarrierVehicleRepository $productCarrierVehicleRepository;
    
    public function __construct(
        ProductCarrierVehicleRepository $productCarrierVehicleRepository
    ) {
        $this->productCarrierVehicleRepository = $productCarrierVehicleRepository;
    }

    public function validate($data)
    {

        $errors = [];

        if (empty($data['regis_num'])) {
            $errors['regis_num'] = "Wprowadź numer rejestracyjny pojazdu";
        }

        return $errors;
    }
    
    public function getByIdCarrier($id)
    {
        return $this->productCarrierVehicleRepository->getByIdCarrier($id);
    }

    public function insert($data)
    {
        return $this->productCarrierVehicleRepository->insert($data);
    }

    public function update($id, $data)
    {
        return $this->productCarrierVehicleRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->productCarrierVehicleRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->productCarrierVehicleRepository->first(['id' => $id]);
    }
    
}