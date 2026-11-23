<?php
declare(strict_types=1);

namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\InvoicesKsefDayService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Faktury i KSeF dnia » [378] : przegląd wysyłek dnia i ich faktur WDT / KSeF, do wyjaśnienia (wynik dla
 * dokumentu), zatwierdzenie. La page est sous /shipments (droit existant « shipping » : wysyłki i dokumenty — WDT, KSeF
 * de chaque expédition —, le même que l'écran « Wysyłki i dokumenty » où l'on renvoie un document, accordé à
 * Administracja par la migration 182) ; les appels ajax vérifient le même droit (App ne contrôle que les pages).
 * Lecture seule hors de n_invoice_ksef_check et n_wizard_done ; rien n'est envoyé à KSeF, aucun e-mail ni SMS.
 */
final class InvoicesKsefDayController extends BaseController
{
    use JsonErrors;

    public const PATH = InvoicesKsefDayService::PATH;

    public function __construct(private InvoicesKsefDayService $ksef, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('shipping/invoices_ksef_day', ['data' => $this->ksef->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->ksef->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_shipment, result: fixed|in_progress|justified, note} — krok 2 : wynik dla dokumentu. */
    public function result()
    {
        try {
            $this->guard();
            $data = $this->ksef->saveResult($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->ksef->finish($this->userId());
            return $this->json(['message' => 'Faktury i KSeF dnia sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do wysyłek i dokumentów (faktury WDT i KSeF).', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
