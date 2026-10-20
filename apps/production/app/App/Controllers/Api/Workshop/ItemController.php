<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Product\ItemService;

/** Fiches de production des articles (assistant P2). */
final class ItemController
{
    use QueryFilters;

    public function __construct(private ItemService $items)
    {
    }

    public function list(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->items->list(
            $this->choiceFilter($request, 'item_type', ItemService::TYPES),
            $this->intFilter($request, 'group_id'),
            $this->choiceFilter($request, 'status', ItemService::STATUSES),
            $this->choiceFilter($request, 'mode', ItemService::MODES),
            $this->searchFilter($request),
            $request->context
        ));
    }

    public function candidates(ApiRequest $request): ApiResult
    {
        return ApiResult::list($this->items->candidates($this->choiceFilter($request, 'item_type', ItemService::TYPES) ?? 'product', $this->searchFilter($request)));
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->get($request->id(), $request->context));
    }

    public function create(ApiRequest $request): ApiResult
    {
        return ApiResult::created($this->items->create($request->body(), $request->context));
    }

    public function updateSettings(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->updateSettings($request->id(), $request->body(), $request->context));
    }

    public function updateLogistics(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->updateLogistics($request->id(), $request->body(), $request->context));
    }

    public function replaceRouting(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->replaceRouting($request->id(), $request->body(), $request->context));
    }

    public function saveBomLosses(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->saveBomLosses($request->id(), $request->body(), $request->context));
    }

    public function validate(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->items->validate($request->id(), $request->context));
    }

    public function duration(ApiRequest $request): ApiResult
    {
        $quantity = $request->query('quantity');
        if ($quantity === null || !is_numeric($quantity)) {
            throw ApiException::validation([['field' => 'quantity', 'code' => 'NOT_NUMBER', 'params' => []]]);
        }
        return new ApiResult($this->items->duration($request->id(), (float) $quantity));
    }

    private function searchFilter(ApiRequest $request): ?string
    {
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) > 100) throw ApiException::validation([['field' => 'search', 'code' => 'TOO_LONG', 'params' => ['max' => 100]]]);
        return $search === '' ? null : $search;
    }
}
