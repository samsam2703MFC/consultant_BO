<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\ApiResult;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Purchasing\ReceivingWizardService;

/**
 * Tablet pakowania, trzeci tryb « Przyjęcia » [230] : kontrola przyjęć dnia — dostawy dnia z zamówień dostawców,
 * pozycja po pozycji OK / niezgodność (ilość, partia, termin, temperatura, zdjęcia z aparatu tabletu, decyzja).
 * Każdy sparowany tablet, bez PIN ; operator = osoba obecna wybrana na tablecie (jak w paletyzacji).
 */
final class ReceivingController
{
    public function __construct(private ReceivingWizardService $receiving, private LogisticsBoardRepository $board, private LogisticsBoardService $boardService)
    {
    }

    public function day(ApiRequest $request): ApiResult
    {
        $day = ReceivingWizardService::day($request->query('date'));
        return new ApiResult($this->receiving->overview($day) + ['now' => date('Y-m-d H:i:s'), 'people' => $this->board->presentPeople()]);
    }

    public function order(ApiRequest $request): ApiResult
    {
        $day = ReceivingWizardService::day($request->query('date'));
        return new ApiResult($this->wrap(fn() => $this->receiving->order($request->id(), $day)));
    }

    public function check(ApiRequest $request): ApiResult
    {
        $body = $request->body();
        $day = ReceivingWizardService::day(is_string($body['date'] ?? null) ? $body['date'] : null);
        $employee = $body['employee_id'] ?? null;
        if ($employee !== null && (!is_int($employee) || !$this->boardService->employeeActive($employee))) {
            throw ApiException::validation([['field' => 'employee_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => is_scalar($employee) ? (string) $employee : '?']]]);
        }
        $result = $this->wrap(fn() => $this->receiving->save($request->id(), ['day' => $day] + $body, null, $employee));
        return new ApiResult(['result' => $result] + $this->receiving->order($request->id(), $day), 201);
    }

    /** Błędy kreatora (CustomException) → 404 / 422 z komunikatem. */
    private function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (CustomException $e) {
            if ((int) $e->getCode() === 404) throw new ApiException('NOT_FOUND', 404, [], ['entity' => 'supplier_order', 'id' => '']);
            throw new ApiException('RECEIVING_INVALID', 422, [], ['message' => $e->getMessage()]);
        }
    }
}
