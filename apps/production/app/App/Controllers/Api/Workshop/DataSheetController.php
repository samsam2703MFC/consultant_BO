<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Services\Workshop\Referentiel\DataSheetService;

/** Tableau à remplir (Excel) : téléchargement pré-rempli, aperçu de l'import, application. */
final class DataSheetController
{
    public function __construct(private DataSheetService $sheet)
    {
    }

    public function export(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->sheet->export($request->context));
    }

    public function preview(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->sheet->preview($request->body(), $request->context));
    }

    public function apply(ApiRequest $request): ApiResult
    {
        return new ApiResult($this->sheet->apply($request->body(), $request->context));
    }
}
