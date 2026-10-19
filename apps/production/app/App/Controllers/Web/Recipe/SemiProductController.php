<?php
namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Services\Material\MaterialService;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeCompetencyService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;
use App\Services\Utils\UnitService;

class SemiProductController extends BaseController
{
    private CompositionService $compositionService;
    private MaterialService $materialService;
    private UnitService $unitService;
    private PreparationService $preparationService;
    private RecipeService $recipeService;
    private SemiProductService $semiProductService;

    public function __construct(
        CompositionService $compositionService,
        MaterialService    $materialService,
        UnitService        $unitService,
        PreparationService $preparationService,
        RecipeService      $recipeService,
        SemiProductService $semiProductService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private RecipeCompetencyService $recipeCompetencyService
    )
    {
        $this->compositionService = $compositionService;
        $this->unitService = $unitService;
        $this->materialService = $materialService;
        $this->preparationService = $preparationService;
        $this->recipeService = $recipeService;
        $this->semiProductService = $semiProductService;
    }

    public function overview()
    {
        $data['semi_final_recipes'] = $this->recipeService->getByType(2);
        $data['competencies']       = $this->recipeCompetencyService->getAll();

        $this->view("recipe/semi_product/overview", $data);
    }

    public function newSemiProduct()
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();
        $data['subrecipes'] = $this->semiProductService->getAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->semiProductService->validate($_POST)) {
                $dataToInsert = $_POST;

                $newId = $this->semiProductService->insert($dataToInsert);

                if ($newId) {

                    header('Location: ' . ROOT . '/semi-products/' . $newId . '/calculation');
                    exit;
                }
            } else {
                $data['old_data'] = $_POST;
            }
        }

        $this->view("recipe/semi_product/new", $data);
    }


    public function editSemiProduct($id)
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['compositions'] = $this->compositionService->getAll();
        $data['subrecipes'] = $this->semiProductService->getAll();

        $data['row'] = $this->recipeService->getRecipeBasicData($id);
        $data['old_data']['material_data'] = $this->recipeService->getRecipeMaterials($id);
        $data['old_data']['composition_data'] = $this->recipeService->getRecipeCompositions($id);
        $data['old_data']['preparation_type'] = $this->recipeService->getRecipePreparationType($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->semiProductService->validateEdit($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    $this->semiProductService->update($id, $dataToUpdate);
                    return true;
                }, default: false);

                if ($success) {

                    header('Location: ' . ROOT . '/semi-products/' . $id . '/calculation');
                    exit;
                }
            }
        }

        $this->view("recipe/semi_product/edit", $data);
    }

    public function deleteSemiProduct($id)
    {
        $data['row'] = $this->recipeService->getRecipeBasicData($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->semiProductService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/semi-products');
                exit;
            }
        }

        $this->view("recipe/semi_product/delete", $data);
    }

    public function calculation($id)
    {
        $data['recipe_calculation_data'] = $this->recipeService->getRecipeCalculation($id);
        $data['recipe_data'] = $this->recipeService->getRecipeSummary($id);

        $this->view("recipe/semi_product/calculation", $data);
    }


}