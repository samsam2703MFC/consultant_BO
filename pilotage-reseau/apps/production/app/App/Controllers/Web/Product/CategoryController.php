<?php

namespace App\Controllers\Web\Product;


use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Product\Category\ProductCategoryService;
use Symfony\Component\HttpFoundation\Request;

class CategoryController extends BaseController
{
    private ProductCategoryService $finalProductCategoryService;

    public function __construct(
        ProductCategoryService $finalProductCategoryService,
        private PageAccessService $access
    )
    {
        $this->finalProductCategoryService = $finalProductCategoryService;
    }


    /** Kategorie z liczbą produktów dla zakładki « Kategorie » w Kartach produktów (dawna strona /product-categories). */
    public function ajaxList()
    {
        return $this->json(['ok' => true, 'rows' => $this->finalProductCategoryService->allWithCounts(),
            'can_toggle' => $this->access->canToggleProducts($this->userId())]);
    }

    /** [339] PUT /ajax/product-categories/{id}/active {active: 0|1} : wszystkie produkty kategorii (administrator, produkcja). */
    public function ajaxSetActive($id)
    {
        if (!$this->access->canToggleProducts($this->userId())) {
            return $this->json(['ok' => false, 'message' => 'Włączać i wyłączać kategorie może administrator albo produkcja.'], 403);
        }
        $data = $this->getJson(Request::createFromGlobals());
        if (!isset($data['active'])) return $this->json(['ok' => false, 'message' => 'Brak danych wejściowych'], 400);
        try {
            $changed = $this->finalProductCategoryService->setActive((int) $id, (bool) $data['active']);
        } catch (CustomException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        }
        return $this->json(['ok' => true, 'changed' => $changed]);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }

    public function ajaxUpdateCategory($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['ok' => false, 'message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->finalProductCategoryService->update($id, $data);

        return $this->json([
            'ok' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }

    public function ajaxCreateCategory()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['ok' => false, 'message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->finalProductCategoryService->create($data);

        return $this->json([
            'ok' => $result['success'],
            'message' => $result['message'],
            'id' => $result['id'] ?? null
        ], $result['success'] ? 201 : 400);
    }
}
