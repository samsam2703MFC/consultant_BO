<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Supply\DeliveryScheduleService;

/** Dostawy surowców (Produkcja) : kto dostarcza co i kiedy — tydzień produkcji i dostawcy. */
final class DeliveryController
{
    public function __construct(private DeliveryScheduleService $deliveries)
    {
    }

    public function week(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->deliveries->week($request->query('date'), $request->context));
    }
}
