<?php
namespace App\Services\Product\Category;

use App\Repositories\Product\Category\CategoryProductRepository;

class ProductCategoryService
{
    private CategoryProductRepository $categoryProductRepository;

    public function __construct(CategoryProductRepository $categoryProductRepository)
    {
        $this->categoryProductRepository = $categoryProductRepository;
    }

    /** Kategorie z liczbą produktów : zakładka « Kategorie » w Kartach produktów. */
    public function allWithCounts(): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'target_margin_percentage' => $r['target_margin_percentage'] !== null ? (float) $r['target_margin_percentage'] : null,
            'qty_active' => (int) $r['qty_active'], 'qty_inactive' => (int) $r['qty_inactive'],
        ], $this->categoryProductRepository->allWithCounts());
    }

    public function findAll()
    {
        return $this->categoryProductRepository->findAll();
    }

    public function getById($id)
    {
        return $this->categoryProductRepository->first(['id' => $id]);
    }

    public function update($id, array $data)
    {
        // Walidacja danych
        $allowedFields = ['name', 'target_margin_percentage'];
        $filteredData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($filteredData)) {
            return [
                'success' => false,
                'message' => 'Brak poprawnych danych do aktualizacji'
            ];
        }

        // Sprawdzamy czy kategoria istnieje
        $category = $this->getById($id);
        if (!$category) {
            return [
                'success' => false,
                'message' => 'Kategoria nie została znaleziona'
            ];
        }

        $result = $this->categoryProductRepository->updateCategory($id, $filteredData);

        return [
            'success' => (bool)$result,
            'message' => $result ? 'Kategoria została zaktualizowana' : 'Nie udało się zaktualizować kategorii'
        ];
    }

    public function create(array $data)
    {
        // Walidacja danych
        if (empty($data['name'])) {
            return [
                'success' => false,
                'message' => 'Nazwa kategorii jest wymagana'
            ];
        }

        $categoryData = [
            'name' => $data['name'],
            'target_margin_percentage' => $data['target_margin_percentage'] ?? 0
        ];

        $result = $this->categoryProductRepository->createCategory($categoryData);

        return [
            'success' => (bool)$result,
            'message' => $result ? 'Kategoria została utworzona' : 'Nie udało się utworzyć kategorii',
            'id' => $result
        ];
    }

}