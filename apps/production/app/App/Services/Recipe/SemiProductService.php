<?php
namespace App\Services\Recipe;


use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\FlattenedRecipeRecipeRepository;
use App\Repositories\Product\Recipe\ProductRecipeConnectionRepository;
use App\Repositories\Product\Recipe\ProductRecipePreparationConnection;
use App\Repositories\Product\Recipe\RecipeRepository;

class SemiProductService
{


    public function __construct(
        private RecipeRepository $recipeRepository,
        private ProductRecipeConnectionRepository $productRecipeConnectionRepository,
        private ProductRecipePreparationConnection $productRecipePreparationConnection,
        private RecipeService $recipeService,
        private TransactionManager $transactionManager,
        private FlattenedRecipeService $flattenedRecipeService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private FlattenedRecipeRecipeRepository $flattenedRecipeRecipeRepository
    )
    {
    }


    public function getAll()
    {
        return $this->recipeRepository->getSFP();
    }

    public function getSummary($id)
    {
        return $this->recipeService->getRecipeSummary($id);
    }

    public function getAllWithPrice()
    {
        $semiProducts = $this->getAll();
        if(!empty($semiProducts))
        {
            foreach ($semiProducts as &$semiProduct)
            {
                $summary = $this->getSummary($semiProduct['id']);

                $semiProduct['raw_price'] = $summary['total_ingredient_cost'];
                $semiProduct['yield_quantity'] = $summary['yield_quantity'];
                $semiProduct['unit_name'] = $summary['recipe_unit'];
            }
        }

        return $semiProducts;
    }

    public function update($idSemiProduct, $data)
    {
        $resp = $this->transactionManager->execute(function () use ($idSemiProduct, $data) {

            $updateData = [
                'name' => $data['name']
            ];
            $res = $this->recipeRepository->update($idSemiProduct, $updateData);
            if(!$res){
                throw new CustomException("UPDATE_RECIPE_ERROR", 'errors', 500);
            }

            $resDelete = $this->productRecipeConnectionRepository->delete($idSemiProduct, 'parent_recipe_id');
            if(!$resDelete){
                throw new CustomException("DELETE_RECIPE_CONNECTION_ERROR", 'errors', 500);
            }

            if(isset($data['material_data'])){
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($idSemiProduct, $data['material_data']);
                if(!$resInsertMaterialForRecipe){
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if(isset($data['composition_data'])){
                $resInsertCompositionForRecipe = $this->productRecipeConnectionRepository->insertCompositionForRecipe($idSemiProduct, $data['composition_data']);
                if(!$resInsertCompositionForRecipe){
                    throw new CustomException("INSERT_COMPOSITION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            $resDeletePreparation = $this->productRecipePreparationConnection->delete($idSemiProduct, 'id_recipe');
            if(!$resDeletePreparation){
                throw new CustomException("DELETE_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
            }

            if(isset($data['preparation_type'])){
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($idSemiProduct, $data['preparation_type']);
                if(!$resInsertPreparationForRecipe){
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            return true;
        });

        if($resp)
        {
            $resFlat = $this->flattenedRecipeService->flatForSingle($idSemiProduct, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }

            $resRecalc = $this->recipeCostRecalculationService->recalculateForSubrecipe($idSemiProduct, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }


            $recipes = $this->flattenedRecipeRecipeRepository->getRecipesBySubrecipeId($idSemiProduct);

            foreach($recipes as $recipe){
                $resFlat = $this->flattenedRecipeService->flatForSingle($recipe['recipe_id'], false);
                if (!$resFlat) {
                    throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
                }
                $resRecalc = $this->recipeCostRecalculationService->recalculateForRecipe($recipe['recipe_id'], false);
                if (!$resRecalc) {
                    throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
                }
            }
        }

        return true;
    }

    public function validate($data)
    {
        $errors = [];

        if(empty($data['name'])){
            $errors['empty_name'] = "Wprowadź nazwę receptury";
        }

        if(empty($data['material_data']) && empty($data['composition_data'])){
            $errors['empty_ingredients'] = "Brak danych dotyczących składników";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function validateEdit($data)
    {
        $errors = [];

        $res = $this->validate($data);
        if(!$res){
            return $res;
        }

        if(empty($data['id_recipe'])){
            $errors['empty_id'] = "Brak id receptury";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        $data['id_type'] = 2;
        $data['id_unit'] = 5;
        $data['yield_quantity'] = 1;

        $idSemiProduct = $this->transactionManager->execute(function () use ($data) {

            $resInsert = $this->recipeRepository->insert([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'id_type' => $data['id_type'],
                'is_subrecipe' => 1,
                'id_unit' => $data['id_unit'],
                'yield_quantity' => $data['yield_quantity']
            ]);
            if(!$resInsert){
                throw new CustomException("INSERT_RECIPE_ERROR", 'errors', 500);
            }

            $idSemiProduct = $resInsert;

            if(isset($data['material_data'])){
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($idSemiProduct, $data['material_data']);
                if(!$resInsertMaterialForRecipe){
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if(isset($data['composition_data'])){
                $resInsertCompositionForRecipe = $this->productRecipeConnectionRepository->insertCompositionForRecipe($idSemiProduct, $data['composition_data']);
                if(!$resInsertCompositionForRecipe){
                    throw new CustomException("INSERT_COMPOSITION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if(isset($data['preparation_type'])){
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($idSemiProduct, $data['preparation_type']);
                if(!$resInsertPreparationForRecipe){
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            return $idSemiProduct;
        });

        if($idSemiProduct){
            $resFlat = $this->flattenedRecipeService->flatForSingle($idSemiProduct, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }
            
            $resRecalc = $this->recipeCostRecalculationService->recalculateForSubrecipe($idSemiProduct, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }

        }

        return $idSemiProduct;
    }

    public function delete($id)
    {
        return $this->recipeService->delete($id);
    }

}