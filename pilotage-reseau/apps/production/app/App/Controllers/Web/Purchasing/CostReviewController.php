<?php
namespace App\Controllers\Web\Purchasing;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\Wizards\CostReviewService;
use Symfony\Component\HttpFoundation\Request;

/** Kreator « Koszty surowców i oszczędności » [247] (makieta 22) : co zdrożało, tańsze opcje, decyzje. */
class CostReviewController extends BaseController
{
    use JsonErrors;

    public function __construct(private CostReviewService $review)
    {
    }

    public function index()
    {
        $this->view('purchasing/cost_review', ['data' => $this->review->data($_GET['m'] ?? null)]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->review->data($_GET['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {m, rows: [{id, decision}]} — « Zapisz decyzje » : zadanie zaliczone. */
    public function finish()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $id = user('id');
            $r = $this->review->finish($body['m'] ?? null, is_array($body['rows'] ?? null) ? $body['rows'] : [], $id ? (int) $id : null);
            return $this->json(['message' => 'Decyzje zapisane — ' . $r['summary'] . '.', 'result' => $r, 'data' => $this->review->data($body['m'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
