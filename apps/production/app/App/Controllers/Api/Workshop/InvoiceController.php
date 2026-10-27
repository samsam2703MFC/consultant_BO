<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Supply\InvoiceService;
use App\Services\Workshop\Supply\PriceHistoryService;

/**
 * Kontrola faktur zakupowych : import (KSeF, XML, PDF), linie, przypisania nazw dostawcy, porównanie z zamówieniem, decyzja ;
 * « Zatwierdź fakturę » jednym kliknięciem ; historia cen surowców za kg według dostawcy.
 */
final class InvoiceController
{
    public function __construct(private InvoiceService $invoices, private PriceHistoryService $history)
    {
    }

    public function list(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->list((string) $request->query('status', 'open'), $request->context)); }
    public function detail(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->detail($request->id(), $request->context)); }
    public function ksefSearch(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->ksefSearch($request->body(), $request->context)); }
    public function ksefImport(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->ksefImport($request->body(), $request->context), 201); }
    public function upload(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->upload($request->body(), $request->context), 201); }
    public function file(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->file($request->id())); }
    public function update(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->update($request->id(), $request->body(), $request->context)); }
    public function saveLines(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->saveLines($request->id(), $request->body(), $request->context)); }
    public function mapLine(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->mapLine($request->id(), $request->id('lineId'), $request->body(), $request->context)); }
    public function decide(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->decide($request->id(), $request->body(), $request->context)); }
    public function importPrices(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->importPrices($request->id(), $request->body(), $request->context)); }
    public function decidePrices(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->decidePrices($request->id(), $request->body(), $request->context)); }
    public function receive(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->receive($request->id(), $request->context)); }
    public function delete(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->delete($request->id(), $request->context)); }
    public function mappings(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->mappings($request->context)); }
    public function deleteMapping(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->deleteMapping($request->id(), $request->context)); }
    public function mapLines(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->mapLines($request->id(), $request->body(), $request->context)); }
    public function process(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->process($request->id(), $request->body(), $request->context)); }
    public function processReady(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->processReady($request->body(), $request->context)); }
    public function ksefSync(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->ksefSync($request->context)); }
    public function prices(ApiRequest $request): ApiResult { return new ApiResult($this->history->overview((int) $request->query('months', '12'), $request->context->translator->lang())); }
    public function changeMaterialUnit(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->changeMaterialUnit($request->id(), $request->body(), $request->context)); }
    public function materialPrices(ApiRequest $request): ApiResult { return new ApiResult($this->history->material($request->id(), (int) $request->query('months', '24'), $request->context->translator->lang())); }
}
