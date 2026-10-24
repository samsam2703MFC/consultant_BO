<?php
namespace App\Services\Recipe;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\FlattenedRecipeRecipeRepository;
use App\Repositories\Product\Recipe\ProductRecipeConnectionRepository;
use App\Repositories\Product\Recipe\ProductRecipePreparationConnection;
use App\Repositories\Product\Recipe\RecipeRepository;

class CompositionService
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
        return $this->recipeRepository->getCompositions();
    }

    public function getSummary($id)
    {
        return $this->recipeService->getRecipeSummary($id);
    }

    public function getAllWithPrice()
    {
        $compositions = $this->getAll();
        if(!empty($compositions))
        {
            foreach ($compositions as &$composition)
            {
                $summary = $this->getSummary($composition['id']);

                $composition['raw_price'] = $summary['total_ingredient_cost'];
                $composition['yield_quantity'] = $summary['yield_quantity'];
                $composition['unit_name'] = $summary['recipe_unit'];            }
        }

        return $compositions;
    }

    public function update($idComposition, $data)
    {
        $resp = $this->transactionManager->execute(function () use ($idComposition, $data) {

            $updateData = [
                'name' => $data['name'],
                'yield_quantity' => $data['yield_quantity']
            ];
            $res = $this->recipeRepository->update($idComposition, $updateData);
            if(!$res){
                throw new CustomException("UPDATE_RECIPE_ERROR", 'errors', 500);
            }



            $resDelete = $this->productRecipeConnectionRepository->delete($idComposition, 'parent_recipe_id');
            if(!$resDelete){
                throw new CustomException("DELETE_RECIPE_CONNECTION_ERROR", 'errors', 500);
            }

            if(isset($data['material_data'])){
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($idComposition, $data['material_data']);
                if(!$resInsertMaterialForRecipe){
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }



            if(isset($data['composition_data'])){
                $resInsertCompositionForRecipe = $this->productRecipeConnectionRepository->insertCompositionForRecipe($idComposition, $data['composition_data']);
                if(!$resInsertCompositionForRecipe){
                    throw new CustomException("INSERT_COMPOSITION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            $resDeletePreparation = $this->productRecipePreparationConnection->delete($idComposition, 'id_recipe');
            if(!$resDeletePreparation){
                throw new CustomException("DELETE_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
            }

            if(isset($data['preparation_type'])){
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($idComposition, $data['preparation_type']);
                if(!$resInsertPreparationForRecipe){
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            return true;
        });


        if($resp){
            $resFlat = $this->flattenedRecipeService->flatForSingle($idComposition, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }

            $resRecalc = $this->recipeCostRecalculationService->recalculateForSubrecipe($idComposition, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }


            $recipes = $this->flattenedRecipeRecipeRepository->getRecipesBySubrecipeId($idComposition);

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


        return $resp;
    }

    public function validateComposition($data)
    {
        $errors = [];

        if(empty($data['name'])){
            $errors['empty_name'] = "Wprowadź nazwę kompozycji";
        }

        if(empty($data['material_data']) && empty($data['composition_data'])){
            $errors['empty_ingredients'] = "Brak danych dotyczących składników";
        }

        if(empty($data['yield_quantity'])){
            $errors['empty_yield'] = "Wprowadź wielkość otrzymanego produktu z receptury";
        }

        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function validateEditComposition($data)
    {
        $errors = [];

        $res = $this->validateComposition($data);
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
        $data['id_type'] = 1;
        $data['id_unit'] = 3;

        $idComposition = $this->transactionManager->execute(function () use ($data) {

            $resInsert = $this->recipeRepository->insert([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'id_type' => 1,
                'is_subrecipe' => 1,
                'id_unit' => 3,
                'yield_quantity' => $data['yield_quantity']
            ]);
            if(!$resInsert){
                throw new CustomException("INSERT_RECIPE_ERROR", 'errors', 500);
            }

            $idComposition = $resInsert;

            if(isset($data['material_data'])){
                $resInsertMaterialForRecipe = $this->productRecipeConnectionRepository->insertMaterialForRecipe($idComposition, $data['material_data']);
                if(!$resInsertMaterialForRecipe){
                    throw new CustomException("INSERT_MATERIAL_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if(isset($data['composition_data'])){
                $resInsertCompositionForRecipe = $this->productRecipeConnectionRepository->insertCompositionForRecipe($idComposition, $data['composition_data']);
                if(!$resInsertCompositionForRecipe){
                    throw new CustomException("INSERT_COMPOSITION_FOR_RECIPE_CONNECTION_ERROR", 'errors', 500);
                }
            }

            if(isset($data['preparation_type'])){
                $resInsertPreparationForRecipe = $this->productRecipePreparationConnection->insertForRecipe($idComposition, $data['preparation_type']);
                if(!$resInsertPreparationForRecipe){
                    throw new CustomException("INSERT_RECIPE_PREPARATION_CONNECTION_ERROR", 'errors', 500);
                }
            }

            return $idComposition;
        });


        if($idComposition){
            $resFlat = $this->flattenedRecipeService->flatForSingle($idComposition, false);
            if (!$resFlat) {
                throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
            }

            $resRecalc = $this->recipeCostRecalculationService->recalculateForSubrecipe($idComposition, false);
            if (!$resRecalc) {
                throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
            }
        }

        return $idComposition;
    }

    public function delete($id)
    {
        return $this->recipeService->delete($id);
    }
}