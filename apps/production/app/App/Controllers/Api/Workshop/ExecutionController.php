<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Execution\ExecutionService;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Execution\KpiService;
use App\Services\Workshop\Execution\OutputService;
use App\Services\Workshop\Execution\TimesheetService;

/** P7 : tablettes (appairage, identification, pointage, opérations, signatures), pointage au téléphone par QR code, heures, KPIs, cockpit. */
final class ExecutionController
{
    use QueryFilters;

    public function __construct(
        private KioskAuthService $kiosk,
        private ExecutionService $execution,
        private TimesheetService $timesheets,
        private KpiService $kpis,
        private OutputService $outputs,
    ) {
    }

    // Responsable
    public function devices(ApiRequest $request): ApiResult { return ApiResult::list($this->kiosk->devices()); }
    public function createDevice(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->createDevice($request->body(), $request->context), 201); }
    public function updateDevice(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->updateDevice($request->id(), $request->body(), $request->context)); }
    public function credentials(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->setCredentials($request->id(), $request->body(), $request->context)); }

    public function employees(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->timesheets->summary((string) $request->query('month', date('Y-m')), $this->intFilter($request, 'group_id')));
    }

    public function timesheet(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->timesheets->month($request->id(), (string) $request->query('month', date('Y-m'))));
    }

    public function correctClock(ApiRequest $request): ApiResult { return new ApiResult($this->timesheets->correct($request->id(), $request->body(), $request->context)); }
    public function addClock(ApiRequest $request): ApiResult { return new ApiResult($this->timesheets->addManual($request->body(), $request->context), 201); }

    public function kpis(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->kpis->kpis((string) $request->query('from', date('Y-m-d', strtotime('monday this week'))), (string) $request->query('to', date('Y-m-d')),
            $this->intFilter($request, 'group_id'), $request->context));
    }

    public function cockpit(ApiRequest $request): ApiResult { return new ApiResult($this->kpis->cockpit($request->context)); }
    public function revokePhones(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->revokePhones($request->id(), $request->context)); }

    // Tablette
    public function pair(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->pair($request->body())); }
    public function people(ApiRequest $request): ApiResult { return ApiResult::list($this->kiosk->people($request->context)); }
    public function identify(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->identify($request->body(), $request->context)); }
    public function me(ApiRequest $request): ApiResult { return new ApiResult($this->execution->me($request->context)); }
    public function qr(ApiRequest $request): ApiResult { return new ApiResult($this->kiosk->qr($request->context, (string) $request->query('base', ''))); }

    // Téléphone (pointage par QR code)
    public function phonePeople(ApiRequest $request): ApiResult { return ApiResult::list($this->kiosk->phonePeople($request->context)); }
    public function phoneMe(ApiRequest $request): ApiResult { return new ApiResult($this->execution->clockState($request->context)); }

    public function enrollPhone(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->kiosk->enrollPhone($request->body(), $request->context, $request->http->headers->get('User-Agent')), 201);
    }

    public function clock(ApiRequest $request): ApiResult { return new ApiResult($this->execution->clock($request->body(), $request->context)); }
    public function start(ApiRequest $request): ApiResult { return new ApiResult($this->execution->start($request->id(), $request->body(), $request->context)); }
    public function pause(ApiRequest $request): ApiResult { return new ApiResult($this->execution->pause($request->id(), $request->context)); }
    public function finish(ApiRequest $request): ApiResult { return new ApiResult($this->execution->finish($request->id(), $request->body(), $request->context)); }
    public function sign(ApiRequest $request): ApiResult { return new ApiResult($this->execution->sign($request->body(), $request->context), 201); }

    // Tablet grupy : produkcja dnia i partie (identyfikowalność).
    public function production(ApiRequest $request): ApiResult { return new ApiResult($this->outputs->day($request->context, $request->query('date'))); }
    public function recordOutput(ApiRequest $request): ApiResult { return new ApiResult($this->outputs->record($request->body(), $request->context), 201); }
    public function output(ApiRequest $request): ApiResult { return new ApiResult($this->outputs->batch($request->id(), $request->context)); }
    public function updateOutput(ApiRequest $request): ApiResult { return new ApiResult($this->outputs->update($request->id(), $request->body(), $request->context)); }
    public function outputLot(ApiRequest $request): ApiResult { return new ApiResult($this->outputs->setLot($request->id(), $request->id('material'), $request->body(), $request->context)); }
}
