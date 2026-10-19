<?php
namespace App\Services\Employee;

use App\Repositories\Employee\ProductionEmployeeRepository;

class ProductionEmployeeService
{

    private ProductionEmployeeRepository $productionEmployeeRepository;

    public function __construct(
        ProductionEmployeeRepository $productionEmployeeRepository
    )
    {
        $this->productionEmployeeRepository = $productionEmployeeRepository;
    }

    public function validate($data)
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = "Wprowadź imię";
        }

        if (empty($data['surname'])) {
            $errors['surname'] = "Wprowadź nazwisko";
        }

        if (empty($data['cost'])) {
            $errors['cost'] = "Wprowadź koszt pracownika";
        }

        if (empty($data['id_section'])) {
            $errors['id_section'] = "Wybierz dział";
        }

        return $errors;
    }

    public function getAllWithSection()
    {
        return $this->productionEmployeeRepository->getAllWithSection();
    }

    public function insert($data)
    {
        return $this->productionEmployeeRepository->insert($data);
    }


    public function update($id, $data)
    {
        return $this->productionEmployeeRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->productionEmployeeRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->productionEmployeeRepository->first(['id' => $id]);
    }

    public function getCostBySection($id)
    {
        return $this->productionEmployeeRepository->getCostBySection($id);
    }
}