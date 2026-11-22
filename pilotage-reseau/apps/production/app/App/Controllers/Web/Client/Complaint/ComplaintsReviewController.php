<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client\Complaint;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\ComplaintsReviewService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Reklamacje klientów » [379] : przegląd reklamacji otwartych i źle prowadzonych oraz tygodnia, do wyjaśnienia
 * (decyzja dla reklamacji), zatwierdzenie. La page est sous /franchise-complaints (droit existant « franchise » : la liste
 * des réclamations où l'on décide et répond, accordé aux postes par la migration 050) ; les appels ajax vérifient le même
 * droit (App ne contrôle que les pages). Montants : profil Finanse et CEO seulement (PageAccessService::seesOrderValues, [312]).
 * Lecture seule hors de n_complaint_review et n_wizard_done ; aucun statut de réclamation changé, aucun e-mail ni SMS.
 */
final class ComplaintsReviewController extends BaseController
{
    use JsonErrors;

    public const PATH = ComplaintsReviewService::PATH;

    public function __construct(private ComplaintsReviewService $review, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('client/complaint/complaints_review', ['data' => $this->review->data(null, $this->values())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->review->data(null, $this->values())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_complaint, decision: answered|nc|watch, note} — krok 2 : decyzja dla reklamacji. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->review->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId(), $this->values());
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
            $r = $this->review->finish($this->userId(), $this->values());
            return $this->json(['message' => 'Reklamacje klientów przejrzane — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do reklamacji klientów.', 'errors', 403);
    }

    /** [312] Wartości reklamacji : profil Finanse i CEO. */
    private function values(): bool
    {
        return $this->access->seesOrderValues($this->userId());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
