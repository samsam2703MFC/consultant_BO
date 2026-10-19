<?php
namespace App\Controllers\Web\Stock;

use App\Core\Web\BaseController;
use App\Services\Stock\FinalProductStockService;
use Symfony\Component\HttpFoundation\Request;

class FinalProductStockController extends  BaseController
{

    private FinalProductStockService $finalProductStockService;

    public function __construct(
        FinalProductStockService $finalProductStockService
    )
    {
        $this->finalProductStockService = $finalProductStockService;
    }

    public function overview()
    {
        $data['categories'] = $this->finalProductStockService->getCategoryProductInStock();

        $this->view("stock/final_product/overview", $data);
    }

    public function supplyMonitor()
    {
        $data = $this->finalProductStockService->getSupplyMonitorData();

        $this->view("stock/final_product/supply_monitor", $data);
    }

    public function ajaxSupplyMonitor()
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);
        $orderIds = $data['order_ids'] ?? [];

        if (!is_array($orderIds)) {
            $orderIds = [];
        }

        return $this->json($this->finalProductStockService->getSupplyMonitorData($orderIds));
    }

    public function stockState()
    {
        $data['rows'] = $this->finalProductStockService->getStockState();

        $this->view("stock/final_product/stock_state", $data);
    }

    public function filterByCategory($idCategory)
    {
        $data['rows'] = $this->finalProductStockService->getStockStateByCategory($idCategory);

        $this->view("stock/final_product/filter", $data);
    }
}
