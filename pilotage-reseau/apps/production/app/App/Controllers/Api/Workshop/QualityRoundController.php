<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Quality\LotTraceService;
use App\Services\Workshop\Quality\QualityRoundService;

/**
 * [415] Tablet jakości Kierownika produkcji (/tablet/quality) : personnes aux postes Kierownik produkcji / Jakość (tablette
 * appairée, sans PIN), puis, après le PIN (POST /kiosk/identify), le tour du jour, une partie (poids, aspect, sacs,
 * échantillon, enregistrement), l'envoi d'un manque de scan au groupe, le suivi d'une partie (étiquette de carton ou numéro
 * de partie), son PDF et son blocage. Chaque appel avec une personne vérifie son poste (403 QC_ROUND_POSITION).
 */
final class QualityRoundController
{
    public function __construct(private QualityRoundService $round, private LotTraceService $trace)
    {
    }

    public function people(ApiRequest $request): ApiResult { return ApiResult::list($this->round->people()); }
    public function show(ApiRequest $request): ApiResult { return new ApiResult($this->round->round($request->context)); }
    public function batch(ApiRequest $request): ApiResult { return new ApiResult($this->round->batch($request->id(), $request->context)); }
    public function control(ApiRequest $request): ApiResult { return new ApiResult($this->round->saveControl($request->id(), $request->body(), $request->context), 201); }
    public function lot(ApiRequest $request): ApiResult { return new ApiResult($this->round->scanLot($request->id(), $request->id('material'), $request->body(), $request->context)); }
    public function requestLots(ApiRequest $request): ApiResult { return new ApiResult($this->round->requestLots($request->id(), $request->context), 201); }
    public function sample(ApiRequest $request): ApiResult { return new ApiResult($this->round->sample($request->id(), $request->body(), $request->context)); }
    public function photo(ApiRequest $request): ApiResult { return new ApiResult($this->round->photo($request->body(), $request->context), 201); }

    public function scan(ApiRequest $request): ApiResult
    {
        $code = (string) ($request->query('code') ?? '');
        if (trim($code) === '') throw ApiException::validation([['field' => 'code', 'code' => 'REQUIRED', 'params' => []]]);
        return new ApiResult($this->trace->scan($code, $request->context));
    }

    public function trace(ApiRequest $request): ApiResult { return new ApiResult($this->trace->lot($request->id(), $request->context)); }
    public function hold(ApiRequest $request): ApiResult { return new ApiResult($this->trace->hold($request->id(), $request->body(), $request->context), 201); }
    public function release(ApiRequest $request): ApiResult { return new ApiResult($this->trace->release($request->id(), $request->body(), $request->context)); }

    /** PDF de la partie : JSON avec le fichier en base64 (comme les documents de la tablette chauffeur). */
    public function pdf(ApiRequest $request): ApiResult
    {
        $file = $this->trace->pdf($request->id(), $request->context);
        return new ApiResult(['filename' => $file['filename'], 'content_base64' => base64_encode($file['content'])]);
    }
}
