<?php
namespace App\Services\Carrier;

use App\Repositories\Shipping\Carrier\ProductCarrierRepository;

class ProductCarrierService
{

    private ProductCarrierRepository $productCarrierRepository;

    public function __construct(
        ProductCarrierRepository $productCarrierRepository
    )
    {
        $this->productCarrierRepository = $productCarrierRepository;
    }

    public function getActive()
    {
        return $this->productCarrierRepository->getActive();
    }
    
    public function validate($data)
    {

        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }

        if(empty($data['email']))
        {
            $errors['email'] = "Email jest wymagany";
        }else if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))
        {
            $errors['email'] = "Email jest nieprawidłowy";
        }

        if(empty($data['phone']))
        {
            $errors['phone'] = "Wprowadź telefon kontaktowy";
        }

        if(empty($data['street']))
        {
            $errors['street'] = "Wprowadź nazwę ulicy siedziby firmy";
        }

        if(empty($data['street_num']))
        {
            $errors['street_num'] = "Wprowadź numer budynku";
        }

        if(empty($data['zip']))
        {
            $errors['zip'] = "Wprowadź kod pocztowy";
        }

        if(empty($data['town']))
        {
            $errors['town'] = "Wprowadź miasto";
        }

        return $errors;
    }


    public function insert($data)
    {
        return $this->productCarrierRepository->insert($data);
    }


    public function update($id, $data)
    {
        return $this->productCarrierRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->productCarrierRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->productCarrierRepository->first(['id' => $id]);
    }
}