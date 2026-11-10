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

    public function validate($data, bool $requireCost = true)
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = "Wprowadź imię";
        }

        if (empty($data['surname'])) {
            $errors['surname'] = "Wprowadź nazwisko";
        }

        // [256] Koszt pracodawcy wymagany tylko od osoby z prawem do płac (administrator) ; edycja karty go nie zmienia.
        if ($requireCost && empty($data['cost'])) {
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
        $result = $this->productionEmployeeRepository->insert($data);
        $this->productionEmployeeRepository->syncWorkshopHeadcount();
        return $result;
    }


    public function update($id, $data)
    {
        $result = $this->productionEmployeeRepository->update($id, $data);
        $this->productionEmployeeRepository->syncWorkshopHeadcount();
        return $result;
    }

    public function delete($id)
    {
        $result = $this->productionEmployeeRepository->delete($id);
        $this->productionEmployeeRepository->syncWorkshopHeadcount();
        return $result;
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