<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\CategoryProfitabilityService;
use Symfony\Component\HttpFoundation\Request;

class CategoryProfitabilityController extends BaseController
{
    private CategoryProfitabilityService $profitabilityService;

    public function __construct(CategoryProfitabilityService $profitabilityService)
    {
        $this->profitabilityService = $profitabilityService;
    }

    /**
     * Display list of categories with profitability KPIs
     */
    public function list(): void
    {
        $searchTerm = $_GET['search'] ?? null;
        
        try {
            $categories = $this->profitabilityService->getCategoriesWithKPIs();
            
            // Filter by search if provided
            if (!empty($searchTerm)) {
                $categories = array_filter($categories, function($cat) use ($searchTerm) {
                    return stripos($cat['name'], $searchTerm) !== false;
                });
            }

            $data['categories'] = $categories;
            $data['search'] = $searchTerm;
            
            $this->view('product/category_profitability/list', $data);
        } catch (\Exception $e) {
            error_log("Error in CategoryProfitabilityController::list: " . $e->getMessage());
            $data['categories'] = [];
            $data['error'] = 'Wystąpił błąd podczas pobierania danych: ' . $e->getMessage();
            $data['search'] = $searchTerm;
            $this->view('product/category_profitability/list', $data);
        }
    }

    /**
     * Display detailed profitability view for a category
     */
    public function details(int $id): void
    {
        try {
            $data['category'] = $this->profitabilityService->getCategoryDetails($id);
            $this->view('product/category_profitability/details', $data);
        } catch (\Exception $e) {
            error_log("Error in CategoryProfitabilityController::details: " . $e->getMessage());
            $this->view('404');
        }
    }

    /**
     * AJAX endpoint for what-if calculations
     */
    public function ajaxCalculate(int $id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);
        
        try {
            // Validate input
            if (!isset($data['sales_price']) || !isset($data['monthly_quantity'])) {
                throw new \Exception('Missing required parameters');
            }

            $salesPrice = (float)$data['sales_price'];
            $monthlyQuantity = (int)$data['monthly_quantity'];

            if ($salesPrice <= 0) {
                throw new \Exception('Cena sprzedaży musi być większa od 0');
            }

            if ($monthlyQuantity <= 0) {
                throw new \Exception('Ilość miesięczna musi być większa od 0');
            }

            $result = $this->profitabilityService->calculateWhatIf($id, $salesPrice, $monthlyQuantity);
            
            return $this->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            error_log("Error in CategoryProfitabilityController::ajaxCalculate: " . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
