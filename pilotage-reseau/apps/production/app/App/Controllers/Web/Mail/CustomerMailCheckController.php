<?php
declare(strict_types=1);

namespace App\Controllers\Web\Mail;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\CustomerMailCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Poczta od klientów » [383] : przegląd (e-maile od klientów bez odpowiedzi po terminie, stan synchronizacji
 * skrzynki), do wyjaśnienia (decyzja dla e-maila), zatwierdzenie. La page est sous /mail, comme Poczta : Poczta n'a pas de
 * droit dans le catalogue PageAccess (page commune à toute personne connectée, chacun n'y lit que SA boîte), l'assistant
 * garde exactement cette règle — même garde PageAccessService::canSee que la page (aucun droit nouveau, aucun droit changé),
 * personne connectée obligatoire, boîte choisie comme MailController::mailUser (CustomerMailCheckService::owner).
 * Lecture seule hors de n_customer_mail_check et n_wizard_done ; aucun e-mail envoyé, aucune connexion IMAP / SMTP.
 */
final class CustomerMailCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = CustomerMailCheckService::PATH;

    public function __construct(private CustomerMailCheckService $checks, private PageAccessService $access)
    {
    }

    public function index()
    {
        $uid = $this->userId();
        $this->view('mail/customer_mail_check', ['data' => $uid !== null && $this->access->canSee($uid, self::PATH) ? $this->checks->data($uid) : null]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->checks->data($this->guard())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_mail, decision: answered|not_needed|forwarded, note, id_responsible?, id_position?} — krok 2 : decyzja dla e-maila. */
    public function decision()
    {
        try {
            $uid = $this->guard();
            $data = $this->checks->saveDecision($this->getJson(Request::createFromGlobals()), $uid);
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie dnia zaliczone. */
    public function finish()
    {
        try {
            $r = $this->checks->finish($this->guard());
            return $this->json(['message' => 'Poczta od klientów sprawdzona — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): int
    {
        $uid = $this->userId();
        if ($uid === null || !$this->access->canSee($uid, self::PATH)) throw new CustomException('Brak dostępu do poczty (asystent « Poczta od klientów »).', 'errors', 403);
        return $uid;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
