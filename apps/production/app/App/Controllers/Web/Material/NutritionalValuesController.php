<?php

namespace App\Controllers\Web\Material;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Material\MaterialService;
use App\Services\Material\Nutrition\NutritionLookupService;
use Symfony\Component\HttpFoundation\Request;

class NutritionalValuesController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private MaterialService $materialService,
        private NutritionLookupService $nutrition,
    ) {}

    public function overview()
    {
        $data['materials'] = $this->materialService->getAllWithNutritionalValues();
        $data['sources'] = $this->nutrition->sources();
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
            $ok = $this->materialService->updateNutritionalValues((int)$id, (string)$field, (float)$value);
            if ($ok) $this->nutrition->manualEdit((int)$id);
            return $ok;
        }, default: false);

        return $this->handleAjaxResponse(
            $result,
            'Zaktualizowano wartość odżywczą',
            'Nie udało się zaktualizować wartości odżywczej'
        );
    }

    /** Propozycja z internetu (Open Food Facts) : kategorie pasujące do nazwy, wartości na 100 g. */
    public function lookup($id)
    {
        try {
            return $this->json(['data' => $this->nutrition->lookup((int) $id, $_GET['q'] ?? null, $_GET['tag'] ?? null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Zapis zatwierdzonych wartości z internetu i ich źródła. */
    public function apply($id)
    {
        try {
            return $this->json(['message' => 'Zapisano wartości z Open Food Facts.', 'data' => $this->nutrition->apply((int) $id, $this->input(), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals()) ?: [];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
