<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Planning\TeamSheetService;
use App\Services\Workshop\Planning\WeekService;

/** Gantt tygodnia : produkty tygodnia na dni, godziny potrzebne / dostępne, wykonanie. */
final class WeekController
{
    public function __construct(private WeekService $week, private TeamSheetService $teams)
    {
    }

    /** Plan zespołów na tydzień (kalendarz, produkcja, dostawy) ; group_id = jeden zespół. */
    public function teamSheet(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->teams->build((string) $request->query('date', date('Y-m-d')), $this->group($request), $request->context));
    }

    /** Ten sam plan jako PDF do druku (base64) : zespół na stronie A4. */
    public function teamSheetPdf(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->teams->pdf((string) $request->query('date', date('Y-m-d')), $this->group($request), $request->context));
    }

    private function group(ApiRequest $request): ?int
    {
        $raw = $request->query('group_id');
        if ($raw === null || $raw === '') return null;
        if (!ctype_digit((string) $raw) || (int) $raw < 1) throw ApiException::validation([['field' => 'group_id', 'code' => 'NOT_INTEGER', 'params' => []]]);
        return (int) $raw;
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
