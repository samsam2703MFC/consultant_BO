<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\WorkstationService;

final class WorkstationController
{
    use QueryFilters;

    public function __construct(private WorkstationService $workstations)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->workstations->list(
            $this->intFilter($request, 'group_id'),
            $this->choiceFilter($request, 'type', WorkstationService::TYPES),
            $this->boolFilter($request, 'active'),
            $request->context
        ));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->workstations->get($request->id(), $request->context));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->workstations->create($request->body(), $request->context));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->workstations->update($request->id(), $request->body(), $request->context));
    }
}
