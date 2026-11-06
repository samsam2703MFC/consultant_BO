<?php
namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\Wizards\TomorrowPlanService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Kreator « Plan na jutro » [247] (makieta 19) : obciążenie grup, ludzie i surowce, potwierdzenie (+ tablety). */
class TomorrowPlanController extends BaseController
{
    use JsonErrors;

    public function __construct(private TomorrowPlanService $plan)
    {
    }

    public function index()
    {
        $this->view('production/tomorrow', ['data' => $this->plan->data($_GET['date'] ?? null, $this->userId())]);
    }

    public function data()
    {
        try {
            return $this->json(['data' => $this->plan->data($_GET['date'] ?? null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {date, item_id, to} — propozycja z kroku 1 : blok na późniejszy dzień tygodnia. */
    public function move()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $this->plan->move($body, $this->userId());
            return $this->json(['message' => 'Blok przesunięty.', 'data' => $this->plan->data($body['date'] ?? null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {date, tablets, note} — « Zatwierdź plan » : zadanie zaliczone. */
    public function confirm()
    {
        try {
            $body = $this->getJson(Request::createFromGlobals());
            $r = $this->plan->confirm($body, $this->userId());
            return $this->json(['message' => 'Plan zatwierdzony — ' . $r['summary'] . '.', 'result' => $r, 'data' => $this->plan->data($body['date'] ?? null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function pdf()
    {
        $file = $this->plan->pdf($_GET['date'] ?? null, $this->userId());
        return new Response($file['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"', 'Content-Length' => strlen($file['content'])]);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
