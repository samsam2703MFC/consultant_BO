<?php
declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Services\Client\Order\OrderService;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ClientOrderController
{
    public function __construct(private OrderService $orders)
    {
    }

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'data' => $this->orders->getPending(),
        ]);
    }

}
