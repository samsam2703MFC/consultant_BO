<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\TeamService;

final class TeamController
{
    use QueryFilters;

    public function __construct(private TeamService $teams)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->teams->list(
            $this->intFilter($request, 'group_id'),
            $this->intFilter($request, 'shift_template_id'),
            $this->boolFilter($request, 'active')
        ));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->teams->get($request->id()));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->teams->create($request->body(), $request->context));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->teams->update($request->id(), $request->body(), $request->context));
    }

    public function members(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->teams->members($request->id()));
    }

    public function replaceMembers(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->teams->replaceMembers($request->id(), $request->body(), $request->context));
    }
}
