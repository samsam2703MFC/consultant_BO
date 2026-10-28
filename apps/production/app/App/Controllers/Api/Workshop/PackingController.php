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
 * Tablet pakowania (28/10/2026), dwa tryby : pakowanie — produkty do spakowania (kartony, sztuki, czas), osoby, koniec
 * przed wyjazdem, licznik spakowanych kartonów (informacyjny) ; paletyzacja — klienci (pojazd albo wszyscy), zamówione /
 * na palecie / zostało, kartony położone na paletę (operator, klawiatura), cofnięcie wpisu. Każdy sparowany tablet, bez PIN.
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

    public function pallets(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->board->pallets($this->date($request->query('date')), $this->int($request->query('vehicle_id')), $this->int($request->query('customer_id'))));
    }

    public function addPallet(ApiRequest $request): ApiResult
    {
        $v = (new Validator($request->body()))->only(['date', 'customer_id', 'product_id', 'cartons', 'employee_id', 'vehicle_id'])
            ->date('date', true, false)->integer('customer_id', true, 1)->integer('product_id', true, 1)->integer('cartons', true, 1, 999)
            ->integer('employee_id', false, 1, null, true)->integer('vehicle_id', false, 1, null, true);
        $v->throwIfInvalid();
        $date = (string) $v->value('date');
        $customer = (int) $v->value('customer_id');
        $product = (int) $v->value('product_id');
        $employee = $v->value('employee_id') !== null ? (int) $v->value('employee_id') : null;
        if (!$this->board->ordered($date, $customer, $product)) {
            throw ApiException::validation([['field' => 'product_id', 'code' => 'PALLET_PRODUCT_NOT_ORDERED', 'params' => []]]);
        }
        if ($employee !== null && !$this->board->employeeActive($employee)) {
            throw ApiException::validation([['field' => 'employee_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => $employee]]]);
        }
        $this->board->addPalletEntry($date, $customer, $product, (int) $v->value('cartons'), $employee, $request->context->deviceId, null);
        $vehicle = $v->value('vehicle_id');
        return new ApiResult($this->board->pallets($date, $vehicle !== null ? (int) $vehicle : null, $customer), 201);
    }

    /** Cofnięcie wpisu z tabletu ; ?vehicle_id= : widok do odświeżenia. */
    public function deletePallet(ApiRequest $request): ApiResult
    {
        $entry = $this->board->deletePalletEntry($request->id()) ?? throw ApiException::notFound('pallet_entry', $request->id());
        return new ApiResult($this->board->pallets($entry['date'], $this->int($request->query('vehicle_id')), $entry['customer_id']));
    }

    private function int(?string $v): ?int
    {
        return $v !== null && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
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
