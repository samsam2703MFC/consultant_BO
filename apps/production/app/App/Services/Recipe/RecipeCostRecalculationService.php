<?php

namespace App\Services\Recipe;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Product\Recipe\FlattenedRecipeIngredientRepository;
use App\Repositories\Product\Recipe\FlattenedRecipeRecipeRepository;
use App\Repositories\Product\Recipe\RecipeRepository;

class RecipeCostRecalculationService
{
    public function __construct(
        private FlattenedRecipeIngredientRepository $flattenedRecipeIngredientRepository,
        private TransactionManager $transactionManager,
        private FlattenedRecipeRecipeRepository $flattenedRecipeRecipeRepository,
        private RecipeRepository $recipeRepository,
        private FlattenedRecipeService $flattenedRecipeService
    )
    {
    }

    /**
     * Kontrakt:
     * - Input: $idMaterial (raw_material.id)
     * - Output: ['recipes' => int, 'message' => string]
     *
     * Na ten moment przeliczenie kosztu jest „lazy” (wyliczane przy pobraniu),
     * więc endpoint pełni rolę: znalezienie powiązanych receptur i możliwość
     * odpalenia dalszych procesów w tle w przyszłości.
     */
    public function recalculateForMaterial(int $idMaterial): array
    {
        return $this->transactionManager->execute(function () use ($idMaterial) {
            $connectedRecipes = $this->flattenedRecipeIngredientRepository->getByMaterialId($idMaterial);

            $uniqueRecipes = [];
            if (!empty($connectedRecipes)) {
                foreach ($connectedRecipes as $row) {
                    if (!empty($row['recipe_id'])) {
                        $uniqueRecipes[(int)$row['recipe_id']] = true;
                    }
                }
            }

            foreach ($uniqueRecipes as $recipeId) {
                // Najpierw odśwież spłaszczoną strukturę składników
                $resFlat = $this->flattenedRecipeService->flatForSingle($recipeId, false);
                if (!$resFlat) {
                    throw new CustomException("FLATTEN_RECIPE_ERROR", 'errors', 500);
                }
                // Dopiero potem przelicz koszt na podstawie świeżych danych
                $resRecalc = $this->recalculateForRecipe($recipeId, false);
                if (!$resRecalc) {
                    throw new CustomException("RECALCULATE_RECIPE_COST_ERROR", 'errors', 500);
                }
            }

            return [
                'recipes' => count($uniqueRecipes),
                'success' => true
            ];
        });
    }

    public function recalculateForRecipe($recipeId, $useTransaction = true)
    {
        if ($useTransaction) {
            return $this->transactionManager->execute(fn() => $this->doRecalcForRecipe((int)$recipeId));
        }
        return $this->doRecalcForRecipe((int)$recipeId);
    }

    private function doRecalcForRecipe(int $recipeId)
    {
        $rawCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($recipeId);
        $this->recipeRepository->update(
            $recipeId, [
            'precalculated_material_cost' => $rawCost
        ]);
        return true;
    }

    public function recalculateForSubrecipe($subrecipeId, $useTransaction = true)
    {
        if ($useTransaction) {
            return $this->transactionManager->execute(fn() => $this->doRecalcForSubrecipe((int)$subrecipeId));
        }
        return $this->doRecalcForSubrecipe((int)$subrecipeId);
    }

    private function doRecalcForSubrecipe(int $subrecipeId)
    {
            // 1) Przelicz i zapisz koszt SAMEJ podreceptury/kompozycji
            $ownCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($subrecipeId);
            $this->recipeRepository->update($subrecipeId, ['precalculated_material_cost' => $ownCost]);

            // 2) Przelicz receptury finalne, które zawierają tę podrecepturę
            $connectedRecipes = $this->flattenedRecipeRecipeRepository->getRecipesBySubrecipeId($subrecipeId);

            $uniqueRecipes = [];
            if (!empty($connectedRecipes)) {
                foreach ($connectedRecipes as $row) {
                    if (!empty($row['recipe_id'])) {
                        $uniqueRecipes[(int)$row['recipe_id']] = true;
                    }
                }
            }

            foreach ($uniqueRecipes as $recipeId) {
                $rawCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($recipeId);
                $this->recipeRepository->update(
                    $recipeId, [
                    'precalculated_material_cost' => $rawCost
                ]);
            }

            return true;
    }

    public function recalcForAll()
    {
        return $this->transactionManager->execute(function () {
            $recipes = $this->recipeRepository->findAll();


            foreach ($recipes as $recipe) {
                $rawCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($recipe['id']);
                $this->recipeRepository->update(
                    $recipe['id'], [
                    'precalculated_material_cost' => $rawCost
                ]);
            }

            return [
                'recipes' => count($recipes),
                'success' => true
            ];
        });
    }
}

