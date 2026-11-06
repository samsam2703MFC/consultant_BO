<?php
namespace App\Controllers\Web\Purchasing;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Purchasing\SupplierReviewService;
use Symfony\Component\HttpFoundation\Request;

/** Kreator « Ocena dostawców i zapytanie ofertowe » [230] (makieta 6) : oceny A / B / C, surowce, szkic zapytania. */
class SupplierReviewController extends BaseController
{
    use JsonErrors;

    public function __construct(private SupplierReviewService $review)
    {
    }

    public function index()
    {
        $this->view('purchasing/supplier_review', ['data' => $this->review->data($this->q())]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->review->data($this->q())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {quarter, rows: [{supplier_id, decision, note}]} — krok 1 « Dalej ». */
    public function grades()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $n = $this->review->saveGrades($this->q($body), is_array($body['rows'] ?? null) ? $body['rows'] : [], $this->userId());
            return $this->json(['message' => 'Zapisano oceny : ' . $n . '.', 'data' => $this->review->data($this->q($body))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {title, reply_deadline, intro, material_ids, supplier_ids} — krok 3 : szkic zapytania (nic nie jest wysyłane). */
    public function tender()
    {
        try {
            $r = $this->review->createTender($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Szkic zapytania ' . ($r['code'] ?? '') . ' utworzony — sprawdź i wyślij w Zapytaniach ofertowych.', 'tender' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {quarter, rows, tender_id} — « Zatwierdź ocenę » : zadanie zaliczone. */
    public function finish()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $tender = is_int($body['tender_id'] ?? null) ? $body['tender_id'] : null;
            $r = $this->review->finish($this->q($body), is_array($body['rows'] ?? null) ? $body['rows'] : [], $tender, $this->userId());
            return $this->json(['message' => 'Ocena zatwierdzona — ' . $r['summary'] . '.', 'data' => $this->review->data($this->q($body))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function q(array $body = []): ?string
    {
        $q = $body['quarter'] ?? ($_GET['q'] ?? null);
        return is_string($q) ? $q : null;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
