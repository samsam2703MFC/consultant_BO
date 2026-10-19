<?php
namespace App\Services\Product;

use App\Repositories\Product\FinalProductRepository;

class ProductService {
    private FinalProductRepository $finalProductRepository;

    public function __construct(FinalProductRepository $finalProductRepository) {
        $this->finalProductRepository = $finalProductRepository;
    }

    public function validate($data)
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = "Wprowadź nazwę";
        }

        if (empty($data['weight'])) {
            $errors['weight'] = "Wprowadź wagę";
        }

        if (empty($data['ppc'])) {
            $errors['ppc'] = "Podaj ilość sztuk w kartonie";
        }
        if (empty($data['ppb'])) {
            $errors['ppb'] = "Podaj ilość sztuk w opakowaniu";
        }
        if (empty($data['bpc'])) {
            $errors['bpc'] = "Podaj ilość opakowań w kartonie";
        }
        if (empty($data['cpl'])) {
            $errors['cpl'] = "Podaj ilość kartonów w jednej warstwie";
        }
        if (empty($data['lpp'])) {
            $errors['lpp'] = "Podaj ilość warstw na palecie";
        }
        if (empty($data['cpp'])) {
            $errors['cpp'] = "Podaj ilość kartonów na palecie";
        }
        if (empty($data['shelf_life'])) {
            $errors['shelf_life'] = "Podaj czas trwałości produktu";
        }
        if (empty($data['stock_minimum'])) {
            $errors['stock_minimum'] = "Podaj minimalną ilość produktu w magazynie";
        }
        if (empty($data['id_category']) || $data['id_category'] === "-1") {
            $errors['id_category'] = "Wybierz kategorię";
        }

        if (empty($data['id_section']) || $data['id_section'] === "-1") {
            $errors['id_category'] = "Wybierz dział produkcyjny";
        }

        if (empty($errors)) {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        return $this->finalProductRepository->insert($data);
    }

    public function first($id)
    {
        return $this->finalProductRepository->first(['id' => $id]);
    }

    public function getById($id)
    {
        return $this->finalProductRepository->getById($id);
    }

    public function update($id, $data)
    {
        return $this->finalProductRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->finalProductRepository->delete($id);
    }

    public function getByIdCategory($idCategory)
    {
        return $this->finalProductRepository->getByIdCategory($idCategory);
    }

    public function getAll($orderBy = "")
    {
        return $this->finalProductRepository->getAll($orderBy);
    }

    public function getFiltered(bool $includeInactive, bool $includeZeroPrice): array
    {
        return $this->finalProductRepository->getAllFiltered($includeInactive, $includeZeroPrice);
    }

    public function getWithoutRecipe()
    {
        return $this->finalProductRepository->getWithoutRecipe();
    }

    public function getWithoutRecipeGrouped(): array
    {
        $rows = $this->finalProductRepository->getWithoutRecipeGroupedBySectionAndCategory();

        $grouped = [];
        foreach ($rows as $row) {
            $sectionKey  = $row['id_section'];
            $categoryKey = $row['id_category'] ?? 0;

            if (!isset($grouped[$sectionKey])) {
                $grouped[$sectionKey] = [
                    'section_name' => $row['section_name'],
                    'categories'   => [],
                ];
            }

            if (!isset($grouped[$sectionKey]['categories'][$categoryKey])) {
                $grouped[$sectionKey]['categories'][$categoryKey] = [
                    'category_name' => $row['category_name'],
                    'products'      => [],
                ];
            }

            $grouped[$sectionKey]['categories'][$categoryKey]['products'][] = [
                'id'   => $row['id'],
                'name' => $row['name'],
            ];
        }

        return $grouped;
    }

    public function getDataByRecipeId($idRecipe)
    {
        return $this->finalProductRepository->getDataByRecipeId($idRecipe);
    }


    public function groupBySection($data)
    {
        $res_data = [];
        foreach ($data as $item) {
            if (!isset($res_data[$item['id_section']])) {
                $res_data[$item['id_section']]['id_section'] = $item['id_section'];
                $res_data[$item['id_section']]['section_name'] = $item['section_name'];
                $res_data[$item['id_section']]['products'] = [];
            }

            array_push($res_data[$item['id_section']]['products'], $item);
        }

        return $res_data;
    }
}