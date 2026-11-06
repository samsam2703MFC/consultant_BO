<?php
namespace App\Services\Recipe;

use App\Repositories\Recipe\RecipeCompetencyRepository;

class RecipeCompetencyService
{
    public function __construct(
        private RecipeCompetencyRepository $repository
    ) {}

    // -------------------------------------------------------
    // CRUD kompetencji
    // -------------------------------------------------------

    public function getAll(): array
    {
        return $this->repository->findAll();
    }

    public function getById(int $id): ?array
    {
        return $this->repository->first(['id' => $id]);
    }

    public function create(array $data): int|false
    {
        return $this->repository->insert($data);
    }

    public function update(int $id, array $data): int|false
    {
        return $this->repository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    public function validate(array $data): array
    {
        $errors = [];
        if (empty(trim($data['name'] ?? ''))) {
            $errors[] = 'Nazwa kompetencji jest wymagana.';
        }
        return $errors;
    }

    // -------------------------------------------------------
    // Powiązania receptura <-> kompetencja
    // -------------------------------------------------------

    public function getByRecipeId(int $recipeId): array
    {
        return $this->repository->getByRecipeId($recipeId);
    }

    public function getCompetencyIdsByRecipeId(int $recipeId): array
    {
        return $this->repository->getCompetencyIdsByRecipeId($recipeId);
    }

    /**
     * Synchronizuje (nadpisuje) przypisane kompetencje do receptury.
     * $competencyIds może być pustą tablicą — usuwa wtedy wszystkie powiązania.
     */
    public function syncRecipeCompetencies(int $recipeId, array $competencyIds): void
    {
        $this->repository->syncRecipeCompetencies($recipeId, $competencyIds);
    }
}

