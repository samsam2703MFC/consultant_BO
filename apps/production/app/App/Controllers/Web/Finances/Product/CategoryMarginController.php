<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Web\BaseController;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Product\Price\FinalProductCustomerPriceRepository;
use App\Services\Finances\ProductMarginService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class CategoryMarginController extends BaseController
{
    public function __construct(
        private ProductMarginService                 $productMarginService,
        private FinalProductRepository               $finalProductRepository,
        private FinalProductCustomerPriceRepository  $customerPriceRepository,
    ) {
    }

    public function overview(): void
    {
        $request  = Request::createFromGlobals();
        $months   = (int)$request->query->get('months', 12);
        $months   = max(1, min(24, $months));

        $available = $this->productMarginService->getAvailableMonthsCount();
        $result    = $this->productMarginService->calculateCategoryOverview($months);

        $this->view('finances/product-margin/categories', [
            'selected_months'        => $months,
            'available_months_count' => $available,
            'result'                 => $result,
        ]);
    }

    /**
     * POST /ajax/category-margin/product-action
     * Obsługuje:
     *   action = 'set_price'      → zmiana ceny standard_price + customer_prices
     *   action = 'deactivate'     → ustawienie active = 0
     */
    public function productAction(): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body    = $this->getJson($request);

        $action    = $body['action']     ?? '';
        $productId = (int)($body['product_id'] ?? 0);

        if ($productId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Brak product_id.'], 400);
        }

        switch ($action) {
            case 'set_price':
                $price = (float)($body['price'] ?? 0);
                if ($price <= 0) {
                    return $this->json(['ok' => false, 'error' => 'Nieprawidłowa cena.'], 400);
                }

                // Aktualizuj cenę standardową
                $this->finalProductRepository->updateStandardPrice($productId, $price);

                // Aktualizuj ceny dla wszystkich klientów
                $this->customerPriceRepository->updateAllPricesForProduct($productId, $price);

                return $this->json([
                    'ok'      => true,
                    'message' => 'Cena produktu została zaktualizowana dla wszystkich cenników.',
                    'price'   => $price,
                ]);

            case 'deactivate':
                $this->finalProductRepository->deactivate($productId);

                return $this->json([
                    'ok'      => true,
                    'message' => 'Produkt został wyłączony ze sprzedaży (active = 0).',
                ]);

            default:
                return $this->json(['ok' => false, 'error' => 'Nieznana akcja: ' . $action], 400);
        }
    }
}

