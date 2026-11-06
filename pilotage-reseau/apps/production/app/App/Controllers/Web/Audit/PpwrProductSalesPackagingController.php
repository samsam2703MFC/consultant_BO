<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Material\Ppwr\FinalProductPpwrSalesPackagingService;
use Symfony\Component\HttpFoundation\Request;

class PpwrProductSalesPackagingController extends BaseController
{
    public function __construct(private FinalProductPpwrSalesPackagingService $service)
    {
    }

    public function index(): void
    {
        $this->view('audit/ppwr_product_sales_packaging/index', ['products' => $this->service->getAudit()]);
    }

    public function ajaxCandidates(int $id)
    {
        try { return $this->json(['data' => $this->service->getCandidates($id)]); }
        catch (CustomException $exception) { return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422); }
    }

    public function ajaxSave(int $id)
    {
        try { return $this->json(['data' => $this->service->save($id, $this->getJson(Request::createFromGlobals())), 'message' => 'Konfiguracja kartonu sprzedażowego została zapisana.']); }
        catch (CustomException $exception) { return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422); }
        catch (\Throwable $exception) { error_log((string)$exception); return $this->json(['message' => 'Nie udało się zapisać konfiguracji PPWR produktu.'], 500); }
    }
}
