<?php

namespace App\Controllers\Web\Material;


use App\Core\Web\BaseController;
use App\Services\Material\MaterialCategoryService;
use App\Services\Product\Category\ProductCategoryService;

class CategoryController extends BaseController
{
    private MaterialCategoryService $materialCategoryService;

    public function __construct(
        MaterialCategoryService $materialCategoryService
    )
    {
        $this->materialCategoryService = $materialCategoryService;
    }


    public function overview()
    {
        $data['rows'] = $this->materialCategoryService->getUsed();

        $this->view("material/category/overview", $data);
    }

    public function createCategory()
    {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $data = $this->getJson($request);

        // Validate required fields
        if (empty($data['name'])) {
            return $this->json(['ok' => false, 'message' => 'Nazwa kategorii jest wymagana'], 400);
        }

        // Set id_group to 0 as required
        $categoryData = [
            'id_group' => 0,
            'name' => $data['name']
        ];

        try {
            $result = $this->materialCategoryService->create($categoryData);
            
            if ($result) {
                return $this->json([
                    'ok' => true,
                    'message' => 'Kategoria została utworzona pomyślnie',
                    'id' => $result
                ], 201);
            } else {
                return $this->json(['ok' => false, 'message' => 'Nie udało się utworzyć kategorii'], 500);
            }
        } catch (\Exception $e) {
            return $this->json(['ok' => false, 'message' => 'Błąd: ' . $e->getMessage()], 500);
        }
    }

    public function updateCategory($id)
    {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $data = $this->getJson($request);

        // Validate required fields
        if (empty($data['name'])) {
            return $this->json(['ok' => false, 'message' => 'Nazwa kategorii jest wymagana'], 400);
        }

        // Set id_group to 0 as required
        $categoryData = [
            'id_group' => 0,
            'name' => $data['name']
        ];

        try {
            $result = $this->materialCategoryService->update($id, $categoryData);

            if ($result) {
                return $this->json([
                    'ok' => true,
                    'message' => 'Kategoria została zaktualizowana pomyślnie'
                ], 200);
            } else {
                return $this->json(['ok' => false, 'message' => 'Nie udało się zaktualizować kategorii'], 500);
            }
        } catch (\Exception $e) {
            return $this->json(['ok' => false, 'message' => 'Błąd: ' . $e->getMessage()], 500);
        }
    }

}
