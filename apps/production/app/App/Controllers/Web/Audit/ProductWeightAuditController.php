<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Audit\ProductWeightAuditService;
use Symfony\Component\HttpFoundation\Request;

class ProductWeightAuditController extends BaseController
{
    public function __construct(private ProductWeightAuditService $service) {}

    public function index()
    {
        $this->view('audit/product_weight/index');
    }

    public function ajaxList()
    {
        return $this->json($this->service->list($_GET));
    }

    public function ajaxUpdateWeight($id)
    {
        try {
            $payload = $this->getJson(Request::createFromGlobals());
            if (!array_key_exists('weight_g', $payload)) {
                return $this->json(['message' => 'Podaj masę netto w gramach.'], 422);
            }
            return $this->json([
                'data' => $this->service->updateWeight((int) $id, $payload['weight_g']),
                'message' => 'Masa produktu została zaktualizowana.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return $this->json(['message' => 'Nie udało się zaktualizować masy produktu.'], 500);
        }
    }
}
