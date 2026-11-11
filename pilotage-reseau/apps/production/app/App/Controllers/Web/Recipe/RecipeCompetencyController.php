<?php
namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Services\Recipe\RecipeCompetencyService;
use Symfony\Component\HttpFoundation\JsonResponse;

class RecipeCompetencyController extends BaseController
{
    public function __construct(
        private RecipeCompetencyService $competencyService
    ) {}





    /**
     * GET /ajax/recipe-competencies
     * Pobierz listę wszystkich kompetencji (AJAX)
     */
    public function ajaxGetAll(): JsonResponse
    {
        $competencies = $this->competencyService->getAll();
        return $this->json($competencies);
    }

    /**
     * GET /ajax/recipes/{recipeId}/competencies
     * Pobierz kompetencje przypisane do receptury
     */
    public function ajaxGetByRecipe(int $recipeId): JsonResponse
    {
        $ids = $this->competencyService->getCompetencyIdsByRecipeId($recipeId);
        return $this->json(['assigned_ids' => $ids]);
    }

    /**
     * POST /ajax/recipes/{recipeId}/competencies/sync
     * Synchronizuj kompetencje przypisane do receptury
     */
    public function ajaxSyncRecipeCompetencies(int $recipeId): JsonResponse
    {
        $payload = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $ids     = array_map('intval', $payload['competency_ids'] ?? []);

        $this->competencyService->syncRecipeCompetencies($recipeId, $ids);

        $assigned = $this->competencyService->getByRecipeId($recipeId);
        return $this->json(['ok' => true, 'assigned' => $assigned]);
    }
}

