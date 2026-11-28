<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Ai\MorningRoutineService;
use App\Services\Client\Order\OrderSmsService;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\OrdersDayCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Zamówienia dnia » [369] : nouvelles et modifiées, à vérifier (résultat par commande), validation.
 * La page est sous /client-orders (droit « orders ») ; les appels ajax vérifient le même droit (App ne contrôle
 * que les pages). [387] Propositions de la routine IA du matin (`ai`, null sans proposition) : seulement affichées ;
 * le résultat enregistré par l'utilisateur marque la proposition acceptée (identique) ou modifiée.
 */
final class OrderDayCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = '/client-orders/day-check';

    public function __construct(private OrdersDayCheckService $check, private PageAccessService $access, private OrderSmsService $sms,
        private MorningRoutineService $routines)
    {
    }

    public function index()
    {
        $this->view('client/order/day_check', ['data' => $this->withSms($this->check->data())]);
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

    /** POST {id_order, result: ok|contact|changed, note} — krok 2 : wynik dla zamówienia. */
    public function result()
    {
        try {
            $this->guard();
            $in = $this->getJson(Request::createFromGlobals());
            $saved = $this->check->saveResult($in, $this->userId());
            $this->routines->recordOutcome(OrdersDayCheckService::CODE, (string) (int) ($in['id_order'] ?? 0), (string) ($in['result'] ?? ''), $in['note'] ?? null, $saved, $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withSms($saved)]);
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
            return $this->json(['message' => 'Zamówienia dnia sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withSms($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [372] Dernier SMS au client sur chaque ligne (14 jours) : badge « SMS 9:14 » à côté du bouton. */
    private function withSms(array $d): array
    {
        $last = $this->sms->lastFor(['order_confirm', 'order_changed'], array_column($d['orders'], 'id'), date('Y-m-d 00:00:00', strtotime('-14 days')));
        foreach ($d['orders'] as &$row) $row['sms'] = $last[$row['id']] ?? null;
        unset($row);
        $d['sms_delivers'] = $this->sms->delivers();
        // [387] Propositions de l'IA du jour (noms réels remis ici seulement).
        $d['ai'] = $this->routines->forPage(OrdersDayCheckService::CODE, $d);
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
