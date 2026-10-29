<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Supply\BuyService;

/** Zakupy tygodnia : co kupić (z zapasem), zapytania do 1–3 dostawców na pozycję, oferty z minimum, zamówienie, wyniki operatora. */
final class BuyController
{
    public function __construct(private BuyService $buy)
    {
    }

    public function week(ApiRequest $request): ApiResult
    {
        $date = $request->query('date');
        $safety = $request->query('safety');
        return new ApiResult($this->buy->week($date !== null && $date !== '' ? (string) $date : null, is_numeric($safety) ? (int) $safety : null, $request->context));
    }

    public function create(ApiRequest $request): ApiResult { return new ApiResult($this->buy->create($request->body(), $request->context), 201); }
    public function detail(ApiRequest $request): ApiResult { return new ApiResult($this->buy->detail($request->id(), $request->context)); }
    public function update(ApiRequest $request): ApiResult { return new ApiResult($this->buy->update($request->id(), $request->body(), $request->context)); }
    public function delete(ApiRequest $request): ApiResult { return new ApiResult($this->buy->delete($request->id(), $request->context)); }
    public function preview(ApiRequest $request): ApiResult { return new ApiResult($this->buy->preview($request->id(), (int) $request->query('supplier', '0'), $request->context)); }
    public function send(ApiRequest $request): ApiResult { return new ApiResult($this->buy->send($request->id(), $request->context)); }
    public function remind(ApiRequest $request): ApiResult { return new ApiResult($this->buy->remind($request->id(), $request->context)); }
    public function addOffer(ApiRequest $request): ApiResult { return new ApiResult($this->buy->addOffer($request->id(), $request->body(), $request->context), 201); }
    public function order(ApiRequest $request): ApiResult { return new ApiResult($this->buy->order($request->id(), $request->body(), $request->context), 201); }
    public function close(ApiRequest $request): ApiResult { return new ApiResult($this->buy->close($request->id(), $request->context)); }
    public function results(ApiRequest $request): ApiResult { return new ApiResult($this->buy->results((string) $request->query('period', 'month'), $request->context)); }
    public function suppliers(ApiRequest $request): ApiResult { return new ApiResult($this->buy->materialSuppliers($request->query('missing') === '1', $request->context)); }
    public function saveSuppliers(ApiRequest $request): ApiResult { return new ApiResult($this->buy->saveMaterialSuppliers($request->id(), $request->body(), $request->context)); }
}
