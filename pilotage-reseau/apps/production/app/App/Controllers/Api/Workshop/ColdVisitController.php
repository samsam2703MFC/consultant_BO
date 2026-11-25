<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Quality\ColdVisitService;

/**
 * [432] Obchód chłodni sur la tablette qualité (/tablet/quality) : miejsca du obchód, une miejsce (surowce, stan ERP,
 * partia), « Zapisz chłodnię », surowce rattachés (ajout, retrait, recherche). Chaque appel vérifie le poste de la personne
 * identifiée (KIEROWNIK_PRODUKCJI ou JAKOSC, sinon 403 QC_ROUND_POSITION). La photo « Nie » passe par
 * POST /kiosk/quality-round/photos (même tablette).
 */
final class ColdVisitController
{
    public function __construct(private ColdVisitService $cold)
    {
    }

    public function home(ApiRequest $request): ApiResult { return new ApiResult($this->cold->home($request->context)); }
    public function place(ApiRequest $request): ApiResult { return new ApiResult($this->cold->place($request->id(), $request->context)); }
    public function save(ApiRequest $request): ApiResult { return new ApiResult($this->cold->save($request->id(), $request->body(), $request->context), 201); }
    public function assign(ApiRequest $request): ApiResult { return new ApiResult($this->cold->assign($request->id(), $request->id('material'), $request->context)); }
    public function unassign(ApiRequest $request): ApiResult { return new ApiResult($this->cold->unassign($request->id(), $request->id('material'), $request->context)); }
    public function search(ApiRequest $request): ApiResult { return ApiResult::list($this->cold->search((string) ($request->query('q') ?? ''), $request->context)); }
}
