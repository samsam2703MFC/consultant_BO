<?php
namespace App\Services\Recipe;



use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\FlattenedRecipeIngredientRepository;
use App\Repositories\Product\Recipe\FlattenedRecipeRecipeRepository;
use App\Repositories\Product\Recipe\RecipeRepository;

class FlattenedRecipeService
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private TransactionManager $transactionManager,
        private FlattenedRecipeIngredientRepository $flattenedRecipeIngredientRepository,
        private FlattenedRecipeRecipeRepository $flattenedRecipeRecipeRepository
    )
    {
    }

    /**
     * Spłaszcza pojedynczą recepturę. Domyślnie w transakcji.
     */
    public function flatenRecipe($id, $useTransaction = true)
    {
        if ($useTransaction) {
            return $this->transactionManager->execute(fn() => $this->doFlattening((int)$id));
        }
        return $this->doFlattening((int)$id);
    }

    /**
     * Wykonuje spłaszczenie i docelowo zapis do tabel flattened.
     * Na tym etapie waliduje i buduje struktury wynikowe.
     */
    public function doFlattening(int $id)
    {
        $recipe_data = $this->recipeRepository->getRecipeCalculation($id);

        // Nawet gdy receptura jest pusta — zwracamy pustą strukturę, żeby
        // doFlatteningWithSaveForSingle mogło wyczyścić stare wpisy z flattened_*.
        if (empty($recipe_data)) {
            return [
                'id_recipe'  => $id,
                'materials'  => [],
                'subrecipes' => [],
            ];
        }

        // 1) zbieramy do $aggregated
        $aggregated = [];
        $this->extractUniqueIngredients($recipe_data, $aggregated);

        // 2) formatujemy wynik do „jednej linii” dla składników
        $extractIngredientsResult = [];
        foreach ($aggregated as $ingredientId => $info) {
            $qty = isset($info['quantity']) ? (float)$info['quantity'] : 0.0;

            $extractIngredientsResult[] = [
                'id_material' => (int)$ingredientId,
                'quantity'    => round($qty, 6),
                'id_unit'     => $info['id_unit'] ?? null
            ];
        }

        // 3) zbieramy unikalne subreceptury (po id)
        $uniqueSubrecipes = [];
        $seenSubrecipes = [];
        $this->extractUniqueSubrecipes($recipe_data, $uniqueSubrecipes, $seenSubrecipes);

        // TODO: tutaj docelowo zapis do tabel flattened (delete+insert)
        // Na razie zwracamy wynik diagnostyczny, żeby można było go użyć w workerze/cron.
        return [
            'id_recipe' => $id,
            'materials' => $extractIngredientsResult,
            'subrecipes' => $uniqueSubrecipes,
        ];
    }

    /**
     * Przechodzi po strukturze receptury i dla każdego składnika sumuje
     * łączną wymaganą ilość.
     *
     * @param array $recipe
     * @param array<int,array{quantity:float,id_unit:mixed}> $aggregated
     */
    private function extractUniqueIngredients(array $recipe, array &$aggregated = []): void
    {
        if (empty($recipe['elements']) || !is_array($recipe['elements'])) {
            return;
        }

        foreach ($recipe['elements'] as $element) {
            if (($element['type'] ?? null) === 'ingredient') {
                // getRecipeCalculation zwraca 'id' (ID surowca) oraz 'required_quantity'
                $id = (int)($element['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $qty = (float)($element['required_quantity'] ?? 0);
                if ($qty < 0) {
                    continue;
                }

                $unit_id = $element['id_unit'] ?? null;

                if (!isset($aggregated[$id])) {
                    $aggregated[$id] = [
                        'quantity' => 0.0,
                        'id_unit'  => $unit_id
                    ];
                }

                $aggregated[$id]['quantity'] += $qty;

            } elseif (($element['type'] ?? null) === 'sub-recipe') {
                // cykl: receptura zatrzymana, brak elementów
                if (!empty($element['cycle_detected'])) {
                    continue;
                }
                $this->extractUniqueIngredients($element, $aggregated);
            }
        }
    }

    /**
     * Zbiera unikalne ID podreceptur użytych w recepturze.
     *
     * @param array $recipe
     * @param int[] $collected
     * @param array<int,bool> $ids
     */
    private function extractUniqueSubrecipes(array $recipe, array &$collected = [], array &$ids = []): void
    {
        if (empty($recipe['elements']) || !is_array($recipe['elements'])) {
            return;
        }

        foreach ($recipe['elements'] as $element) {
            if (($element['type'] ?? null) === 'sub-recipe') {
                if (!empty($element['cycle_detected'])) {
                    continue;
                }

                // getRecipeCalculation zwraca 'id' dla subreceptury
                $subrecipeId = (int)($element['id'] ?? 0);
                if ($subrecipeId > 0 && !isset($ids[$subrecipeId])) {
                    $collected[] = $subrecipeId;
                    $ids[$subrecipeId] = true;
                }

                $this->extractUniqueSubrecipes($element, $collected, $ids);
            }
        }
    }

    /**
     * Spłaszcza wszystkie receptury (bez transakcji per receptura – przyspiesza batch).
     */
    public function flatForAll(): bool
    {
        return $this->transactionManager->execute(function () {
            $recipes = $this->recipeRepository->findAll();

            $this->flattenedRecipeIngredientRepository->deleteAll();
            $this->flattenedRecipeRecipeRepository->deleteAll();

            foreach ($recipes as $recipe) {
                if (!isset($recipe['id'])) {
                    continue;
                }

                $this->doFlatteningWithSaveForSingle((int)$recipe['id']);
            }
            return true;
        });
    }

    public function flatForSingle($id, $useTransaction = true)
    {
        if($useTransaction) {
            return $this->transactionManager->execute(fn() => $this->doFlatteningWithSaveForSingle((int)$id));
        }
        return $this->doFlatteningWithSaveForSingle((int)$id);

    }

    private function doFlatteningWithSaveForSingle($recipeId)
    {
        $res = $this->flatenRecipe((int)$recipeId, false);

        // flatenRecipe powinno zawsze zwracać tablicę z kluczami materials/subrecipes
        if (!is_array($res)) {
            throw new CustomException("FLATTEN_RECIPE_INVALID_RESULT", 'errors', 500);
        }

        $this->flattenedRecipeIngredientRepository->delete($recipeId, 'recipe_id');
        $this->flattenedRecipeRecipeRepository->delete($recipeId, 'recipe_id');

        foreach ($res['materials'] as $material) {
            $material['id_recipe'] = (int)$recipeId;
            $r = $this->flattenedRecipeIngredientRepository->insert([
                'recipe_id' => $material['id_recipe'],
                'material_id' => $material['id_material'],
                'quantity' => $material['quantity']
            ]);
            if (!$r) {
                throw new CustomException("INSERT_FLATTENED_INGREDIENT_ERROR", 'errors', 500);
            }
        }

        foreach ($res['subrecipes'] as $subrecipeId) {
            $r = $this->flattenedRecipeRecipeRepository->insert([
                'recipe_id' => (int)$recipeId,
                'subrecipe_id' => (int)$subrecipeId
            ]);
            if (!$r) {
                throw new CustomException("INSERT_FLATTENED_SUBRECIPE_ERROR", 'errors', 500);
            }
        }

        return true;
    }

    public function flatForSubrecipe($id, $useTransaction = true)
    {
        if($useTransaction) {
            return $this->transactionManager->execute(fn() => $this->doFlatteningWithSaveForSubrecipeSingle((int)$id));
        }
        return $this->doFlatteningWithSaveForSubrecipeSingle((int)$id);
    }

    private function doFlatteningWithSaveForSubrecipeSingle($id)
    {
        $relatedRecipes = $this->flattenedRecipeRecipeRepository->getRecipesBySubrecipeId($id);
        $relatedRecipesOnlyIds = array_map(fn($r) => (int)$r['recipe_id'], $relatedRecipes);
        $relatedRecipesOnlyIds[] = (int)$id; // dodajemy też samą subrecepturę, bo może być użyta jako główna receptura

        foreach ($relatedRecipesOnlyIds as $recipeId) {
            $this->doFlatteningWithSaveForSingle($recipeId);
        }

        return true;
    }
}

