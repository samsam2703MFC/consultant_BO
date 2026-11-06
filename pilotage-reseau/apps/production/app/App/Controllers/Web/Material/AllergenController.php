<?php

namespace App\Controllers\Web\Material;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Material\AllergenService;
use Symfony\Component\HttpFoundation\Request;

class AllergenController extends BaseController
{
    public function __construct(
        private AllergenService $allergenService
    )
    {}

    #[Route('GET', '/ajax/allergens')]
    public function ajaxListAllergens()
    {
        $rows = $this->allergenService->getAll();
        return $this->json(['rows' => $rows], 200);
    }

    #[Route('GET', '/ajax/materials/{idMaterial:\\d+}/allergens')]
    public function ajaxGetMaterialAllergens($idMaterial)
    {
        $rows = $this->allergenService->getForMaterial((int)$idMaterial);
        return $this->json(['rows' => $rows], 200);
    }

    #[Route('PATCH', '/ajax/materials/{idMaterial:\\d+}/allergens')]
    public function ajaxUpdateMaterialAllergens($idMaterial)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        $allergenIds = $data['allergenIds'] ?? $data['allergens'] ?? [];
        if (!is_array($allergenIds)) {
            return $this->json(['message' => 'Niepoprawny format danych wejściowych'], 400);
        }

        $result = $this->handleAction(function () use ($idMaterial, $allergenIds) {
            return $this->allergenService->updateForMaterial((int)$idMaterial, $allergenIds);
        }, default: false);

        return $this->handleAjaxResponse($result, 'Alergeny zaktualizowane', 'Nie udało się zaktualizować alergenów');
    }
}

