<?php

namespace App\Services\Product;

use App\Repositories\Material\Allergen\AllergenRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Product\Recipe\PreparationStepRepository;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Repositories\Recipe\RecipeCompetencyRepository;
use App\Exceptions\CustomException;

/**
 * Zbiera wszystkie dane potrzebne do wygenerowania karty technicznej
 * zarówno dla produktu finalnego, jak i kompozycji (podreceptury).
 *
 * Zwracana struktura:
 * [
 *   'object'      => [...],   // podstawowe dane produktu/receptury
 *   'recipe'      => [...],   // receptura z elementami + alergenami
 *   'preparation' => [
 *       'types' => [...],     // typy przygotowania (piekarnik, mikser itp.)
 *       'steps' => [...],     // kroki przygotowania
 *   ],
 * ]
 */
class TechnicalCardDataService
{
    public function __construct(
        private FinalProductRepository    $finalProductRepository,
        private RecipeRepository          $recipeRepository,
        private PreparationStepRepository $preparationStepRepository,
        private AllergenRepository        $allergenRepository,
        private RecipeCompetencyRepository $recipeCompetencyRepository,
    ) {
    }

    // -------------------------------------------------------------------------
    // Publiczne API
    // -------------------------------------------------------------------------

    /**
     * Dane do karty technicznej produktu finalnego.
     *
     * @param int $productId  ID z tabeli final_product
     */
    public function getForProduct(int $productId): array
    {
        $product = $this->finalProductRepository->getById($productId);
        if (empty($product)) {
            throw new CustomException("MISSING_PRODUCT", "product", 404);
        }

        $idRecipe = (int)($product['id_recipe'] ?? 0);

        return $this->buildData($product, $idRecipe);
    }

    /**
     * Dane do karty technicznej kompozycji (receptury niezwiązanej z produktem).
     *
     * @param int $recipeId  ID z tabeli product_recipe
     */
    public function getForComposition(int $recipeId): array
    {
        $recipe = $this->recipeRepository->getRecipeBasicData($recipeId);
        if (empty($recipe)) {
            throw new CustomException("MISSING_RECIPE", "recipe", 404);
        }

        // Budujemy obiekt "product-like" z danych receptury
        $object = [
            'id'            => null,
            'id_recipe'     => $recipeId,
            'name'          => $recipe['name'] ?? '-',
            'category_name' => $recipe['type_name'] ?? '-',
            'weight'        => null,
            'standard_price' => null,
        ];

        return $this->buildData($object, $recipeId);
    }

    // -------------------------------------------------------------------------
    // Prywatne
    // -------------------------------------------------------------------------

    private function buildData(array $object, int $idRecipe): array
    {
        // Receptura z elementami i alergenami
        $recipe = [];
        if ($idRecipe > 0) {
            $recipe = $this->recipeRepository->getRecipeCalculation($idRecipe);
            // Wzbogać składniki o alergeny
            if (!empty($recipe['elements'])) {
                $this->enrichWithAllergens($recipe['elements']);
            }
        }

        // Kroki przygotowania
        $steps = $idRecipe > 0
            ? $this->preparationStepRepository->getStepsByRecipeId($idRecipe)
            : [];

        // Typy przygotowania
        $types = $idRecipe > 0
            ? $this->recipeRepository->getRecipePreparationType($idRecipe)
            : [];

        // Kompetencje wymagane do wykonania receptury
        $competencies = $idRecipe > 0
            ? $this->recipeCompetencyRepository->getByRecipeId($idRecipe)
            : [];

        return [
            'object'      => $object,
            'recipe'      => $recipe,
            'preparation' => [
                'types' => $types,
                'steps' => $steps,
            ],
            'competencies' => $competencies,
        ];
    }

    /**
     * Rekurencyjnie dodaje alergeny do składników receptury.
     * Alergeny pobierane są z material_allergen_connection.
     */
    private function enrichWithAllergens(array &$elements): void
    {
        foreach ($elements as &$element) {
            if ($element['type'] === 'ingredient') {
                $materialId = (int)($element['ingredient_id'] ?? $element['id'] ?? 0);
                if ($materialId > 0) {
                    $allergens = $this->allergenRepository->getForMaterial($materialId);
                    $element['allergens'] = array_map(
                        static fn(array $a) => $a['code'],
                        $allergens
                    );
                } else {
                    $element['allergens'] = [];
                }
            } elseif ($element['type'] === 'sub-recipe' && !empty($element['elements'])) {
                $this->enrichWithAllergens($element['elements']);
                // Zbierz alergeny z podreceptury w górę
                $element['allergens'] = $this->collectAllergensFromElements($element['elements']);
            } else {
                $element['allergens'] = [];
            }
        }
        unset($element);
    }

    /**
     * Zbiera unikalne alergeny ze wszystkich składników (płasko).
     */
    private function collectAllergensFromElements(array $elements): array
    {
        $all = [];
        foreach ($elements as $el) {
            if (!empty($el['allergens'])) {
                $all = array_merge($all, $el['allergens']);
            }
        }
        return array_values(array_unique($all));
    }
}

