<?php
namespace App\Services\Recipe;



use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\FlattenedRecipeIngredientRepository;
use App\Repositories\Product\Recipe\PredefinedRecipeLabelRepository;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Repositories\Settings\LanguageRepository;

class PredefinedRecipeLabelService
{

    public function __construct(
        private PredefinedRecipeLabelRepository $predefinedRecipeLabelRepository,
        private FlattenedRecipeIngredientRepository $flattenedRecipeIngredientRepository,
        private RecipeRepository $recipeRepository,
        private LanguageRepository $languageRepository
    )
    {
    }

    public function buildForRecipe($recipeId, $langCode)
    {
        $concatStr = $this->flattenedRecipeIngredientRepository->getForLabel($recipeId, $langCode);

        $this->predefinedRecipeLabelRepository->deleteByRecipeAndLang((int)$recipeId, $langCode);

        return $this->predefinedRecipeLabelRepository->insert([
            'recipe_id' => $recipeId,
            'lang_code' => $langCode,
            'allergens' => $concatStr['allergens'] ?? "",
            'ingredients' => $concatStr['ingredients'] ?? "",
            'nutriscore' => $concatStr['nutriscore'] ?? ""
        ]);
    }

    public function buildForAllRecipes()
    {
        $languages = $this->languageRepository->findActive();

        if (empty($languages)) {
            $languages = [['code' => 'fr']];
        }

        $recipes = $this->recipeRepository->getByType(3);
        foreach ($recipes as $recipe) {
            foreach ($languages as $language) {
                $this->buildForRecipe($recipe['id'], $language['code']);
            }
        }

        return true;
    }
}