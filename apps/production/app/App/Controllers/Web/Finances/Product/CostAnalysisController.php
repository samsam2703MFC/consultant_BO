<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Finances\CostAnalysisService;
use Symfony\Component\HttpFoundation\Request;

class CostAnalysisController extends BaseController
{
    public function __construct(
        private CostAnalysisService $costAnalysisService
    ) {
    }

    #[Route('GET', '/finances/cost-analysis')]
    public function overview()
    {
        $request = Request::createFromGlobals();
        $productId = (int)$request->query->get('product_id', 0);
        $monthId = (int)$request->query->get('month_id', 0);

        $data = [
            'products' => $this->costAnalysisService->getProducts("fp.name"),
            'months' => $this->costAnalysisService->getCostMonths(),
            'selected_product_id' => $productId,
            'selected_month_id' => $monthId,
            'analysis' => null
        ];

        if ($productId > 0) {
            $data['analysis'] = $this->costAnalysisService->calculate($productId, $monthId);
        }

        $this->view('finances/cost-analysis/overview', $data);
    }
}

