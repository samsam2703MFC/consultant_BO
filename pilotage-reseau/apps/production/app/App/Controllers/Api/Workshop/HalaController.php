<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Execution\HalaService;

/**
 * Tablet hali « Mój dzień » [409–411] : chemin du jour du groupe de la tablette (personne identifiée par PIN), Pomoc,
 * Zmiana du brygadzista. Toute la logique : HalaService ; les données de production passent par OutputService.
 */
final class HalaController
{
    public function __construct(private HalaService $hala)
    {
    }

    public function day(ApiRequest $request): ApiResult { return new ApiResult($this->hala->day($request->context)); }
    public function made(ApiRequest $request): ApiResult { return new ApiResult($this->hala->made($request->id(), $request->body(), $request->context)); }
    public function scan(ApiRequest $request): ApiResult { return new ApiResult($this->hala->scanBag($request->body(), $request->context)); }
    public function chillStart(ApiRequest $request): ApiResult { return new ApiResult($this->hala->chillStart($request->body(), $request->context), 201); }
    public function chillMeasure(ApiRequest $request): ApiResult { return new ApiResult($this->hala->chillMeasure($request->id(), $request->body(), $request->context)); }
    public function clean(ApiRequest $request): ApiResult { return new ApiResult($this->hala->clean($request->id(), $request->body(), $request->context)); }
    public function close(ApiRequest $request): ApiResult { return new ApiResult($this->hala->close($request->context)); }
    public function help(ApiRequest $request): ApiResult { return new ApiResult($this->hala->help($request->body(), $request->context), 201); }
    public function helpState(ApiRequest $request): ApiResult { return new ApiResult($this->hala->helpState($request->context)); }
    public function shift(ApiRequest $request): ApiResult { return new ApiResult($this->hala->shift($request->context)); }
    public function helpAction(ApiRequest $request): ApiResult { return new ApiResult($this->hala->helpAction($request->id(), $request->body(), $request->context)); }
    public function validateShift(ApiRequest $request): ApiResult { return new ApiResult($this->hala->validateShift($request->context)); }
    public function photo(ApiRequest $request): ApiResult { return new ApiResult($this->hala->photo($request->id())); }

    /** « Dalej » : {step} parmi les étapes du groupe. */
    public function step(ApiRequest $request): ApiResult
    {
        $step = $request->body()['step'] ?? null;
        if (!is_string($step) || !in_array($step, HalaService::STEPS, true)) {
            throw ApiException::validation([['field' => 'step', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', HalaService::STEPS)]]]);
        }
        return new ApiResult($this->hala->step($step, $request->context));
    }
}
