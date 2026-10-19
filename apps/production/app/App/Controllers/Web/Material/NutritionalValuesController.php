<?php

namespace App\Controllers\Web\Material;

use App\Core\Web\BaseController;
use App\Services\Material\MaterialService;
use Symfony\Component\HttpFoundation\Request;

class NutritionalValuesController extends BaseController
{
    public function __construct(
        private MaterialService $materialService
    ) {}

    public function overview()
    {
        $data['materials'] = $this->materialService->getAllWithNutritionalValues();
        $this->view('material/nutritional-values/overview', $data);
    }

    public function ajaxUpdate($id)
    {
        $request = Request::createFromGlobals();
        $payload = $this->getJson($request);

        $field = $payload['field'] ?? null;
        $value = $payload['value'] ?? null;

        if (!$field || !is_numeric($value)) {
            return $this->json(['ok' => false, 'message' => 'Niepoprawne dane wejściowe'], 400);
        }

        $result = $this->handleAction(function () use ($id, $field, $value) {
            return $this->materialService->updateNutritionalValues((int)$id, (string)$field, (float)$value);
        }, default: false);

        return $this->handleAjaxResponse(
            $result,
            'Zaktualizowano wartość odżywczą',
            'Nie udało się zaktualizować wartości odżywczej'
        );
    }
}
