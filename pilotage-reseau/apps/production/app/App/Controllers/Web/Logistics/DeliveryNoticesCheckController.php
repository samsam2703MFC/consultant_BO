<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\DeliveryNoticesCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Powiadomienia o dostawie » [382] : przegląd (klienci dnia wysyłki — i następnego dnia roboczego, gdy automat
 * wysyła dzień wcześniej — z powiadomieniem, błędem e-mail / SMS, bez kontaktu), do wyjaśnienia (decyzja dla klienta),
 * zatwierdzenie. La page est sous /logistics (droit existant « logistics » : Logistyka i pakowanie, le même que l'écran
 * Powiadomienia klientów /logistics/notices vers lequel l'assistant renvoie pour envoyer l'avis) ; les appels ajax vérifient
 * le même droit (App ne contrôle que les pages). Aucun droit nouveau.
 * Lecture seule hors de n_delivery_notice_check et n_wizard_done ; aucun avis modifié, aucun e-mail ni SMS envoyé.
 */
final class DeliveryNoticesCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = DeliveryNoticesCheckService::PATH;

    public function __construct(private DeliveryNoticesCheckService $checks, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('logistics/delivery_notices_check', ['data' => $this->checks->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->checks->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {ship_date, id_customer, decision: resent|phone|justified, note} — krok 2 : decyzja dla klienta. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->checks->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie dnia zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->checks->finish($this->userId());
            return $this->json(['message' => 'Powiadomienia o dostawie sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do logistyki (powiadomienia o dostawie).', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
