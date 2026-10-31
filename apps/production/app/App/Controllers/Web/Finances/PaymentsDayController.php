<?php
namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Finances\PaymentsDayService;
use Symfony\Component\HttpFoundation\Request;

/** Kreator « Płatności dnia » [230] (makieta 5) : wpłaty proform, przypomnienia, różnice dostaw → zadanie zaliczone. */
class PaymentsDayController extends BaseController
{
    use JsonErrors;

    public function __construct(private PaymentsDayService $payments)
    {
    }

    public function index()
    {
        $this->view('finances/payments_day', ['data' => $this->payments->data(date('Y-m-d'))]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->payments->data(date('Y-m-d'))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {items: [{id, paid_on, amount}]} */
    public function paid()
    {
        try {
            $items = $this->getJson(Request::createFromGlobals())['items'] ?? [];
            $r = $this->payments->markPaid(is_array($items) ? $items : [], date('Y-m-d'), $this->userId());
            return $this->json(['message' => 'Opłacone : ' . $r['paid'] . ($r['failed'] ? ' · nie udało się : ' . count($r['failed']) . ' (anulowane albo bez pozycji)' : '') . '.',
                'result' => $r, 'data' => $this->payments->data(date('Y-m-d'))], $r['paid'] ? 200 : 422);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function finish()
    {
        try {
            $r = $this->payments->finish(date('Y-m-d'), $this->userId());
            return $this->json(['message' => 'Zadanie zaliczone : ' . $r['summary'] . '.', 'data' => $this->payments->data(date('Y-m-d'))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
