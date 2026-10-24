<?php
namespace App\Services\Supplier;

use App\Repositories\Supplier\MaterialSupplierRepository;

class MaterialSupplierService
{

    private MaterialSupplierRepository $supplierRepository;

    public function __construct(
        MaterialSupplierRepository $supplierRepository
    )
    {
        $this->supplierRepository = $supplierRepository;
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

        return $errors;
    }

    public function getActive()
    {
        return $this->supplierRepository->getActive();
    }

    public function insert($data)
    {
        $data['is_active'] = 1;
        if(!isset($data['email']) || $data['email'] == "") $data['email'] = "";
        if(!isset($data['phone']) || $data['phone'] == "") $data['phone'] = "";

        return $this->supplierRepository->insert($data);
    }


    public function update($id, $data)
    {
        if(!isset($data['email']) || $data['email'] == "") $data['email'] = "";
        if(!isset($data['phone']) || $data['phone'] == "") $data['phone'] = "";
        return $this->supplierRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->supplierRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->supplierRepository->first(['id' => $id]);
    }
}