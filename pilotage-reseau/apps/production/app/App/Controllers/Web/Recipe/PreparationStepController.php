<?php

namespace App\Controllers\Web\Recipe;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Recipe\PreparationStepService;
use App\Services\Recipe\RecipeService;

/**
 * Obsługuje widok i akcje kroków przygotowania receptury
 */
class PreparationStepController extends BaseController
{
    public function __construct(
        private PreparationStepService $preparationStepService,
        private RecipeService          $recipeService
    ) {}

    /**
     * GET  /recipes/{id}/preparation-steps
     * Widok z listą kroków danej receptury
     */
    #[Route('GET', '/recipes/{id}/preparation-steps')]
    public function index(int $id): void
    {
        $data['recipe']      = $this->safeFetch(
            fn () => $this->preparationStepService->getRecipeBasicData($id),
            $this->errors, null, []
        );
        $data['steps']       = $this->preparationStepService->getSteps($id);
        $data['recipe_id']   = $id;

        $this->view('recipe/recipe/preparation-steps', $data);
    }

    // ─── AJAX endpoints ────────────────────────────────────────────────────────

    /**
     * POST /ajax/recipes/{id}/preparation-steps
     * Dodaje nowy krok
     */
    #[Route('POST', '/ajax/recipes/{id}/preparation-steps')]
    public function ajaxAdd(int $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $body = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());

        $description  = trim($body['description'] ?? '');
        $insertAfter  = isset($body['insert_after']) && $body['insert_after'] !== null
            ? (int)$body['insert_after']
            : null;

        $result = $this->handleAction(function () use ($id, $description, $insertAfter) {
            $newId = $this->preparationStepService->addStep($id, $description, $insertAfter);
            $steps = $this->preparationStepService->getSteps($id);
            return ['ok' => true, 'new_id' => $newId, 'steps' => $steps];
        });

        return $this->json($result, $result['code'] ?? 200);
    }

    /**
     * PUT /ajax/recipes/{recipeId}/preparation-steps/{stepId}
     * Aktualizuje opis kroku
     */
    #[Route('PUT', '/ajax/recipes/{recipeId}/preparation-steps/{stepId}')]
    public function ajaxUpdate(int $recipeId, int $stepId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $body = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $description = trim($body['description'] ?? '');

        $result = $this->handleAction(function () use ($recipeId, $stepId, $description) {
            $this->preparationStepService->updateStep($recipeId, $stepId, $description);
            $steps = $this->preparationStepService->getSteps($recipeId);
            return ['ok' => true, 'steps' => $steps];
        });

        return $this->json($result, $result['code'] ?? 200);
    }

    /**
     * DELETE /ajax/recipes/{recipeId}/preparation-steps/{stepId}
     * Usuwa krok
     */
    #[Route('DELETE', '/ajax/recipes/{recipeId}/preparation-steps/{stepId}')]
    public function ajaxDelete(int $recipeId, int $stepId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $result = $this->handleAction(function () use ($recipeId, $stepId) {
            $this->preparationStepService->deleteStep($recipeId, $stepId);
            $steps = $this->preparationStepService->getSteps($recipeId);
            return ['ok' => true, 'steps' => $steps];
        });

        return $this->json($result, $result['code'] ?? 200);
    }

    /**
     * POST /ajax/recipes/{recipeId}/preparation-steps/{stepId}/move
     * Zmienia kolejność kroku (body: {"direction": "up"|"down"})
     */
    #[Route('POST', '/ajax/recipes/{recipeId}/preparation-steps/{stepId}/move')]
    public function ajaxMove(int $recipeId, int $stepId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $body      = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $direction = $body['direction'] ?? 'up';

        $result = $this->handleAction(function () use ($recipeId, $stepId, $direction) {
            if ($direction === 'down') {
                $this->preparationStepService->moveStepDown($recipeId, $stepId);
            } else {
                $this->preparationStepService->moveStepUp($recipeId, $stepId);
            }
            $steps = $this->preparationStepService->getSteps($recipeId);
            return ['ok' => true, 'steps' => $steps];
        });

        return $this->json($result, $result['code'] ?? 200);
    }

    /**
     * POST /ajax/recipes/{recipeId}/preparation-steps/reorder
     * Zapisuje nową kolejność kroków (body: {"ordered_ids": [3,1,2]})
     */
    #[Route('POST', '/ajax/recipes/{recipeId}/preparation-steps/reorder')]
    public function ajaxReorder(int $recipeId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $body       = $this->getJson(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $orderedIds = $body['ordered_ids'] ?? [];

        $result = $this->handleAction(function () use ($recipeId, $orderedIds) {
            $this->preparationStepService->reorderSteps($recipeId, $orderedIds);
            $steps = $this->preparationStepService->getSteps($recipeId);
            return ['ok' => true, 'steps' => $steps];
        });

        return $this->json($result, $result['code'] ?? 200);
    }
}

