<?php
namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\Wizards\YieldReviewService;
use Symfony\Component\HttpFoundation\Request;

/** Kreator « Wydajność i straty kwartału » [247] (makieta 23) : straty, wydajność, działania. */
class YieldReviewController extends BaseController
{
    use JsonErrors;

    public function __construct(private YieldReviewService $review)
    {
    }

    public function index()
    {
        $this->view('production/yield_review', ['data' => $this->review->data($_GET['q'] ?? null)]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->review->data($_GET['q'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {q, actions: [{title, goal, user_id, group_id, due_date}], tablets} — « Zatwierdź przegląd ». */
    public function finish()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $id = user('id');
            $r = $this->review->finish($body['q'] ?? null, is_array($body['actions'] ?? null) ? $body['actions'] : [], !empty($body['tablets']), $id ? (int) $id : null);
            return $this->json(['message' => 'Przegląd zatwierdzony — ' . $r['summary'] . '.', 'result' => $r, 'data' => $this->review->data($body['q'] ?? null)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
