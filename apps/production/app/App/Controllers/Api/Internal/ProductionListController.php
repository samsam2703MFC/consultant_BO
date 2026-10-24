<?php
declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Services\Client\Order\OrderService;
use App\Services\Production\ProductionListService;
use App\Services\Production\RequirementListService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal counterpart of the production-planning page.  It intentionally
 * delegates all calculations and PDF rendering to the existing web services.
 */
final class ProductionListController
{
    public function __construct(
        private ProductionListService $productionLists,
        private RequirementListService $requirementLists,
        private OrderService $orders,
    ) {
    }

    public function preview(): Response
    {
        $data = $this->standardRequest();
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            return new JsonResponse(['status' => 'ok', 'data' => $this->productionLists->getProductionListDocumentData($data)]);
        } catch (\Throwable $exception) {
            return $this->failure('production list preview', 'PRODUCTION_LIST_PREVIEW_FAILED', $exception);
        }
    }

    public function pdf(): Response
    {
        $data = $this->standardRequest();
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            return $this->pdfResponse($this->productionLists->getProductionListPDF($data));
        } catch (\Throwable $exception) {
            return $this->failure('production list PDF', 'PRODUCTION_LIST_GENERATION_FAILED', $exception);
        }
    }

    public function complaintPreview(): Response
    {
        try {
            return new JsonResponse(['status' => 'ok', 'data' => $this->productionLists->getComplaintProductionListDocumentData()]);
        } catch (\Throwable $exception) {
            return $this->failure('complaint production list preview', 'COMPLAINT_PRODUCTION_LIST_PREVIEW_FAILED', $exception);
        }
    }

    public function complaintPdf(): Response
    {
        try {
            return $this->pdfResponse($this->productionLists->getComplaintProductionListPDF());
        } catch (\Throwable $exception) {
            return $this->failure('complaint production list PDF', 'COMPLAINT_PRODUCTION_LIST_GENERATION_FAILED', $exception);
        }
    }

    public function requirementPreview(): Response
    {
        $data = $this->requirementRequest();
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            return new JsonResponse(['status' => 'ok', 'data' => $this->requirementLists->getRequirementListData($data)]);
        } catch (\Throwable $exception) {
            return $this->failure('requirement list preview', 'REQUIREMENT_LIST_PREVIEW_FAILED', $exception);
        }
    }

    public function requirementPdf(): Response
    {
        $data = $this->requirementRequest();
        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            return $this->pdfResponse($this->requirementLists->getRequirementListPDF($data));
        } catch (\Throwable $exception) {
            return $this->failure('requirement list PDF', 'REQUIREMENT_LIST_GENERATION_FAILED', $exception);
        }
    }

    /** @return array{order_id:list<int>, stock_minimum:bool}|JsonResponse */
    private function standardRequest(): array|JsonResponse
    {
        $request = $this->jsonRequest();
        if ($request instanceof JsonResponse) {
            return $request;
        }

        $orderIds = $this->validatedOrderIds($request, false);
        if ($orderIds instanceof JsonResponse) {
            return $orderIds;
        }

        $stockMinimum = $this->stockMinimum($request);
        if ($stockMinimum === null) {
            return new JsonResponse(['status' => 'error', 'description' => 'INVALID_STOCK_MINIMUM'], 422);
        }

        return ['order_id' => $orderIds, 'stock_minimum' => $stockMinimum];
    }

    /** @return array{order_id:list<int>, stock_minimum:bool}|JsonResponse */
    private function requirementRequest(): array|JsonResponse
    {
        $request = $this->jsonRequest();
        if ($request instanceof JsonResponse) {
            return $request;
        }

        $orderIds = $this->validatedOrderIds($request, true);
        if ($orderIds instanceof JsonResponse) {
            return $orderIds;
        }

        $stockMinimum = $this->stockMinimum($request);
        if ($stockMinimum === null) {
            return new JsonResponse(['status' => 'error', 'description' => 'INVALID_STOCK_MINIMUM'], 422);
        }

        return ['order_id' => $orderIds, 'stock_minimum' => $stockMinimum];
    }

    /** @return array<string, mixed>|JsonResponse */
    private function jsonRequest(): array|JsonResponse
    {
        try {
            $data = json_decode(Request::createFromGlobals()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['status' => 'error', 'description' => 'MALFORMED_JSON'], 400);
        }

        if (!is_array($data)) {
            return new JsonResponse(['status' => 'error', 'description' => 'MALFORMED_JSON'], 400);
        }

        return $data;
    }

    /** @param array<string, mixed> $request @return list<int>|JsonResponse */
    private function validatedOrderIds(array $request, bool $required): array|JsonResponse
    {
        $rawIds = $request['order_ids'] ?? [];
        if (!is_array($rawIds)) {
            return new JsonResponse(['status' => 'error', 'description' => 'INVALID_ORDER_ID'], 422);
        }
        if ($required && $rawIds === []) {
            return new JsonResponse(['status' => 'error', 'description' => 'ORDER_IDS_REQUIRED'], 422);
        }

        $orderIds = [];
        foreach ($rawIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                return new JsonResponse(['status' => 'error', 'description' => 'INVALID_ORDER_ID'], 422);
            }
            $orderIds[] = (int) $id;
        }
        $orderIds = array_values(array_unique($orderIds));

        if ($orderIds === []) {
            return $orderIds;
        }
        if (!$this->orders->areAllPendingOrderIds($orderIds)) {
            return new JsonResponse(['status' => 'error', 'description' => 'ORDER_NOT_PENDING_OR_NOT_FOUND'], 422);
        }

        $datesByOrderId = [];
        foreach ($this->orders->getPending() as $order) {
            $datesByOrderId[(int) $order['id']] = (string) $order['expected_shipping_date'];
        }
        $selectedDates = [];
        foreach ($orderIds as $orderId) {
            $selectedDates[$datesByOrderId[$orderId]] = true;
        }
        if (count($selectedDates) > 4) {
            return new JsonResponse(['status' => 'error', 'description' => 'TOO_MANY_SHIPPING_DATES'], 422);
        }

        return $orderIds;
    }

    /** @param array<string, mixed> $request */
    private function stockMinimum(array $request): ?bool
    {
        if (!array_key_exists('stock_minimum', $request)) {
            return false;
        }

        $value = filter_var($request['stock_minimum'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $value;
    }

    /** @param array{filename:string, file_string:string} $document */
    private function pdfResponse(array $document): Response
    {
        return new Response($document['file_string'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $document['filename'] . '"',
            'Content-Length' => (string) strlen($document['file_string']),
        ]);
    }

    private function failure(string $operation, string $code, \Throwable $exception): JsonResponse
    {
        error_log('[internal-api] ' . $operation . ' failed: ' . $exception->getMessage());
        return new JsonResponse(['status' => 'error', 'description' => $code], 500);
    }
}
