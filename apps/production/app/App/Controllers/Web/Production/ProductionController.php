<?php

namespace App\Controllers\Web\Production;


use App\Core\Web\BaseController;
use App\Services\Client\Order\OrderService;
use App\Services\Production\ProductionListService;
use App\Services\Production\ProductionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductionController extends BaseController
{
    private ProductionService $productionService;
    private ProductionListService $productionListService;
    private OrderService $orderService;

    public function __construct(
        ProductionService $productionService,
        ProductionListService $productionListService,
        OrderService $orderService
    )
    {
        $this->productionService = $productionService;
        $this->productionListService = $productionListService;
        $this->orderService = $orderService;
    }


    public function standardProductionElement()
    {
        $data['rows'] = $this->orderService->getPending();

        if($_SERVER['REQUEST_METHOD'] == "POST"){

            if(isset($_POST['production_list']) && $_POST['production_list'] == 1){
                $this->productionService->getProductionListPDF($_POST);
            }
            else if(isset($_POST['requirements_list']) && $_POST['requirements_list'] == 1){
                $recipeModel = new RecipeModel();

                $productionData = $productionModel->getProductionListData($_POST);

                $stock_minimum_flag = false;


                if(isset($_POST['stock_minimum'])){
                    $stock_minimum_flag = true;
                }

                $recipeModel->getMaterialRequirementsForProductList($productionData, $stock_minimum_flag);

                exit();
            }

        }

        $this->view("product/category/overview", $data);
    }

    public function ajaxGetProductionList()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }


        $resp = $this->productionListService->getProductionListPDF($_POST);

        if (!$resp) {
            // jeżeli coś poszło nie tak, zwracamy JSON z kodem błędu
            return $this->json($resp, $resp['code']);
        }

        $pdfData  = $resp['file_string'];
        $filename = htmlspecialchars($resp['filename']);


        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }
}
