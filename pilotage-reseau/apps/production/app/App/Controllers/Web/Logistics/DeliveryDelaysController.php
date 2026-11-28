<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Ai\MorningRoutineService;
use App\Services\Client\Order\OrderSmsService;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\DeliveryDelaysService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Opóźnienia dostaw » [374] : przegląd dostaw (dziś, do następnego dnia roboczego, po terminie, w transporcie),
 * do wyjaśnienia (decyzja dla każdej dostawy z sygnałem), zatwierdzenie. La page est sous /logistics (droit existant
 * « logistics ») ; les appels ajax vérifient le même droit (App ne contrôle que les pages). Le bouton SMS passe par
 * /ajax/client-orders/sms (droit « orders ») : il n'est proposé qu'à qui a aussi ce droit. Lecture seule hors de
 * n_delivery_delay_check ; aucun SMS sans clic. [387] Propositions de la routine IA du matin (`ai`, null sans
 * proposition) : seulement affichées ; la décision enregistrée marque la proposition acceptée (identique) ou modifiée.
 */
final class DeliveryDelaysController extends BaseController
{
    use JsonErrors;

    public const PATH = '/logistics/delays';
    /** Page des SMS de commande (OrderSmsController::PATH). */
    private const SMS_PATH = '/client-orders';

    public function __construct(private DeliveryDelaysService $delays, private PageAccessService $access, private OrderSmsService $sms,
        private SmsTransport $gateway, private MorningRoutineService $routines)
    {
    }

    public function index()
    {
        $this->view('logistics/delivery_delays', ['data' => $this->withSms($this->delays->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withSms($this->delays->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_order, decision: ok|new_date|contact, new_date, note} — krok 2 : decyzja dla dostawy. */
    public function decision()
    {
        try {
            $this->guard();
            $in = $this->getJson(Request::createFromGlobals());
            $saved = $this->delays->saveDecision($in, $this->userId());
            $this->routines->recordOutcome(DeliveryDelaysService::CODE, (string) (int) ($in['id_order'] ?? 0), (string) ($in['decision'] ?? ''), $in['note'] ?? null, $saved, $this->userId());
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
            $r = $this->delays->finish($this->userId());
            return $this->json(['message' => 'Opóźnienia dostaw sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withSms($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** [372] Dernier SMS de commande sur chaque ligne (14 jours) : badge « SMS 9:14 » ; bouton seulement avec le droit « orders ». */
    private function withSms(array $d): array
    {
        $d['can_sms'] = $this->access->canSee($this->userId(), self::SMS_PATH);
        $last = $d['can_sms'] ? $this->sms->lastFor(['order_changed', 'order_confirm'], array_column($d['orders'], 'id'), date('Y-m-d 00:00:00', strtotime('-14 days'))) : [];
        foreach ($d['orders'] as &$row) $row['sms'] = $last[$row['id']] ?? null;
        unset($row);
        // Passerelle : envoi réel seulement en mode « live » (OrderSmsService n'expose pas delivers()).
        $d['sms_delivers'] = $d['can_sms'] && $this->gateway->delivers();
        // [387] Propositions de l'IA du jour (noms réels remis ici seulement).
        $d['ai'] = $this->routines->forPage(DeliveryDelaysService::CODE, $d);
        return $d;
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do logistyki.', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
