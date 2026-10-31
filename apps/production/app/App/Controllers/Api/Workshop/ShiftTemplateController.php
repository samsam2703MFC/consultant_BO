<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\ShiftTemplateService;

final class ShiftTemplateController
{
    use QueryFilters;

    public function __construct(private ShiftTemplateService $shifts)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->shifts->list($this->boolFilter($request, 'active'), $request->context));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->shifts->get($request->id(), $request->context));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->shifts->create($request->body(), $request->context));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->shifts->update($request->id(), $request->body(), $request->context));
    }
}
