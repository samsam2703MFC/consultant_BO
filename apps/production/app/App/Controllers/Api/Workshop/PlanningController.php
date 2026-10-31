<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Planning\PlanningService;

/** Besoins MTO / MTS / semi-finis et plans de production (P3). */
final class PlanningController
{
    use QueryFilters;

    public function __construct(private PlanningService $planning)
    {
    }

    public function demand(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->planning->demand((string) $request->query('from', ''), (string) $request->query('to', ''), $request->context));
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->planning->list($this->choiceFilter($request, 'status', PlanningService::STATUSES)));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->planning->createPlan($request->body(), $request->context));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->planning->get($request->id(), $request->context));
    }

    public function adjustLine(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->planning->adjustLine($request->id(), $request->id('lineId'), $request->body(), $request->context));
    }

    public function validate(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->planning->validate($request->id(), $request->context));
    }
}
