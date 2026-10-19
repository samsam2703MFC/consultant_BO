<?php

namespace App\Services\Recipe;

use App\Core\Helpers\UnitMapper;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\ProductRecipeConnectionRepository;
use App\Repositories\Product\Recipe\ProductRecipePreparationConnection;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Services\Product\ProductService;

class RecipeService
{
    public function __construct(
        private RecipeRepository                   $recipeRepository,
        private ProductRecipeConnectionRepository  $productRecipeConnectionRepository,
        private ProductRecipePreparationConnection $productRecipePreparationConnection,
        private TransactionManager                 $transactionManager,
        private ProductService                     $productService,
        private FlattenedRecipeService $flattenedRecipeService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private PredefinedRecipeLabelService $predefinedRecipeLabelService

    )
    {
    }


    public function getRecipeCalculation($idRecipe)
    {
        return $this->recipeRepository->getRecipeCalculation($idRecipe);
    }


    public function getRecipeBasicData($idRecipe)
    {
        return $this->recipeRepository->getRecipeBasicData($idRecipe);
    }

    public function getRecipeMaterials($idRecipe)
    {
        return $this->recipeRepository->getRecipeMaterials($idRecipe);
    }

    public function getRecipeCompositions($idRecipe)
    {
        return $this->recipeRepository->getRecipeCompositions($idRecipe);
    }

    public function getRecipePreparationType($idRecipe)
    {
        return $this->recipeRepository->getRecipePreparationType($idRecipe);
    }

    public function getRecipeSummary($id_recipe)
    {
        $total_ingredient_cost = 0;

        if (is_null($id_recipe)) {
            return ['total_ingredient_cost' => $total_ingredient_cost,
                'yield_quantity' => 0,
                'recipe_unit' => 0,
                'ppc' => 0];
        }


        $calculation_arr = $this->getRecipeCalculation($id_recipe);

        if (!empty($calculation_arr)) {
            foreach ($calculation_arr['elements'] as $item) {
//                echo $item['name'] . " => " . $item['calculated_req_price'] . "<br>";
                $total_ingredient_cost += $item['price_for_required_quantity'];
            }
        } else {
            $calculation_arr['yield_quantity'] = 0;
            $calculation_arr['unit_name'] = 0;
            $calculation_arr['ppc'] = 0;
        }

        return ['total_ingredient_cost' => $total_ingredient_cost,
            'yield_quantity' => $calculation_arr['yield_quantity'],
            'recipe_unit' => $calculation_arr['unit_name'],
            'ppc' => $calculation_arr['ppc']];
    }

