<?php
namespace App\Controllers\Web\Purchasing;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\Wizards\NcReviewService;
use Symfony\Component\HttpFoundation\Request;

/** Kreator « Niezgodności miesiąca » [247] (makieta 21) : lista, przyczyna i działanie, dostawcy i audyt, e-mail. */
class NcReviewController extends BaseController
{
    use JsonErrors;

    public function __construct(private NcReviewService $review)
    {
    }

    public function index()
    {
        $this->view('purchasing/nc_review', ['data' => $this->review->data($_GET['m'] ?? null)]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->review->data($_GET['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {m, cause_cat, corrective_action, owner_user, due_date, status} — krok 2. */
    public function action($id)
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $this->review->saveAction((int) $id, $body, $this->userId());
            return $this->json(['message' => 'Zapisano działanie.', 'data' => $this->review->data($body['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {m, supplier_id, planned_on, done_on, result, note, reason} — krok 3. */
    public function audit()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $this->review->saveAudit($body['m'] ?? null, $body, $this->userId());
            return $this->json(['message' => 'Zapisano audyt dostawcy.', 'data' => $this->review->data($body['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {m, supplier_id} — e-mail do dostawcy z NC miesiąca (kolejka wysyłki). */
    public function mail()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $r = $this->review->mailSupplier($body['m'] ?? null, (int) ($body['supplier_id'] ?? 0), $this->userId());
            return $this->json(['message' => 'E-mail w kolejce wysyłki : ' . $r['ncs'] . ' NC' . ($r['photos'] ? ', ' . $r['photos'] . ' zdjęć' : '') . '.', 'result' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function finish()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $r = $this->review->finish($body['m'] ?? null, $this->userId());
            return $this->json(['message' => 'Miesiąc zatwierdzony — ' . $r['summary'] . '.', 'data' => $this->review->data($body['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
