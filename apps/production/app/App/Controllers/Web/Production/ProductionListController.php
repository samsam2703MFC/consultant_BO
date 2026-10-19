<?php

namespace App\Controllers\Web\Production;


use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Client\Order\OrderService;
use App\Services\Production\ProductionListService;
use App\Services\Production\RequirementListService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductionListController extends BaseController
{

    public function __construct(
        private ProductionListService $productionListService,
        private OrderService $orderService,
        private RequirementListService $requirementListService,
    )
    {
    }


    public function planning()
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

        $this->view("production/production_list/planning", $data);
    }

    public function ajaxGetProductionList()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        //$resp = $this->productionListService->getProductionListPDF($data);
//        show($resp);
// exit();
        $resp = $this->handleAction(function () use ($data) {
            return $this->productionListService->getProductionListPDF($data);
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy produkcyjnej'], 500);
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



    public function ajaxGetComplaintProductionList()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        $resp = $this->handleAction(function () use ($data) {
            return $this->productionListService->getComplaintProductionListPDF();
        }, default: false);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy produkcyjnej'], 500);
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

    #[Route('POST', '/ajax/production-list/requirement/pdf')]
    public function ajaxGetRequirementListPDF()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $resp = $this->requirementListService->getRequirementListPDF($data);

        if (!$resp || !is_array($resp)) {
            return $this->json(['message' => 'Wystąpił błąd przy generowaniu listy zapotrzebowania materiałowego'], 500);
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
