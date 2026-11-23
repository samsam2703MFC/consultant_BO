<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\PalletsReturnsService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Palety zwrotne » [381] : przegląd (saldo palet EUR klientów, brakujące i uszkodzone palety tygodnia), do
 * wyjaśnienia (decyzja dla klienta), zatwierdzenie. La page est sous /logistics (droit existant « logistics » : Logistyka i
 * pakowanie, le même que l'écran des confirmations de livraison /logistics/deliveries vers lequel l'assistant renvoie) ;
 * les appels ajax vérifient le même droit (App ne contrôle que les pages). Aucun droit nouveau.
 * Lecture seule hors de n_pallet_return_review et n_wizard_done ; aucun arrêt ni incident modifié, aucun e-mail ni SMS.
 */
final class PalletsReturnsController extends BaseController
{
    use JsonErrors;

    public const PATH = PalletsReturnsService::PATH;

    public function __construct(private PalletsReturnsService $pallets, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('logistics/pallets_returns', ['data' => $this->pallets->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->pallets->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_customer, decision: reminder|agreed|written_off, note} — krok 2 : decyzja dla klienta. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->pallets->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie tygodnia zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->pallets->finish($this->userId());
            return $this->json(['message' => 'Palety zwrotne przejrzane — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do logistyki (palety zwrotne).', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
