<?php

namespace App\Services\Recipe;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Product\Recipe\PreparationStepRepository;
use App\Repositories\Product\Recipe\RecipeRepository;

class PreparationStepService
{
    public function __construct(
        private PreparationStepRepository $stepRepository,
        private RecipeRepository          $recipeRepository,
        private TransactionManager        $transactionManager
    ) {}

    /**
     * Pobiera dane receptury (podstawowe) lub rzuca wyjątek gdy nie istnieje
     */
    public function getRecipeBasicData(int $recipeId): array
    {
        $data = $this->recipeRepository->getRecipeBasicData($recipeId);
        if (empty($data)) {
            throw new CustomException("RECIPE_NOT_FOUND", 'errors', 404);
        }
        return $data;
    }

    /**
     * Pobiera listę kroków przygotowania dla receptury
     */
    public function getSteps(int $recipeId): array
    {
        return $this->stepRepository->getStepsByRecipeId($recipeId);
    }

    /**
     * Dodaje nowy krok na końcu listy (lub na wskazanej pozycji jeśli podano insertAfter)
     *
     * @param int      $recipeId
     * @param string   $description  treść kroku
     * @param int|null $insertAfter  jeśli podano, krok zostaje wstawiony PO kroku o tym stepNumber
     * @return int  id nowego rekordu
     */
    public function addStep(int $recipeId, string $description, ?int $insertAfter = null): int
    {
        $description = trim($description);
        if ($description === '') {
            throw new CustomException("STEP_DESCRIPTION_EMPTY", 'errors', 422);
        }

        return $this->transactionManager->execute(function () use ($recipeId, $description, $insertAfter) {

            if ($insertAfter !== null) {
                // wstaw po wskazanym kroku – przesuń wyżej
                $newStepNumber = $insertAfter + 1;
                $this->stepRepository->shiftStepsUp($recipeId, $newStepNumber);
            } else {
                // dołącz na końcu
                $newStepNumber = $this->stepRepository->getMaxStepNumber($recipeId) + 1;
            }

            $id = $this->stepRepository->insert([
                'recipe_id'        => $recipeId,
                'step_number'      => $newStepNumber,
                'step_description' => $description,
            ]);

            if (!$id) {
                throw new CustomException("STEP_INSERT_FAILED", 'errors', 500);
            }

            return (int)$id;
        });
    }

    /**
     * Aktualizuje opis istniejącego kroku
     */
    public function updateStep(int $recipeId, int $stepId, string $description): void
    {
        $description = trim($description);
        if ($description === '') {
            throw new CustomException("STEP_DESCRIPTION_EMPTY", 'errors', 422);
        }

        $step = $this->stepRepository->getStepById($stepId, $recipeId);
        if (empty($step)) {
            throw new CustomException("STEP_NOT_FOUND", 'errors', 404);
        }

        $ok = $this->stepRepository->updateStep($stepId, $step['step_number'], $description);
        if (!$ok) {
            throw new CustomException("STEP_UPDATE_FAILED", 'errors', 500);
        }
    }

    /**
     * Usuwa krok i renumeruje pozostałe
     */
    public function deleteStep(int $recipeId, int $stepId): void
    {
        $step = $this->stepRepository->getStepById($stepId, $recipeId);
        if (empty($step)) {
            throw new CustomException("STEP_NOT_FOUND", 'errors', 404);
        }

        $this->transactionManager->execute(function () use ($recipeId, $stepId, $step) {
            $this->stepRepository->delete($stepId);
            $this->stepRepository->shiftStepsDown($recipeId, $step['step_number']);
            return true;
        });
    }

    /**
     * Przesuwa krok w górę (zmniejsza numer o 1)
     */
    public function moveStepUp(int $recipeId, int $stepId): void
    {
        $step = $this->stepRepository->getStepById($stepId, $recipeId);
        if (empty($step) || $step['step_number'] <= 1) {
            return; // już na górze
        }

        $steps = $this->stepRepository->getStepsByRecipeId($recipeId);
        $prevStep = null;
        foreach ($steps as $s) {
            if ($s['step_number'] === $step['step_number'] - 1) {
                $prevStep = $s;
                break;
            }
        }

        if ($prevStep) {
            $this->stepRepository->swapStepNumbers(
                $recipeId,
                $stepId,             $step['step_number'] - 1,
                (int)$prevStep['id'], $step['step_number']
            );
        }
    }

    /**
     * Przesuwa krok w dół (zwiększa numer o 1)
     */
    public function moveStepDown(int $recipeId, int $stepId): void
    {
        $step  = $this->stepRepository->getStepById($stepId, $recipeId);
        $maxSN = $this->stepRepository->getMaxStepNumber($recipeId);

        if (empty($step) || $step['step_number'] >= $maxSN) {
            return; // już na dole
        }

        $steps    = $this->stepRepository->getStepsByRecipeId($recipeId);
        $nextStep = null;
        foreach ($steps as $s) {
            if ($s['step_number'] === $step['step_number'] + 1) {
                $nextStep = $s;
                break;
            }
        }

        if ($nextStep) {
            $this->stepRepository->swapStepNumbers(
                $recipeId,
                $stepId,              $step['step_number'] + 1,
                (int)$nextStep['id'], $step['step_number']
            );
        }
    }

    /**
     * Zapisuje nową kolejność kroków (tablica id w żądanej kolejności)
     */
    public function reorderSteps(int $recipeId, array $orderedIds): void
    {
        $this->transactionManager->execute(function () use ($recipeId, $orderedIds) {
            foreach ($orderedIds as $pos => $id) {
                $id = (int)$id;
                $newStep = $pos + 1;
                $this->stepRepository->query(
                    "UPDATE product_recipe_preparation_step
                     SET step_number = :step_number
                     WHERE id = :id AND recipe_id = :recipe_id",
                    ['step_number' => $newStep, 'id' => $id, 'recipe_id' => $recipeId]
                );
            }
            return true;
        });
    }
}

