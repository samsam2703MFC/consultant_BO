<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Services\Shipping\ShipmentPackagingService;
use App\Exceptions\CustomException;
use Symfony\Component\HttpFoundation\Request;

class PackagingDataAuditController extends BaseController
{
    public function __construct(private ShipmentPackagingService $shipmentPackagingService)
    {
    }

    public function index(): void
    {
        $this->view('audit/packaging_data/index', [
            'issues' => $this->shipmentPackagingService->getDataIssues(),
        ]);
    }

    public function ajaxUpdateMaterial(int $id)
    {
        try {
            $payload = $this->getJson(Request::createFromGlobals());
            return $this->json([
                'data' => $this->shipmentPackagingService->updatePackagingMaterial($id, $payload),
                'message' => 'Dane opakowania zostały zaktualizowane.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się zapisać danych opakowania.'], 500);
        }
    }
}
