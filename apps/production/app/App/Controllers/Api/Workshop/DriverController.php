<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Logistics\DriverService;

/** Tablet kierowcy : dzień (trasa i postoje), start / koniec trasy, potwierdzenie albo problem, dokumenty dnia. */
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

    public function run(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->run($request->context, $request->body()));
    }

    public function stop(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->driver->stop($request->context, $request->body()), 201);
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
