<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Scheduling\GanttService;
use App\Services\Workshop\Scheduling\OptimizerService;
use App\Services\Workshop\Scheduling\SchedulingService;

/** Ordonnancement d'un plan, opérations (Gantt, déplacement P5), charge des équipes (P4), optimiseur de shifts et simulation (P6). */
final class SchedulingController
{
    use QueryFilters;

    public function __construct(private SchedulingService $scheduling, private GanttService $gantt, private OptimizerService $optimizer)
    {
    }

    public function optimizer(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->optimizer->propose((string) $request->query('from', ''), (string) $request->query('to', ''), $request->context));
    }

    public function applyOptimizer(ApiRequest $request): ApiResult
    {
        $body = $request->body();
        return new ApiResult($this->optimizer->apply((string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''), $request->context));
    }

    public function simulate(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->optimizer->simulate($request->body(), $request->context));
    }

    public function saveStaffing(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->optimizer->saveStaffing($request->body(), $request->context));
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
