<?php
namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Services\Client\Order\OrderQueueService;

/**
 * Do obsłużenia : zamówienia, proformy, wysyłki i e-maile, które czekają na działanie — jedna linia na sprawę,
 * termin, stanowisko odpowiedzialne i bezpośrednie działanie.
 */
class OrderQueueController extends BaseController
{
    public function __construct(private OrderQueueService $queue)
    {
    }

    /** GET /client-orders/queue?rule=&when=all|overdue|today|week&mine=1 */
    public function index()
    {
        $q = fn(string $k) => is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        $userId = user('id') ? (int) user('id') : null;
        $this->view('client/order/queue', $this->queue->overview($q('rule'), $q('when') ?? 'all', $q('mine') === '1', $userId));
    }
}