    public function delete($id)
    {
        return $this->transactionManager->execute(function () use ($id) {

            $recipe = $this->recipeRepository->first(['id' => $id]);
            if($recipe && count($recipe) > 0 && $recipe['id_type'] == 3) {
                $product = $this->productService->getDataByRecipeId($id);
                if (empty($product)) {
                    throw new CustomException("PRODUCT_NOT_FOUND_FOR_RECIPE", 'errors', 404);
                }
                $resUpdateProduct = $this->productService->update($product['id'], ['id_recipe' => null]);
                if (!$resUpdateProduct) {
                    throw new CustomException("UPDATE_PRODUCT_ERROR", 'errors', 500);
                }
            }

            $resDelete = $this->recipeRepository->delete($id);
            if (!$resDelete) {
                throw new CustomException("DELETE_RECIPE_ERROR", 'errors', 500);
            }

            $resDelete = $this->productRecipePreparationConnection->delete($id, 'id_recipe');
            if (!$resDelete) {
                throw new CustomException("DELETE_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
            }

            $resDelete = $this->productRecipeConnectionRepository->delete($id, 'parent_recipe_id');
            if (!$resDelete) {
                throw new CustomException("DELETE_RECIPE_CONNECTION_ERROR", 'errors', 500);
            }

            return true;
        });
    }


    public function getAll()
    {
        return $this->recipeRepository->findAll();
    }

    public function getFinalRecipes()
    {
        $res = $this->recipeRepository->getFinalRecipes();
        if(!empty($res)){
            foreach ($res as &$recipe){
                $recipe['unit_name'] = UnitMapper::codeFromId($recipe['id_unit']);
            }
        }

        return $res;
    }
    public function getByType($typeId)
    {
        $res = $this->recipeRepository->getByType($typeId);
        if(!empty($res)){
            foreach ($res as &$recipe){
                $recipe['unit_name'] = UnitMapper::codeFromId($recipe['id_unit']);
            }
        }

        return $res;
    }

    public function getAllWithPrice()
    {
        $recipes = $this->recipeRepository->getAllConnectedWithProduct();
        if (!empty($recipes)) {
            foreach ($recipes as &$recipe) {
                $summary = $this->getRecipeSummary($recipe['id']);

                $recipe['raw_price'] = $summary['total_ingredient_cost'];
                $recipe['yield_quantity'] = $summary['yield_quantity'];
                $recipe['unit_name'] = $summary['recipe_unit'];
            }
        }

        return $recipes;
    }

    public function validate($data)
    {
        $errors = [];

        if (empty($data['final_product'])) {
            $errors['empty_name'] = "Wybierz produkt";
        }

        if (empty($data['material_data']) && empty($data['sfp_data'])) {
            $errors['empty_ingredients'] = "Brak danych dotyczących składników";
        }

        if (empty($errors)) {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        $idRecipe = $this->transactionManager->execute(function () use ($data) {
            $resInsert = $this->recipeRepository->insert([
                'name' => null,
                'description' => '',
                'id_type' => 3,
                'is_subrecipe' => 1,
                'id_unit' => 5,
                'yield_quantity' => 1,
                'precalculated_material_cost' => 0
            ]);
            if (!$resInsert) {
                throw new CustomException("INSERT_RECIPE_ERROR", 'errors', 500);
            }

            $idRecipe = $resInsert;

            if (isset($data['material_data'])) {
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($idRecipe, $data['material_data']);
                if (!$resInsertMaterialForRecipe) {
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if (isset($data['sfp_data'])) {
                $resInsertSemiProductRecipe = $this->productRecipeConnectionRepository->insertSemiProductForRecipe($idRecipe, $data['sfp_data']);
                if (!$resInsertSemiProductRecipe) {
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if (isset($data['preparation_type'])) {
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($idRecipe, $data['preparation_type']);
                if (!$resInsertPreparationForRecipe) {
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            $resUpdate = $this->productService->update($data['final_product'], ['id_recipe' => $idRecipe]);
            if (!$resUpdate) {
                throw new CustomException("UPDATE_PRODUCT_ERROR", 'errors', 500);
            }


            return $idRecipe;
        });

        if($idRecipe){

            $resFlat = $this->flattenedRecipeService->flatForSingle($idRecipe, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }

            $resPredefinedLabel = $this->predefinedRecipeLabelService->buildForRecipe($idRecipe, "fr");
            if(!$resPredefinedLabel){
                throw new CustomException("BUILD_PREDEFINED_LABEL_ERROR", 'errors', 500);
            }

            $resRecalc = $this->recipeCostRecalculationService->recalculateForRecipe($idRecipe, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }
        }

        return $idRecipe;
    }

    public function update($id, $data)
    {
        $resp = $this->transactionManager->execute(function () use ($id, $data) {

            $resDelete = $this->productRecipeConnectionRepository->delete($id, 'parent_recipe_id');
            if (!$resDelete) {
                throw new CustomException("DELETE_RECIPE_CONNECTION_ERROR", 'errors', 500);
            }

            if (isset($data['material_data'])) {
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($id, $data['material_data']);
                if (!$resInsertMaterialForRecipe) {
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if (isset($data['sfp_data'])) {
                $resInsertSemiProductRecipe = $this->productRecipeConnectionRepository->insertSemiProductForRecipe($id, $data['sfp_data']);
                if (!$resInsertSemiProductRecipe) {
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }


            $resDeletePreparation = $this->productRecipePreparationConnection->delete($id, 'id_recipe');
            if (!$resDeletePreparation) {
                throw new CustomException("DELETE_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
            }

            if (isset($data['preparation_type'])) {
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($id, $data['preparation_type']);
                if (!$resInsertPreparationForRecipe) {
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            return true;
        });

        if($resp){
            $resFlat = $this->flattenedRecipeService->flatForSingle($id, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }

            $resPredefinedLabel = $this->predefinedRecipeLabelService->buildForRecipe($id, "fr");
            if(!$resPredefinedLabel){
                throw new CustomException("BUILD_PREDEFINED_LABEL_ERROR", 'errors', 500);
            }

            $resRecalc = $this->recipeCostRecalculationService->recalculateForRecipe($id, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }
        }

        return $resp;
    }

    public function validateEdit($data)
    {
        $errors = [];

        if (empty($data['material_data']) && empty($data['sfp_data'])) {
            $errors['empty_ingredients'] = "Brak danych dotyczących składników";
        }

        if (empty($data['id_recipe'])) {
            $errors['empty_id'] = "Brak id receptury";
        }

        if (empty($errors)) {
            return true;
        }

        return false;
    }

    public function getRecipeSFP($idRecipe)
    {
        return $this->recipeRepository->getRecipeSFP($idRecipe);
    }
}