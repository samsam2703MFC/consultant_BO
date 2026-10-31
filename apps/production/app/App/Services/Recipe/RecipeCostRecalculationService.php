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

            foreach (array_keys($uniqueRecipes) as $recipeId) {   // identyfikatory są kluczami
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
        $this->saveCost((int) $recipeId, (float) $rawCost);
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
            $this->saveCost((int) $subrecipeId, (float) $ownCost);

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

            foreach (array_keys($uniqueRecipes) as $recipeId) {   // identyfikatory są kluczami
                $rawCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($recipeId);
                $this->saveCost((int) $recipeId, (float) $rawCost);
            }

            return true;
    }

    public function recalcForAll()
    {
        return $this->transactionManager->execute(function () {
            $recipes = $this->recipeRepository->findAll();


            foreach ($recipes as $recipe) {
                $rawCost = $this->flattenedRecipeIngredientRepository->getIngredientsCostSumByRecipeId($recipe['id']);
                $this->saveCost((int) $recipe['id'], (float) $rawCost);
            }

            return [
                'recipes' => count($recipes),
                'success' => true
            ];
        });
    }

    /**
     * Jedno źródło kosztu surowców produktu : koszt receptury (precalculated_material_cost) kopiowany do karty
     * produktu (final_product.pre_calculated_raw_price_per_carton), z której czytają marże, koszty, kalkulator,
     * Rentowność i magazyn. Produkt bez receptury zachowuje koszt wpisany ręcznie.
     */
    private function saveCost(int $recipeId, float $rawCost): void
    {
        $this->recipeRepository->update($recipeId, ['precalculated_material_cost' => $rawCost]);
        $this->recipeRepository->mirrorMaterialCostToProducts($recipeId, $rawCost);
    }
}
