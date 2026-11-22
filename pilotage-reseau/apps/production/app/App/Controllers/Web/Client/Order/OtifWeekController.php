<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\OtifWeekService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Terminowość tygodnia (OTIF) » [375] : wynik tygodnia (na czas, w całości, OTIF, trend, klienci, przyczyny),
 * przyczyny (działanie dla każdej głównej przyczyny), podsumowanie. La page est sous /client-orders (droit existant
 * « orders » : Zamówienia klientów) ; les appels ajax vérifient le même droit (App ne contrôle que les pages).
 * Aucun montant affiché (délais et quantités seulement). Lecture seule hors de n_otif_week_action et n_otif_week ;
 * aucun e-mail ni SMS.
 */
final class OtifWeekController extends BaseController
{
    use JsonErrors;

    public const PATH = '/client-orders/otif';

    public function __construct(private OtifWeekService $otif, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('client/order/otif_week', ['data' => $this->otif->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->otif->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {cause, decision: accepted|corrective|watch, note, id_responsible, id_position} — krok 2 : działanie dla przyczyny. */
    public function action()
    {
        try {
            $this->guard();
            $data = $this->otif->saveAction($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : wynik tygodnia zapisany, n_wizard_done, zadanie tygodnia zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->otif->finish($this->userId());
            return $this->json(['message' => 'Terminowość tygodnia sprawdzona — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
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
