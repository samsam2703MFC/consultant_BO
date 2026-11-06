<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Supply\SupplyService;

/** Zaopatrzenie : potrzeby surowców i opakowań z produkcji, tryb i stany surowców, zamówienia do dostawców, przyjęcia. */
final class SupplyController
{
    public function __construct(private SupplyService $supply)
    {
    }

    public function needs(ApiRequest $request): ApiResult
    {
        $date = (string) $request->query('date', date('Y-m-d', strtotime('+7 days')));
        return new ApiResult($this->supply->needs($date, (int) $request->query('weeks', '1'), $request->context));
    }

    public function materials(ApiRequest $request): ApiResult { return new ApiResult($this->supply->materials($request->context)); }
    public function saveMaterial(ApiRequest $request): ApiResult { return new ApiResult($this->supply->saveMaterial($request->id(), $request->body(), $request->context)); }
    public function addCount(ApiRequest $request): ApiResult { return new ApiResult($this->supply->addCount($request->id(), $request->body(), $request->context), 201); }
    public function addCounts(ApiRequest $request): ApiResult { return new ApiResult($this->supply->addCounts($request->body(), $request->context), 201); }
    public function suggestLimits(ApiRequest $request): ApiResult { return new ApiResult($this->supply->suggestLimits($request->body(), $request->context)); }
    public function orders(ApiRequest $request): ApiResult { return new ApiResult($this->supply->orders((string) $request->query('status', 'open'), $request->context)); }
    public function createOrders(ApiRequest $request): ApiResult { return new ApiResult($this->supply->createOrders($request->body(), $request->context), 201); }
    public function order(ApiRequest $request): ApiResult { return new ApiResult($this->supply->order($request->id(), $request->context)); }
    public function updateOrder(ApiRequest $request): ApiResult { return new ApiResult($this->supply->updateOrder($request->id(), $request->body(), $request->context)); }
    public function saveLines(ApiRequest $request): ApiResult { return new ApiResult($this->supply->saveLines($request->id(), $request->body(), $request->context)); }
    public function preview(ApiRequest $request): ApiResult { return new ApiResult($this->supply->preview($request->id(), $request->context)); }
    public function send(ApiRequest $request): ApiResult { return new ApiResult($this->supply->send($request->id(), $request->body(), $request->context)); }
    public function receive(ApiRequest $request): ApiResult { return new ApiResult($this->supply->receive($request->id(), $request->body(), $request->context)); }
    public function cancel(ApiRequest $request): ApiResult { return new ApiResult($this->supply->cancel($request->id(), $request->context)); }
    public function delete(ApiRequest $request): ApiResult { return new ApiResult($this->supply->delete($request->id(), $request->context)); }
}
