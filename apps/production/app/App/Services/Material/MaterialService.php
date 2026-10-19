<?php
namespace App\Services\Material;

use App\Repositories\Material\MaterialRepository;

class MaterialService
{
    private MaterialRepository $materialRepository;

    public function __construct(
        MaterialRepository $materialRepository
    )
    {
        $this->materialRepository = $materialRepository;
    }

    public function getAll()
    {
        return  $this->materialRepository->getAll();
    }
    
    public function validate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }

        if(empty($data['waste_perc']))
        {
            $errors['waste_perc'] = "Wprowadź procent użycia materiału";
        }

        if(empty($data['id_unit']))
        {
            $errors['id_unit'] = "Wybierz jednostkę";
        }

        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $errors['id_category'] = "Wybierz kategorię";
        }

        if(empty($data['pack_price']))
        {
            $errors['pack_price'] = "Wprowadź cenę";
        }

        if(empty($data['package_size_in_base_unit']))
        {
            $errors['package_size_in_base_unit'] = "Podaj wagę opakowania";
        }

        if(empty($data['id_current_supplier']))
        {
            $errors['id_current_supplier'] = "Wybierz obecnego dostawcę";
        }

        if(empty($data['energy_100g']))
        {
            $errors['energy_100g'] = "Wprowadź wartość energetyczną";
        }

        if(empty($data['fat_100g']))
        {
            $errors['fat_100g'] = "Wprowadź ilość tłuszczu";
        }

        if(empty($data['saturated_fat_100g']))
        {
            $errors['saturated_fat_100g'] = "Wprowadź ilość kwasów tłuszczowych nasyconych";
        }

        if(empty($data['carbohydrates_100g']))
        {
            $errors['carbohydrates_100g'] = "Wprowadź ilość węglowodanów";
        }

        if(empty($data['sugars_100g']))
        {
            $errors['sugars_100g'] = "Wprowadź ilość cukrów";
        }

        if(empty($data['salt_100g']))
        {
            $errors['salt_100g'] = "Wprowadź ilość soli";
        }

        if(empty($data['fiber_100g']))
        {
            $errors['fiber_100g'] = "Wprowadź ilość błonnika";
        }

        if(empty($data['proteins_100g']))
        {
            $errors['proteins_100g'] = "Wprowadź ilość białka";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function validateUpdate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }

        if(empty($data['waste_perc']))
        {
            $errors['waste_perc'] = "Wprowadź procent użycia materiału";
        }

        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $errors['id_category'] = "Wybierz kategorię";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function adjustData($data)
    {
        if(empty($data['energy_100g'])) $data['energy_100g'] = 0;
        if(empty($data['fat_100g'])) $data['fat_100g'] = 0;
        if(empty($data['saturated_fat_100g'])) $data['saturated_fat_100g'] = 0;
        if(empty($data['carbohydrates_100g'])) $data['carbohydrates_100g'] = 0;
        if(empty($data['sugars_100g'])) $data['sugars_100g'] = 0;
        if(empty($data['salt_100g'])) $data['salt_100g'] = 0;
        if(empty($data['fiber_100g'])) $data['fiber_100g'] = 0;
        if(empty($data['proteins_100g'])) $data['proteins_100g'] = 0;
        if(!isset($data['is_part_of_package'])) $data['is_part_of_package'] = 0;

        return $data;
    }

    public function insert($data)
    {
        $data['price_per_unit'] = $data['pack_price'] / $data['package_size_in_base_unit'];
        $data = $this->adjustData($data);

        return $this->materialRepository->insert($data);
    }

    public function update($id, $data)
    {
        $data = $this->adjustData($data);
        return $this->materialRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->materialRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->materialRepository->getById($id);
    }

    public function getByIdCategory($id)
    {
        return $this->materialRepository->getByIdCategory($id);
    }

    public function getAllWithNutritionalValues()
    {
        return $this->materialRepository->getAllWithNutritionalValues();
    }

    public function updateNutritionalValues(int $id, string $field, float $value): bool
    {
        $allowedFields = [
            'energy_100g', 'fat_100g', 'saturated_fat_100g',
            'carbohydrates_100g', 'sugars_100g', 'salt_100g',
            'fiber_100g', 'proteins_100g',
        ];

        if (!in_array($field, $allowedFields, true)) {
            return false;
        }

        return (bool)$this->materialRepository->update($id, [$field => $value]);
    }
}