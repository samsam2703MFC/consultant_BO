<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Repositories\Product\Recipe\RecipeRepository;
use App\Services\Finances\ProductMarginService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ProductMarginController extends BaseController
{
    public function __construct(
        private ProductMarginService $productMarginService,
        private RecipeRepository     $recipeRepository,
    ) {
    }

    #[Route('GET', '/finances/product-margin')]
    public function overview(): void
    {
        $request   = Request::createFromGlobals();
        $productId = (int)$request->query->get('product_id', 0);
        $months    = (int)$request->query->get('months', 12);
        $months    = max(1, min(24, $months));

        $availableCount = $this->productMarginService->getAvailableMonthsCount();

        $data = [
            'products'               => $this->productMarginService->getProducts(),
            'selected_product_id'    => $productId,
            'selected_months'        => $months,
            'available_months_count' => $availableCount,
            'analysis'               => null,
        ];

        if ($productId > 0) {
            $data['analysis'] = $this->productMarginService->calculate($productId, $months);
        }

        $this->view('finances/product-margin/overview', $data);
    }

    /**
     * AJAX: zwraca strukturę foodcost (drzewo receptury) dla danego produktu.
     * GET /ajax/product-margin/foodcost-breakdown?product_id=X
     */
    public function ajaxFoodcostBreakdown(): JsonResponse
    {
        $request   = Request::createFromGlobals();
        $productId = (int)$request->query->get('product_id', 0);

        if ($productId <= 0) {
            return $this->json(['error' => 'Brak product_id'], 400);
        }

        // Pobierz id_recipe dla produktu
        $product = $this->productMarginService->getProductBasicData($productId);
        if (empty($product) || empty($product['id_recipe'])) {
            return $this->json(['error' => 'Produkt nie ma przypisanej receptury.', 'data' => null], 200);
        }

        $idRecipe   = (int)$product['id_recipe'];
        $calculation = $this->recipeRepository->getRecipeCalculation($idRecipe);

        if (empty($calculation)) {
            return $this->json(['error' => 'Brak danych receptury.', 'data' => null], 200);
        }

        return $this->json([
            'error' => null,
            'data'  => $calculation,
        ]);
    }
}

