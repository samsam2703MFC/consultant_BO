<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\CustomersNoOrderService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Klienci bez zamówienia » [370] : przegląd stałych klientów, do kontaktu (wynik dla każdego), zatwierdzenie.
 * La page est sous /client-orders (droit « orders ») ; les appels ajax vérifient le même droit (App ne contrôle
 * que les pages).
 */
final class CustomersNoOrderController extends BaseController
{
    use JsonErrors;

    public const PATH = '/client-orders/missing';

    public function __construct(private CustomersNoOrderService $check, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('client/order/no_order', ['data' => $this->check->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->check->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_customer, result: contacted|arrived|paused, note, paused_until} — krok 2 : wynik dla klienta. */
    public function result()
    {
        try {
            $this->guard();
            $data = $this->check->saveResult($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->check->finish($this->userId());
            return $this->json(['message' => 'Klienci bez zamówienia sprawdzeni — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do zamówień klientów.', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
