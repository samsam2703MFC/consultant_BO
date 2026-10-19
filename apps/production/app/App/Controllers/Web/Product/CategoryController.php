<?php

namespace App\Controllers\Web\Product;


use App\Core\Web\BaseController;
use App\Services\Product\Category\ProductCategoryService;
use Symfony\Component\HttpFoundation\Request;

class CategoryController extends BaseController
{
    private ProductCategoryService $finalProductCategoryService;

    public function __construct(
        ProductCategoryService $finalProductCategoryService
    )
    {
        $this->finalProductCategoryService = $finalProductCategoryService;
    }


    public function overview()
    {
        $data['rows'] = $this->finalProductCategoryService->getExistedCategoryInProductList();

        $this->view("product/category/overview", $data);
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
