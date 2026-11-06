<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\GroupService;

final class GroupController
{
    use QueryFilters;

    public function __construct(private GroupService $groups)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->groups->list($this->boolFilter($request, 'active'), $request->context));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->groups->get($request->id(), $request->context));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->groups->create($request->body(), $request->context));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->groups->update($request->id(), $request->body(), $request->context));
    }
}
