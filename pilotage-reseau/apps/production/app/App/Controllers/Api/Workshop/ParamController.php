<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\ParamService;

final class ParamController
{
    public function __construct(private ParamService $params)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->params->list($request->context));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->params->get($request->vars['code'], $request->context));
    }

    public function update(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->params->update($request->vars['code'], $request->body(), $request->context));
    }
}
