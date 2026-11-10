<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\EmployeeService;

final class EmployeeController
{
    use QueryFilters;

    public function __construct(private EmployeeService $employees)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->employees->list($this->intFilter($request, 'group_id'), $this->boolFilter($request, 'active')));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->employees->get($request->id()));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->employees->update($request->id(), $request->body(), $request->context));
    }
}
