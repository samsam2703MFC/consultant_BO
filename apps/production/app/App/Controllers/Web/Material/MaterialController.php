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
                $result = $this->handleAction(function () {
                    $this->materialService->insert($_POST);
                    header('Location: ' . ROOT . '/materials/all?category=' . (int) $_POST['id_category']);
                    exit;
                }, default: false);
                $this->errors[] = 'Nie udało się zapisać surowca — sprawdź pola i spróbuj ponownie.';
            } else {
                $this->errors = array_values($this->materialService->lastErrors);
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
                // handleAction zwraca tablicę także przy błędzie : sprawdzamy « ok », nie samą wartość
                $result = $this->handleAction(function () use ($id) {
                    return $this->materialService->update($id, $_POST) !== false;
                }, default: false);

                if (!empty($result['ok'])) {
                    header('Location: ' . ROOT . '/materials/all?category=' . (int) $_POST['id_category']);
                    exit;
                }
                $this->errors[] = 'Nie udało się zapisać surowca — sprawdź pola i spróbuj ponownie.';
            } else {
                $this->errors = array_values($this->materialService->lastErrors);
            }
        }

        $this->view("material/material/edit", $data);
    }

    public function deleteMaterial($id)
    {
        $data['row'] = $this->materialService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->handleAction(function () use ($id) {
                $this->materialService->delete($id);
                return true;
            }, default: false);

            if (!empty($result['ok'])) {
                header('Location: ' . ROOT . '/materials/all?category=' . (int) $data['row']['id_category']);
                exit;
            }
            $this->errors[] = $result['error'] ?? 'Nie udało się usunąć surowca.';
        }

        $this->view("material/material/delete", $data);
    }

    /**
     * Surowce i kategorie : jedna strona (zakładki « Surowce » i « Kategorie ») zamiast listy kategorii,
     * listy surowców kategorii i listy wszystkich surowców. ?category= wybiera kategorię, ?tab=categories — kategorie.
     */
    public function listAll()
    {
        $data['rows'] = $this->materialService->getAll() ?: [];
        $counts = array_count_values(array_map(fn($r) => (int) $r['id_category'], $data['rows']));
        $data['categories'] = array_map(fn($c) => $c + ['materials_count' => $counts[(int) $c['id']] ?? 0],
            $this->materialCategoryService->getAll() ?: []);
        usort($data['categories'], fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        $data['allergens'] = $this->allergenService->getAll();
        $data['selected_category'] = (int) ($_GET['category'] ?? 0);
        $data['tab'] = ($_GET['tab'] ?? '') === 'categories' ? 'categories' : 'materials';

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
            $success = $this->materialService->setPackagePart((int) $id, $isPartOfPackage);

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
