<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Supply\InvoiceService;

/** Kontrola faktur zakupowych : import (KSeF, XML, PDF), linie, przypisania nazw dostawcy, porównanie z zamówieniem, decyzja. */
final class InvoiceController
{
    public function __construct(private InvoiceService $invoices)
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
    public function receive(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->receive($request->id(), $request->context)); }
    public function delete(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->delete($request->id(), $request->context)); }
    public function mappings(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->mappings($request->context)); }
    public function deleteMapping(ApiRequest $request): ApiResult { return new ApiResult($this->invoices->deleteMapping($request->id(), $request->context)); }
}
