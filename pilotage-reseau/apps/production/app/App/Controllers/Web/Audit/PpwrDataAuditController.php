<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Material\Ppwr\RawMaterialPpwrService;
use App\Services\Material\Ppwr\FinalProductPpwrSalesPackagingService;
use Symfony\Component\HttpFoundation\Request;

class PpwrDataAuditController extends BaseController
{
    public function __construct(
        private RawMaterialPpwrService $ppwrService,
        private FinalProductPpwrSalesPackagingService $productPackagingService
    )
    {
    }

    public function index(): void
    {
        $this->view('audit/ppwr_data/index', [
            'materials' => $this->ppwrService->getAudit(),
            'products' => $this->productPackagingService->getAudit(),
        ]);
    }

    public function ajaxSaveProfile(int $id)
    {
        try {
            return $this->json([
                'data' => $this->ppwrService->saveProfile($id, $this->getJson(Request::createFromGlobals())),
                'message' => 'Profil PPWR został zapisany.',
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string)$exception);
            return $this->json(['message' => 'Nie udało się zapisać profilu PPWR.'], 500);
        }
    }
}
