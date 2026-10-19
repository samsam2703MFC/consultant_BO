<?php

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Services\Product\ProductProfitabilityService;
use App\Repositories\Production\Cost\CostMonthRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Product Profitability Controller
 * MVP v1 for product profitability analysis
 */
class ProfitabilityController extends BaseController
{
    private ProductProfitabilityService $profitabilityService;
    private CostMonthRepository $costMonthRepo;

    public function __construct(
        ProductProfitabilityService $profitabilityService,
        CostMonthRepository $costMonthRepo
    ) {
        $this->profitabilityService = $profitabilityService;
        $this->costMonthRepo = $costMonthRepo;
    }

    /**
     * List view: All products with KPIs (cost, profit, margin)
     */
    public function list()
    {
        $searchTerm = $_GET['search'] ?? null;
        $costMonthId = isset($_GET['cost_month_id']) ? (int)$_GET['cost_month_id'] : null;

        $data['products'] = $this->profitabilityService->getProductListWithKPIs($searchTerm, $costMonthId);
        $data['search'] = $searchTerm;
        $data['cost_month_id'] = $costMonthId;

        // Lista dostępnych miesięcy rozliczeniowych
        $data['cost_months'] = $this->costMonthRepo->listAll(24);
        $data['selected_cost_month'] = $costMonthId
            ? $this->costMonthRepo->getById($costMonthId)
            : $this->costMonthRepo->getLatest();

        $this->view("product/profitability/list", $data);
    }

    /**
     * Details view: Single product profitability breakdown
     */
    public function details(int $id)
    {
        try {
            $costMonthId = isset($_GET['cost_month_id']) ? (int)$_GET['cost_month_id'] : null;

            $data['profitability'] = $this->profitabilityService->calculateProfitability($id, null, null, $costMonthId);
            $data['cost_month_id'] = $costMonthId;

            // Lista dostępnych miesięcy rozliczeniowych
            $data['cost_months'] = $this->costMonthRepo->listAll(24);
            $data['selected_cost_month'] = $costMonthId
                ? $this->costMonthRepo->getById($costMonthId)
                : $this->costMonthRepo->getLatest();

            $this->view("product/profitability/details", $data);
        } catch (\Exception $e) {
            $this->view("404");
        }
    }

    /**
     * AJAX endpoint: Calculate "what-if" scenarios
     * POST /ajax/products/{id}/profitability/calculate
     * Body: { "sales_price": 10.50, "monthly_quantity": 5000, "cost_month_id": 123 }
     */
    public function ajaxCalculate(int $id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        try {
            $salesPrice = $data['sales_price'] ?? null;
            $monthlyQty = $data['monthly_quantity'] ?? null;
            $costMonthId = isset($data['cost_month_id']) ? (int)$data['cost_month_id'] : null;

            $profitability = $this->profitabilityService->calculateProfitability(
                $id,
                $salesPrice,
                $monthlyQty,
                $costMonthId
            );

            return $this->json([
                'success' => true,
                'data' => $profitability
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
