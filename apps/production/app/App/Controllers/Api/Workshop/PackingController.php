<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Logistics\LogisticsService;

/**
 * Tablet pakowania (28/10/2026) : dzień wysyłki — produkty do spakowania (kartony, sztuki, czas), osoby, koniec przed
 * wyjazdem ; licznik spakowanych kartonów na produkt (informacyjny). Każdy sparowany tablet, bez PIN.
 */
final class PackingController
{
    public function __construct(private LogisticsBoardService $board, private LogisticsService $logistics, private LogisticsBoardRepository $repo)
    {
    }

    public function show(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->board->packing($this->date($request->query('date'))));
    }

    public function set(ApiRequest $request): ApiResult
    {
        $id = $request->id();
        $v = (new Validator($request->body()))->only(['date', 'packed'])->date('date', true, false)->integer('packed', true, 0, 9999);
        $v->throwIfInvalid();
        if (!$this->repo->productExists($id)) throw ApiException::notFound('product', $id);
        return new ApiResult($this->board->setPacked((string) $v->value('date'), $id, (int) $v->value('packed'), null, $request->context->deviceId));
    }

    private function date(?string $date): string
    {
        if ($date === null || $date === '') return $this->logistics->defaultDate();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw ApiException::validation([['field' => 'date', 'code' => 'INVALID_DATE', 'params' => []]]);
        }
        return $date;
    }
}
