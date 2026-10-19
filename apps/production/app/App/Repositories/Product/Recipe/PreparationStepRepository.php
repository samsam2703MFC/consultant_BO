<?php

namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;

/**
 * PreparationStepRepository
 */
class PreparationStepRepository extends BaseRepository
{

    protected string $table = 'product_recipe_preparation_step';

    protected $allowedColumns = [
        'recipe_id',
        'step_number',
        'step_description'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'recipe_id',
        'step_number',
        'step_description'
    ];

    /**
     * Pobiera wszystkie kroki dla danej receptury posortowane wg numeru kroku
     */
    public function getStepsByRecipeId(int $recipeId): array
    {
        return $this->query(
            "SELECT id, recipe_id, step_number, step_description
             FROM {$this->table}
             WHERE recipe_id = :recipe_id
             ORDER BY step_number ASC",
            ['recipe_id' => $recipeId]
        ) ?: [];
    }

    /**
     * Pobiera maksymalny numer kroku dla danej receptury
     */
    public function getMaxStepNumber(int $recipeId): int
    {
        $result = $this->query(
            "SELECT COALESCE(MAX(step_number), 0) AS max_step
             FROM {$this->table}
             WHERE recipe_id = :recipe_id",
            ['recipe_id' => $recipeId]
        );
        return (int)($result[0]['max_step'] ?? 0);
    }

    /**
     * Pobiera jeden krok po id (tylko jeśli należy do receptury)
     */
    public function getStepById(int $id, int $recipeId): array
    {
        return $this->first(['id' => $id, 'recipe_id' => $recipeId]);
    }

    /**
     * Przesuwa numery kroków >= $fromStep w górę o 1 (przy wstawianiu)
     */
    public function shiftStepsUp(int $recipeId, int $fromStep): void
    {
        $this->query(
            "UPDATE {$this->table}
             SET step_number = step_number + 1
             WHERE recipe_id = :recipe_id AND step_number >= :from_step",
            ['recipe_id' => $recipeId, 'from_step' => $fromStep]
        );
    }

    /**
     * Przesuwa numery kroków > $deletedStep w dół o 1 (po usunięciu)
     */
    public function shiftStepsDown(int $recipeId, int $deletedStep): void
    {
        $this->query(
            "UPDATE {$this->table}
             SET step_number = step_number - 1
             WHERE recipe_id = :recipe_id AND step_number > :deleted_step",
            ['recipe_id' => $recipeId, 'deleted_step' => $deletedStep]
        );
    }

    /**
     * Aktualizuje numer kroku i opis
     */
    public function updateStep(int $id, int $stepNumber, string $description): bool
    {
        return (bool)$this->query(
            "UPDATE {$this->table}
             SET step_number = :step_number, step_description = :step_description
             WHERE id = :id",
            ['id' => $id, 'step_number' => $stepNumber, 'step_description' => $description]
        );
    }

    /**
     * Zamienia numery kroków dwóch rekordów (drag & drop / przesuwanie)
     */
    public function swapStepNumbers(int $recipeId, int $idA, int $stepA, int $idB, int $stepB): void
    {
        // użyj wartości tymczasowej aby uniknąć konfliktu UNIQUE
        $tmp = PHP_INT_MAX;
        $this->query("UPDATE {$this->table} SET step_number = :tmp WHERE id = :id AND recipe_id = :rid",
            ['tmp' => $tmp, 'id' => $idA, 'rid' => $recipeId]);
        $this->query("UPDATE {$this->table} SET step_number = :s WHERE id = :id AND recipe_id = :rid",
            ['s' => $stepA, 'id' => $idB, 'rid' => $recipeId]);
        $this->query("UPDATE {$this->table} SET step_number = :s WHERE id = :id AND recipe_id = :rid",
            ['s' => $stepB, 'id' => $idA, 'rid' => $recipeId]);
    }
}