<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Product\TimeStudyService;

/** Chronométrages des étapes et application d'une nouvelle norme. */
final class TimeStudyController
{
    public function __construct(private TimeStudyService $studies)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->studies->list($request->id(), $request->context));
    }

    public function record(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->studies->record($request->id(), $request->body(), $request->context));
    }

    public function setExcluded(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->studies->setExcluded($request->id(), $request->body(), $request->context));
    }

    public function applyStandard(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->studies->applyStandard($request->id(), $request->body(), $request->context));
    }
}
