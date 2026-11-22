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
 * [372] SMS de commande aux clients depuis les assistants (un clic, texte relu) : brouillon puis envoi.
 * Droit « orders » (pages /client-orders) ; App ne contrôle que les pages, d'où la garde ici.
 */
final class OrderSmsController extends BaseController
{
    use JsonErrors;

    public const PATH = '/client-orders';

    public function __construct(private OrderSmsService $sms, private PageAccessService $access, private CustomersNoOrderService $missing)
    {
    }

    /** GET ?kind=order_missing|order_confirm|order_changed&id=…&expected=Y-m-d&detail=… */
    public function draft()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->sms->draft((string) ($_GET['kind'] ?? ''), (int) ($_GET['id'] ?? 0),
                ['expected' => isset($_GET['expected']) ? (string) $_GET['expected'] : null, 'detail' => isset($_GET['detail']) ? (string) $_GET['detail'] : null])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /**
     * POST {kind, id, text}. Relance « pas de commande » : si le client attend encore un résultat dans l'assistant
     * « Klienci bez zamówienia », le résultat « Kontakt z klientem » est noté avec le texte du SMS.
     */
    public function send()
    {
        try {
            $this->guard();
            $in = $this->getJson(Request::createFromGlobals());
            $kind = (string) ($in['kind'] ?? '');
            $r = $this->sms->send($kind, (int) ($in['id'] ?? 0), (string) ($in['text'] ?? ''), $this->userId());
            $noted = false;
            if ($kind === 'order_missing') {
                $id = (int) $in['id'];
                $late = array_values(array_filter($this->missing->data()['clients'], fn($c) => $c['id'] === $id && $c['status'] === 'late' && $c['check'] === null));
                if ($late) {
                    $this->missing->saveResult(['id_customer' => $id, 'result' => 'contacted', 'note' => mb_substr('SMS : ' . $r['text'], 0, 500)], $this->userId());
                    $noted = true;
                }
            }
            return $this->json(['message' => $r['message'] . ($noted ? ' Wynik : kontakt z klientem.' : ''), 'result' => ['status' => $r['status'], 'delivers' => $r['delivers'], 'noted' => $noted]]);
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
