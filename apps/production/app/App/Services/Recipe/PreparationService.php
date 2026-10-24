<?php
namespace App\Services\Recipe;

use App\Repositories\Product\Recipe\RecipePreparationRepository;
use App\Repositories\Product\Recipe\RecipeRepository;

class PreparationService
{
    private RecipePreparationRepository $recipePreparationRepository;

    public function __construct(
        RecipePreparationRepository $recipePreparationRepository
    )
    {
        $this->recipePreparationRepository = $recipePreparationRepository;
    }

    public function getPreparationTypes()
    {
        return $this->recipePreparationRepository->getPreparationTypes();
    }
}