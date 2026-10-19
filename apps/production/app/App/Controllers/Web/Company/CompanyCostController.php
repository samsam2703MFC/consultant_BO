<?php
namespace App\Controllers\Web\Company;

use App\Core\Web\BaseController;
use App\Repositories\Employee\Section\ProductionSectionRepository;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Utils\CompanyCostService;
use Symfony\Component\HttpFoundation\Request;

class CompanyCostController extends BaseController
{
    private ProductionSectionService $productionSectionService;
    private CompanyCostService $companyCostService;

    public function __construct(
        ProductionSectionService $productionSectionService,
        CompanyCostService $companyCostService
    )
    {
        $this->productionSectionService = $productionSectionService;
        $this->companyCostService = $companyCostService;
    }

    public function getSectionsOverview()
    {
        $data['sections'] = $this->productionSectionService->getAll();

        $this->view("company/costs", $data);
    }

    public function ajaxUpdateCost($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->companyCostService->update($id, $data);

        return $this->handleAjaxResponse($result, 'Koszt zaktualizowany', 'Nie udało się zaktualizować kosztów');
    }

}