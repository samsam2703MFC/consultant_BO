<?php
declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Services\Client\Order\OrderService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PickingListController
{
    public function __construct(private OrderService $orders)
    {
    }

    public function create(): Response
    {
        $ids = $this->validatedOrderIds();
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        try {
            // This endpoint is intentionally public.  There is no authenticated
            // device context available, therefore it must not create a
            // device-attributed picking-list audit entry.
            $result = $this->orders->getPickingList($ids);
            return new Response($result['file_string'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
                'Content-Length' => (string) strlen($result['file_string']),
            ]);
        } catch (\Throwable $exception) {
            error_log('[internal-api] picking list generation failed: ' . $exception->getMessage());
            return new JsonResponse(['status' => 'error', 'description' => 'PICKING_LIST_GENERATION_FAILED'], 500);
        }
    }

    /**
     * Returns the exact per-order data set used by the picking-list PDF.
     * This endpoint is deliberately read-only: viewing data must not create
     * a picking-list audit record, which is reserved for an actual PDF generation.
     */
    public function preview(): Response
    {
        $ids = $this->validatedOrderIds();
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        try {
            return new JsonResponse([
                'status' => 'ok',
                'data' => [
                    'orders' => $this->orders->getPickingListPreview($ids),
                ],
            ]);
        } catch (\Throwable $exception) {
            error_log('[internal-api] picking list preview failed: ' . $exception->getMessage());
            return new JsonResponse(['status' => 'error', 'description' => 'PICKING_LIST_PREVIEW_FAILED'], 500);
        }
    }

    /** @return list<int>|JsonResponse */
    private function validatedOrderIds(): array|JsonResponse
    {
        try {
            $data = json_decode(Request::createFromGlobals()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['status' => 'error', 'description' => 'MALFORMED_JSON'], 400);
        }
        $orderIds = is_array($data) ? ($data['order_ids'] ?? null) : null;
        if (!is_array($orderIds) || !$this->orders->checkOrderQuantityForPickingList($orderIds)) {
            return new JsonResponse(['status' => 'error', 'description' => 'ORDER_IDS_REQUIRED'], 422);
        }

        $ids = [];
        foreach ($orderIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                return new JsonResponse(['status' => 'error', 'description' => 'INVALID_ORDER_ID'], 422);
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        if (!$this->orders->areAllPendingOrderIds($ids)) {
            return new JsonResponse(['status' => 'error', 'description' => 'ORDER_NOT_PENDING_OR_NOT_FOUND'], 422);
        }

        return $ids;
    }
}
