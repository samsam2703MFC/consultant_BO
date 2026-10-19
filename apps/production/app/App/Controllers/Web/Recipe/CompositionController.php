<?php
namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Material\MaterialService;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\PreparationStepService;
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
        private PreparationStepService $preparationStepService
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

                    header('Location: ' . ROOT . '/compositions/' . $newId . '/calculation');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->compositionService->validateEditComposition($_POST)) {
                $dataToUpdate = $_POST;

                $res = $this->compositionService->update($id, $dataToUpdate);

                if ($res) {

                    header('Location: ' . ROOT . '/compositions/' . $id . '/calculation');
                    exit;
                }
            }
        }

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
        $data['recipe_calculation_data'] = $this->recipeService->getRecipeCalculation($id);
        $data['recipe_data'] = $this->recipeService->getRecipeSummary($id);

        $this->view("recipe/composition/calculation", $data);
    }

    public function preparationSteps(int $id): void
    {
        $data['recipe']    = $this->safeFetch(
            fn() => $this->preparationStepService->getRecipeBasicData($id),
            $this->errors, null, []
        );
        $data['steps']     = $this->preparationStepService->getSteps($id);
        $data['recipe_id'] = $id;

        $this->view('recipe/composition/preparation-steps', $data);
    }

}