<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Finance\FinanceWorkspaceService;
use App\Services\Workshop\Finance\ServiceCostService;
use App\Services\Workshop\Supply\InvoiceService;

/**
 * Finanse : miejsce pracy « Faktury i koszty » (przypisania, zatwierdzenie, wzrosty cen) i koszty usług miesiąca
 * (faktury usług po kategoriach, « nie w tym miesiącu », koszt bez faktury KSeF, zamknięcie → kalkulator kosztów).
 */
final class FinanceController
{
    public function __construct(private FinanceWorkspaceService $workspace, private ServiceCostService $services, private InvoiceService $invoices)
    {
    }

    public function workspace(ApiRequest $request): ApiResult { return new ApiResult($this->workspace->overview($request->context)); }
    public function month(ApiRequest $request): ApiResult { return new ApiResult($this->services->month((string) $request->query('month', ServiceCostService::defaultMonth()), $request->context)); }
    public function create(ApiRequest $request): ApiResult { return new ApiResult($this->services->create($request->body(), $request->context), 201); }
    public function update(ApiRequest $request): ApiResult { return new ApiResult($this->services->update($request->id(), $request->body(), $request->context)); }
    public function decide(ApiRequest $request): ApiResult { return new ApiResult($this->services->decide($request->id(), $request->body(), $request->context)); }
    public function delete(ApiRequest $request): ApiResult { return new ApiResult($this->services->delete($request->id(), $request->context)); }
    public function classify(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->classify($request->id(), $request->body(), $request->context)); }
    public function file(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->file($request->id())); }
    public function mark(ApiRequest $request): ApiResult { return new ApiResult($this->services->mark($request->body(), $request->context)); }
    public function acceptAll(ApiRequest $request): ApiResult { return new ApiResult($this->services->acceptAll((string) $request->vars['ym'], $request->context)); }
    public function close(ApiRequest $request): ApiResult { return new ApiResult($this->services->close((string) $request->vars['ym'], $request->context)); }
    public function reopen(ApiRequest $request): ApiResult { return new ApiResult($this->services->reopen((string) $request->vars['ym'], $request->context)); }
}
