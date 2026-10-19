<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Scheduling\GanttService;
use App\Services\Workshop\Scheduling\SchedulingService;

/** Ordonnancement d'un plan, opérations planifiées (Gantt, déplacement P5) et charge des équipes (P4). */
final class SchedulingController
{
    use QueryFilters;

    public function __construct(private SchedulingService $scheduling, private GanttService $gantt)
    {
    }

    public function updateOperation(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->gantt->update($request->id(), $request->body(), $request->context));
    }

    public function schedule(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->scheduling->schedulePlan($request->id(), $request->context));
    }

    public function operations(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->scheduling->operations((string) $request->query('from', ''), (string) $request->query('to', ''), $this->intFilter($request, 'group_id'), $request->context));
    }

    public function load(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->scheduling->load((string) $request->query('from', ''), (string) $request->query('to', ''), $request->context));
    }
}
