<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Client\Order\OrderSmsService;
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

    public function __construct(private CustomersNoOrderService $check, private PageAccessService $access, private OrderSmsService $sms)
    {
    }

    public function index()
    {
        $this->view('client/order/no_order', ['data' => $this->withSms($this->check->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withSms($this->check->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_customer, result: contacted|arrived|paused, note, paused_until} — krok 2 : wynik dla klienta. */
    public function result()
    {
        try {
            $this->guard();
            $data = $this->withSms($this->check->saveResult($this->getJson(Request::createFromGlobals()), $this->userId()));
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
            return $this->json(['message' => 'Klienci bez zamówienia sprawdzeni — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withSms($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [372] Dernier SMS au client sur chaque ligne (14 jours) : badge « SMS 9:14 » à côté du bouton. */
    private function withSms(array $d): array
    {
        $last = $this->sms->lastFor(['order_missing'], array_column($d['clients'], 'id'), date('Y-m-d 00:00:00', strtotime('-14 days')));
        foreach ($d['clients'] as &$row) $row['sms'] = $last[$row['id']] ?? null;
        unset($row);
        $d['sms_delivers'] = $this->sms->delivers();
        return $d;
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
