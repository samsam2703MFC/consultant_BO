<?php

namespace App\Controllers\Web\Material;


use App\Core\Web\BaseController;
use App\Services\Material\AllergenService;
use App\Services\Material\MaterialCategoryService;
use App\Services\Material\MaterialService;
use App\Services\Material\MaterialSupplierService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Utils\UnitService;

class MaterialController extends BaseController
{
    public function __construct(
        private MaterialCategoryService $materialCategoryService,
        private MaterialService         $materialService,
        private MaterialSupplierService $materialSupplierService,
        private UnitService             $unitService,
        private AllergenService $allergenService
    )
    {
    }


    public function overview()
    {
        $data['rows'] = $this->materialCategoryService->getUsed();

        $this->view("material/category/overview", $data);
    }

    public function newMaterial($idCategory = null)
    {
        $data['suppliers'] = $this->materialSupplierService->getAll();
        $data['category_material'] = $this->materialCategoryService->getAll();
        $data['units'] = $this->unitService->getSmallUnit();

        if(isset($idCategory)) {
            $_POST['id_category'] = $idCategory;
        }
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            if ($this->materialService->validate($_POST)) {
                $this->handleAction(function () {
                    $this->materialService->insert($_POST);
                    header('Location: ' . ROOT . '/material-categories/' . $_POST['id_category'] . '/materials');
                    exit;
                }, default: false);
            }
        }

        $this->view("material/material/new", $data);
    }

    public function editMaterial($id)
    {
        $data['suppliers'] = $this->materialSupplierService->getAll();
        $data['category_material'] = $this->materialCategoryService->getAll();
        $data['units'] = $this->unitService->getSmallUnit();
        $data['row'] = $this->materialService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            if ($this->materialService->validateUpdate($_POST)) {
                $success = $this->handleAction(function () use ($id) {
                    $this->materialService->update($id, $_POST);
                    return true;
                }, default: false);

                if ($success) {
                    header('Location: ' . ROOT . '/material-categories/' . $_POST['id_category'] . '/materials');
                    exit;
                }
            }
        }

        $this->view("material/material/edit", $data);
    }

    public function deleteMaterial($id)
    {
        $data['row'] = $this->materialService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->materialService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/material-categories/' . $data['row']['id_category'] . '/materials');
                exit;
            }
        }

        $this->view("material/material/delete", $data);
    }

    public function filterByCategory($idCategory)
    {
        $data['rows'] = $this->materialService->getByIdCategory($idCategory);
        $data['allergens'] = $this->allergenService->getAll();
        $data['id_category'] = $idCategory;
        $data['category'] = $this->materialCategoryService->getById($idCategory);

        $this->view("material/material/filter", $data);
    }

    public function listAll()
    {
        $data['rows'] = $this->materialService->getAll();
        $data['categories'] = $this->materialCategoryService->getAll();

        $this->view("material/list", $data);
    }

    public function ajaxTogglePackagePart($id)
    {
        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['is_part_of_package'])) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Brak parametru is_part_of_package'
                ]);
                return;
            }

            $isPartOfPackage = (int)$input['is_part_of_package'];

            // Walidacja - tylko 0 lub 1
            if ($isPartOfPackage !== 0 && $isPartOfPackage !== 1) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Nieprawidłowa wartość. Dozwolone: 0 lub 1'
                ]);
                return;
            }

            // Aktualizacja w bazie
            $success = $this->materialService->update($id, [
                'is_part_of_package' => $isPartOfPackage
            ]);

            if ($success !== false) {
                echo json_encode([
                    'ok' => true,
                    'message' => 'Status zaktualizowany pomyślnie',
                    'data' => [
                        'is_part_of_package' => $isPartOfPackage
                    ]
                ]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Nie udało się zaktualizować statusu'
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'Błąd serwera: ' . $e->getMessage()
            ]);
        }
    }

}
