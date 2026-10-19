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
     * GET /hr/recipe-competencies
     * Główny panel zarządzania kompetencjami recepturowymi
     */
    public function overview(): void
    {
        $data['competencies'] = $this->competencyService->getAll();
        $this->view('recipe/recipe_competency/overview', $data);
    }

    /**
     * POST /ajax/recipe-competencies
     * Tworzenie nowej kompetencji (AJAX)
     */
    public function ajaxCreate(): JsonResponse
    {
        $payload = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());

        $errors = $this->competencyService->validate($payload);
        if (!empty($errors)) {
            return $this->json(['ok' => false, 'errors' => $errors], 422);
        }

        $id = $this->competencyService->create($payload);
        if (!$id) {
            return $this->json(['ok' => false, 'message' => 'Błąd zapisu.'], 500);
        }

        $competency = $this->competencyService->getById((int) $id);
        return $this->json(['ok' => true, 'competency' => $competency], 201);
    }

    /**
     * PUT /ajax/recipe-competencies/{id}
     * Aktualizacja kompetencji (AJAX)
     */
    public function ajaxUpdate(int $id): JsonResponse
    {
        $payload = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());

        $errors = $this->competencyService->validate($payload);
        if (!empty($errors)) {
            return $this->json(['ok' => false, 'errors' => $errors], 422);
        }

        $result = $this->competencyService->update($id, $payload);
        if ($result === false) {
            return $this->json(['ok' => false, 'message' => 'Błąd aktualizacji.'], 500);
        }

        $competency = $this->competencyService->getById($id);
        return $this->json(['ok' => true, 'competency' => $competency]);
    }

    /**
     * DELETE /ajax/recipe-competencies/{id}
     * Usunięcie kompetencji (AJAX)
     */
    public function ajaxDelete(int $id): JsonResponse
    {
        $result = $this->competencyService->delete($id);
        if (!$result) {
            return $this->json(['ok' => false, 'message' => 'Błąd usunięcia.'], 500);
        }
        return $this->json(['ok' => true]);
    }

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

