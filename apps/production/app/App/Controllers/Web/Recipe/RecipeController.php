<?php

namespace App\Controllers\Web\Recipe;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Repositories\Product\FinalProductRepository;
use App\Services\Material\MaterialService;
use App\Services\Product\ProductService;
use App\Services\Product\ProductsWithoutRecipePdfBuilder;
use App\Services\Recipe\CompositionService;
use App\Services\Recipe\FlattenedRecipeService;
use App\Services\Recipe\PredefinedRecipeLabelService;
use App\Services\Recipe\PreparationService;
use App\Services\Recipe\RecipeCompetencyService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Recipe\RecipeCostService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;
use App\Services\Utils\UnitService;
use Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * product class
 */
class RecipeController extends BaseController
{


    public function __construct(
        private CompositionService $compositionService,
        private MaterialService    $materialService,
        private UnitService        $unitService,
        private PreparationService $preparationService,
        private RecipeService      $recipeService,
        private SemiProductService $semiProductService,
        private ProductService     $productService,
        private RecipeCostService $recipeCostService,
        private FlattenedRecipeService $flattenedRecipeService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private PredefinedRecipeLabelService $predefinedRecipeLabelService,
        private RecipeCompetencyService $recipeCompetencyService,
        private ProductsWithoutRecipePdfBuilder $productsWithoutRecipePdfBuilder
    )
    {
    }

    #[Route('GET', '/recipes')]
    public function overview()
    {
        $data['final_recipes']  = $this->recipeService->getFinalRecipes();
        $data['competencies']   = $this->recipeCompetencyService->getAll();

        $this->view("recipe/recipe/overview", $data);
    }

    public function newRecipe()
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['sfp'] = $this->semiProductService->getAll();
        $data['products_without_recipe'] = $this->productService->getWithoutRecipe();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->recipeService->validate($_POST)) {
                $dataToInsert = $_POST;

                $newRecipeId = $this->recipeService->insert($dataToInsert);

                if ($newRecipeId) {

                    header('Location: ' . ROOT . '/recipes/' . $newRecipeId . '/calculation');
                    exit;
                }

            } else {
                $data['old_data'] = $_POST;
            }
        }

        $this->view("recipe/recipe/new", $data);
    }


    public function editRecipe($id)
    {
        $data['units'] = $this->unitService->getSmallUnit();
        $data['materials'] = $this->materialService->getAll();
        $data['preparation_types'] = $this->preparationService->getPreparationTypes();
        $data['sfp'] = $this->semiProductService->getAll();

        $data['product_data'] = $this->productService->getDataByRecipeId($id);

        $data['old_data']['material_data'] = $this->recipeService->getRecipeMaterials($id);
        $data['old_data']['sfp_data'] = $this->recipeService->getRecipeSFP($id);
        $data['old_data']['preparation_type'] = $this->recipeService->getRecipePreparationType($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['id_recipe'] = $id;
            $data['old_data'] = $_POST;

            if ($this->recipeService->validateEdit($_POST)) {
                $dataToUpdate = $_POST;

                $success = $this->handleAction(function () use ($id, $dataToUpdate) {
                    $this->recipeService->update($id, $dataToUpdate);
                    return true;
                }, default: false);

                if ($success) {

                    header('Location: ' . ROOT . '/recipes/' . $id . '/calculation');
                    exit;
                }
            }
        }

        $this->view("recipe/recipe/edit", $data);
    }


    public function deleteRecipe($id)
    {
        $data['product_data'] = $this->productService->getDataByRecipeId($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = $this->handleAction(function () use ($id) {
                $this->recipeService->delete($id);
                return true;
            }, default: false);

            if ($success) {
                header('Location: ' . ROOT . '/compositions');
                exit;
            }
        }

        $this->view("recipe/recipe/delete", $data);
    }


    public function calculation($id)
    {
        $data['recipe_calculation_data'] = $this->recipeService->getRecipeCalculation($id);
        $data['recipe_price'] = $this->recipeService->getRecipeSummary($id)['total_ingredient_cost'];

        $this->view("recipe/recipe/calculation", $data);
    }



    public function final_recipe($action = null, $id = null)
    {

        if ($action == "new") {

        } else if ($action == "edit") {

        } else if ($action == "delete") {
            $recipeModel->deleteRecipe(['id_recipe' => $id]);

        } else if ($action == "calculation") {

            $data['recipe_calculation_data'] = $recipeModel->getRecipeCalculation($id);
            $data['recipe_price'] = $recipeModel->getRecipeSummary($id)['total_ingredient_cost'];

        } else {

        }
        $data['errors'] = $recipeModel->errors;

        $this->view("recipe/final_recipe", $data);
    }




    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $resp = false;
        if ($target == "recipe" && $action == "get_tree") {
            $recipeModel = new RecipeModel();

            $data = json_decode(json_encode(json_decode($_POST['id_recipe'])), true);
            $resp['recipe_tree'] = $recipeModel->getDataForBOM(['id_recipe' => $data]);
            $resp['recipe_data'] = $recipeModel->getRecipeBasicData(['id_recipe' => $data]);
        }


        echo json_encode($resp);
    }

    public function ajaxGetProductsWithoutRecipePdf()
    {
        $grouped = $this->productService->getWithoutRecipeGrouped();
        $result  = $this->productsWithoutRecipePdfBuilder->build($grouped);

        $pdfData  = $result['file_string'];
        $filename = htmlspecialchars($result['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    public function ajaxGetRecipeFinalCostsCsv()
    {
        $respDoc = $this->recipeCostService->getRecipeFinalCostsCsv();

        if (!$respDoc) {
            return $this->json($respDoc, $respDoc['code']);
        }

        $pdfData  = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    #[Route('GET', '/ajax/settings/flat-all-recipes')]
    public function ajaxFlatAllRecipes()
    {
        $res = $this->flattenedRecipeService->flatForAll();
        return $this->json($res);
    }

    #[Route('GET', '/ajax/settings/recalc-all-recipes')]
    public function ajaxRecalcAllRecipes()
    {
        $res = $this->recipeCostRecalculationService->recalcForAll();
        return $this->json($res);
    }

    #[Route('GET', '/ajax/settings/rewrite-all-labels')]
    public function ajaxRewriteAllLabels()
    {
        $res = $this->predefinedRecipeLabelService->buildForAllRecipes();
        return $this->json($res);
    }
}
