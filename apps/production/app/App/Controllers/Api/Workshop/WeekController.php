<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Planning\WeekService;

/** Gantt tygodnia : produkty tygodnia na dni, godziny potrzebne / dostępne, wykonanie. */
final class WeekController
{
    public function __construct(private WeekService $week)
    {
    }

    public function get(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->week((string) $request->query('date', date('Y-m-d')), $request->context));
    }

    /** Arkusz tygodnia : jeden wiersz na produkt (stan, min., maks., zamówienia, uzupełnienie, partie, godziny). */
    public function sheet(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->sheet((string) $request->query('date', date('Y-m-d')), $request->context));
    }

    public function move(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->move($request->body(), $request->context));
    }

    public function distribute(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->distribute($request->body(), $request->context));
    }

    /** Godzina startu bloku w dniu (i stałej partii 📌 tego dnia tygodnia). */
    public function time(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->time($request->body(), $request->context));
    }

    /** « Wyczyść tydzień » : wszystko z dni od dzisiaj z powrotem do « Do zrobienia ». */
    public function clear(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->clear($request->body(), $request->context));
    }

    /** 📌 « Co tydzień » : stała partia produktu w tym dniu tygodnia (albo odpięcie). */
    public function pin(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->week->pin($request->body(), $request->context));
    }
}
