<?php
namespace App\Controllers\Web\Recipe;

use App\Services\Recipe\RecipeCalculationViewService;
use App\Repositories\Workshop\RecipeProducerRepository;
use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Material\MaterialService;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Recipe\RecipeService;
use App\Services\Utils\UnitService;
use Controller;

/**
 * product class
 */
class CompositionController extends BaseController
{
    private CompositionService $compositionService;
    private MaterialService $materialService;
    private UnitService $unitService;
    private PreparationService $preparationService;
    private RecipeService $recipeService;

    public function __construct(
        CompositionService $compositionService,
        MaterialService    $materialService,
        UnitService        $unitService,
        PreparationService $preparationService,
        RecipeService      $recipeService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private RecipeCalculationViewService $calculationView,
        private RecipeProducerRepository $producers,
        private \App\Services\Recipe\RecipeExtrasService $extras
    )
    {
        $this->compositionService = $compositionService;
        $this->unitService = $unitService;
        $this->materialService = $materialService;
        $this->preparationService = $preparationService;
        $this->recipeService = $recipeService;
    }

    public function overview()
    {
        $data['compositions'] = $this->recipeService->getByType(1);

        $this->view("recipe/composition/overview", $data);
    }

    public function newComposition()
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->compositionService->validateComposition($_POST)) {
                $dataToInsert = $_POST;

                $newId = $this->compositionService->insert($dataToInsert);

                if ($newId) {
                    $this->saveExtras((int) $newId, true);
                    header('Location: ' . ROOT . '/compositions/' . $newId . '/calculation');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $data += $this->extras->formData(null, 'composition', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/composition/new", $data);
    }

    public function editComposition($id)
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();
        $data['row'] = $this->recipeService->getRecipeBasicData($id);
        $data['old_data']['material_data'] = $this->recipeService->getRecipeMaterials($id);
        $data['old_data']['composition_data'] = $this->recipeService->getRecipeCompositions($id);
        $data['old_data']['preparation_type'] = $this->recipeService->getRecipePreparationType($id);
        $data['id_recipe'] = $id;
        $data['producer_groups'] = $this->producers->groups();
        $data['producer'] = $this->producers->forRecipes([(int) $id])[(int) $id] ?? null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->compositionService->validateEditComposition($_POST)) {
                $dataToUpdate = $_POST;

                $res = $this->compositionService->update($id, $dataToUpdate);

                if ($res) {
                    $this->saveExtras((int) $id, false);
                    header('Location: ' . ROOT . '/compositions/' . $id . '/calculation');
                    exit;
                }
            }
        }

        $data += $this->extras->formData((int) $id, 'composition', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null);
        $this->view("recipe/composition/edit", $data);
    }

    public function deleteComposition($id)
    {
        $data['row'] = $this->recipeService->getRecipeBasicData($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->compositionService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/compositions');
                exit;
            }
        }

        $this->view("recipe/composition/delete", $data);
    }

    public function calculation($id)
    {
        try {
            $data = $this->calculationView->build((int) $id, 'composition');
        } catch (CustomException $e) {
            http_response_code(404);
            echo 'Nie znaleziono receptury.';
            return;
        }
        if (($_SESSION['recipe_saved']['id'] ?? null) === (int) $id) {
            $data['saved'] = $_SESSION['recipe_saved'];
            unset($_SESSION['recipe_saved']);
        }
        $this->view('recipe/calculation', $data);
    }

    /** Zakładki Przygotowanie i Grupa i kompetencje (instrukcja, kompetencje, grupa) po zapisie składu ; wynik na kalkulacji. */
    private function saveExtras(int $recipeId, bool $isNew): void
    {
        $_SESSION['recipe_saved'] = ['id' => $recipeId, 'new' => $isNew, 'problems' => $this->extras->save($recipeId, $_POST, $isNew, user('id') ? (int) user('id') : null)];
    }
}
