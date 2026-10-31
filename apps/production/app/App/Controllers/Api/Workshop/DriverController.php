<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Logistics\DriverService;

/** Tablet kierowcy : dzień (trasa i postoje), start / koniec trasy, zdjęcia przed / po rozładunku, potwierdzenie albo problem, raport PDF, dokumenty dnia. */
final class DriverController
{
    public function __construct(private DriverService $driver)
    {
    }

    public function day(ApiRequest $request): ApiResult
    {
        $vehicle = $request->query('vehicle_id');
        return new ApiResult($this->driver->day($request->context, $this->date($request, false),
            $vehicle !== null && ctype_digit($vehicle) ? (int) $vehicle : null));
    }

    /** Tournées du jour, avant l'identification du chauffeur : la tablette les propose en premier (tournée → chauffeur → PIN). */
    public function tours(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->tours($request->context, $this->date($request, false)));
    }

    /** Plan załadunku naczepy wysłany z biura (miejsca, palety, przystanki, załadowane). */
    public function loading(ApiRequest $request): ApiResult
    {
        $vehicle = $request->query('vehicle_id');
        return new ApiResult($this->driver->loading($request->context, $this->date($request, false),
            $vehicle !== null && ctype_digit($vehicle) ? (int) $vehicle : null));
    }

    public function loadingCheck(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->loadingCheck($request->context, $request->body()));
    }

    public function run(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->run($request->context, $request->body()));
    }

    public function stop(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->stop($request->context, $request->body()), 201);
    }

    public function photo(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->photo($request->context, $request->body()), 201);
    }

    /** Raport PDF trasy (albo klienta : customer_id) — JSON z plikiem base64, jak dokumenty dnia. */
    public function report(ApiRequest $request): ApiResult
    {
        $vehicle = $request->query('vehicle_id');
        $customer = $request->query('customer_id');
        $file = $this->driver->report($request->context, $this->date($request, true),
            $vehicle !== null && ctype_digit($vehicle) ? (int) $vehicle : null, $customer !== null && ctype_digit($customer) ? (int) $customer : null);
        return new ApiResult(['filename' => $file['filename'], 'content_base64' => base64_encode($file['content'])]);
    }

    public function documents(ApiRequest $request): ApiResult
    {
        $file = $this->driver->documents($request->context, $this->date($request, true));
        return new ApiResult(['filename' => $file['filename'], 'content_base64' => base64_encode($file['content'])]);
    }

    private function date(ApiRequest $request, bool $required): ?string
    {
        $date = $request->query('date');
        if ($date === null || $date === '') {
            if ($required) throw ApiException::validation([['field' => 'date', 'code' => 'REQUIRED', 'params' => []]]);
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw ApiException::validation([['field' => 'date', 'code' => 'INVALID_DATE', 'params' => []]]);
        return $date;
    }
}
