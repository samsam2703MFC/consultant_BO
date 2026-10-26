<?php

namespace App\Controllers\Web\Material;


use App\Core\Route;
use App\Core\Web\BaseController;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Material\MaterialCategoryService;
use App\Services\Material\MaterialPriceService;
use App\Services\Material\MaterialService;
use App\Services\Material\MaterialSupplierService;
use App\Services\Recipe\RecipeCostRecalculationService;
use App\Services\Product\Category\ProductCategoryService;
use App\Services\Utils\UnitService;
use Symfony\Component\HttpFoundation\Request;

class PriceController extends BaseController
{
    public function __construct(
        private MaterialCategoryService $materialCategoryService,
        private MaterialService         $materialService,
        private MaterialPriceService         $materialPriceService,
        private MaterialSupplierService $materialSupplierService,
        private UnitService             $unitService,
        private RecipeCostRecalculationService $recipeCostRecalculationService,
        private ParamRepository $params
    )
    {}

    /** P21 : prices.manual_edit = 0 → ceny tylko przez ofertę, fakturę zakupową albo zapytanie ofertowe. */
    private function manualEdit(): bool
    {
        return $this->params->get('prices.manual_edit', '1') !== '0';
    }


    #[Route('GET', '/materials/prices')]
    public function overview()
    {
        $data['rows'] = $this->materialService->getAll();
        $data['suppliers'] = $this->materialSupplierService->getAll();
        $data['category_material'] = $this->materialCategoryService->getAll();
        $data['units'] = $this->unitService->getSmallUnit();
        $data['manual_edit'] = $this->manualEdit();

        $this->view("material/price/edit", $data);
    }

    #[Route('PATCH', '/ajax/materials/{idMaterial:\d+}/price')]
    public function ajaxUpdatePrice($idMaterial)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        if (empty($data)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }
        if (!$this->manualEdit()) {
            return $this->json(['message' => 'Ręczna zmiana ceny wyłączona : cena surowca zmienia się przez ofertę (Zakupy › Oferty), fakturę zakupową albo zapytanie ofertowe.'], 403);
        }

        // update() wewnętrznie przelicza też koszty powiązanych receptur
        $result = $this->materialPriceService->update($idMaterial, $data, 'manual');

        return $this->handleAjaxResponse($result, 'Cena zaktualizowana, koszty receptur przeliczone', 'Nie udało się zaktualizować ceny');
    }

    /** Historia cen surowca ze źródłem zmiany (P21). */
    #[Route('GET', '/ajax/materials/{idMaterial:\d+}/price-history')]
    public function ajaxPriceHistory($idMaterial)
    {
        return $this->json(['rows' => $this->materialPriceService->history((int) $idMaterial)]);
    }

    #[Route('POST', '/ajax/materials/{idMaterial:\d+}/recalculate-related-recipe-costs')]
    public function ajaxRecalculateRelatedRecipeCosts($idMaterial)
    {
        // Pozostawiony jako ręczny trigger (np. z panelu administracyjnego)
        $result = $this->recipeCostRecalculationService->recalculateForMaterial((int)$idMaterial);
        return $this->json($result);
    }
}
