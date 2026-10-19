<?php
namespace App\Repositories\Recipe;

use App\Core\Helpers\BaseRepository;

class RecipeCompetencyRepository extends BaseRepository
{
    protected string $table = 'recipe_competency';

    protected $allowedColumns = ['name', 'description'];


    // -------------------------------------------------------
    // Powiązania receptura <-> kompetencja
    // -------------------------------------------------------

    /**
     * Zwraca kompetencje przypisane do danej receptury
     */
    public function getByRecipeId(int $recipeId): array
    {
        $sql = "SELECT rc.*
                FROM recipe_competency rc
                INNER JOIN recipe_competency_connection rcc ON rcc.competency_id = rc.id
                WHERE rcc.recipe_id = :recipe_id
                ORDER BY rc.name ASC";
        return $this->query($sql, ['recipe_id' => $recipeId]) ?: [];
    }

    /**
     * Nadpisuje kompetencje receptury (usuwa stare, wstawia nowe)
     */
    public function syncRecipeCompetencies(int $recipeId, array $competencyIds): void
    {
        $this->query("DELETE FROM recipe_competency_connection WHERE recipe_id = :recipe_id", ['recipe_id' => $recipeId]);

        foreach ($competencyIds as $competencyId) {
            $this->query(
                "INSERT IGNORE INTO recipe_competency_connection (recipe_id, competency_id) VALUES (:recipe_id, :competency_id)",
                ['recipe_id' => $recipeId, 'competency_id' => (int) $competencyId]
            );
        }
    }

    /**
     * Zwraca IDs kompetencji przypisanych do receptury
     */
    public function getCompetencyIdsByRecipeId(int $recipeId): array
    {
        $sql = "SELECT competency_id FROM recipe_competency_connection WHERE recipe_id = :recipe_id";
        $rows = $this->query($sql, ['recipe_id' => $recipeId]);
        if (!$rows) return [];
        return array_column($rows, 'competency_id');
    }
}

