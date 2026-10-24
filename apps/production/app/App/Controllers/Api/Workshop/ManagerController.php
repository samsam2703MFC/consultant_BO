<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Manager\ManagerService;

/** Kierownik produkcji : plan i cele, raport miesięczny, HACCP, nowe produkty, działania, premia, rozmowa, audyt kompetencji. */
final class ManagerController
{
    public function __construct(private ManagerService $manager)
    {
    }

    public function plan(ApiRequest $request): ApiResult { return new ApiResult($this->manager->plan((int) $request->query('year', date('Y')), $request->context)); }
    public function savePlan(ApiRequest $request): ApiResult { return new ApiResult($this->manager->savePlan($request->body(), $request->context)); }
    public function saveObjectives(ApiRequest $request): ApiResult { return new ApiResult($this->manager->saveObjectives($request->body(), $request->context)); }
    public function validateObjectives(ApiRequest $request): ApiResult { return new ApiResult($this->manager->validateObjectives($request->body(), $request->context)); }
    public function report(ApiRequest $request): ApiResult { return new ApiResult($this->manager->report((string) $request->query('month', date('Y-m', strtotime('first day of last month'))), $request->context)); }
    public function saveValue(ApiRequest $request): ApiResult { return new ApiResult($this->manager->saveValue($request->body(), $request->context)); }
    public function addHaccp(ApiRequest $request): ApiResult { return new ApiResult($this->manager->addHaccp($request->body(), $request->context), 201); }
    public function updateHaccp(ApiRequest $request): ApiResult { return new ApiResult($this->manager->updateHaccp($request->id(), $request->body(), $request->context)); }
    public function deleteHaccp(ApiRequest $request): ApiResult { return new ApiResult($this->manager->deleteHaccp($request->id(), $request->context)); }
    public function saveLaunch(ApiRequest $request): ApiResult { return new ApiResult($this->manager->saveLaunch($request->id(), $request->body(), $request->context)); }
    public function saveActions(ApiRequest $request): ApiResult { return new ApiResult($this->manager->saveActions($request->body(), $request->context)); }
    public function bonus(ApiRequest $request): ApiResult { return new ApiResult($this->manager->bonus((string) $request->query('period', ''), $request->context)); }
    public function interview(ApiRequest $request): ApiResult { return new ApiResult($this->manager->interview((string) $request->query('month', date('Y-m', strtotime('first day of last month'))), $request->context)); }
    public function audits(ApiRequest $request): ApiResult { return new ApiResult($this->manager->audits($request->context)); }
    public function addAudit(ApiRequest $request): ApiResult { return new ApiResult($this->manager->addAudit($request->body(), $request->context), 201); }
}
