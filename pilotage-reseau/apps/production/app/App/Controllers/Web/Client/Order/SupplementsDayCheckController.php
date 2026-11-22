<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Order;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\SupplementsDayCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Domówienia dnia » [371] : przegląd domówień (stan po stronie produkcji), do wyjaśnienia (wynik dla każdego
 * domówienia z sygnałem), zatwierdzenie. La page est sous /client-orders (droit « orders ») ; les appels ajax vérifient
 * le même droit (App ne contrôle que les pages). Lecture seule hors de n_supplement_check.
 */
final class SupplementsDayCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = '/client-orders/supplements';

    public function __construct(private SupplementsDayCheckService $check, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('client/order/supplements_day', ['data' => $this->check->data()]);
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

    /** POST {id_order, result: sent|contact|moved, note} — krok 2 : wynik dla domówienia. */
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
            return $this->json(['message' => 'Domówienia dnia sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
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
