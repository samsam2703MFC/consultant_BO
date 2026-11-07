<?php
declare(strict_types=1);

namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Stock\StockPeriodService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Magazyn › Prognoza zapasu [216] : zapas potrzebny wg planu logistyki (wyjazd, powrót, dni pracy, produkty MTS na tydzień),
 * okresy stanów wyjątkowych (projekt, zatwierdzenie, powrót do normalnych). Dane : StockPeriodService.
 */
final class StockPlanController extends BaseController
{
    use JsonErrors;

    public function __construct(private StockPeriodService $periods) {}

    public function index()
    {
        try {
            $boot = $this->periods->page(isset($_GET['period']) ? (int) $_GET['period'] : null);
        } catch (\Throwable $e) {
            $boot = ['error' => $e->getMessage()];
        }
        $this->view('workshop/stock_forecast', ['boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)]);
    }

    public function data()
    {
        return $this->respond(fn() => ['data' => $this->periods->page(isset($_GET['period']) && (int) $_GET['period'] > 0 ? (int) $_GET['period'] : null)]);
    }

    public function create()
    {
        return $this->respond(fn() => ['message' => 'Utworzono okres (projekt) ze stanami z prognozy.', 'data' => $this->periods->create($this->input(), $this->userId())], 201);
    }

    public function update($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano okres.', 'data' => $this->periods->update((int) $id, $this->input())]);
    }

    public function approve($id)
    {
        return $this->respond(fn() => ['message' => 'Zatwierdzono stany wyjątkowe.', 'data' => $this->periods->approve((int) $id, $this->userId())]);
    }

    public function reopen($id)
    {
        return $this->respond(fn() => ['message' => 'Cofnięto zatwierdzenie — obowiązują stany normalne.', 'data' => $this->periods->reopen((int) $id)]);
    }

    public function delete($id)
    {
        return $this->respond(function () use ($id) {
            $this->periods->delete((int) $id);
            return ['message' => 'Usunięto okres.'];
        });
    }

    private function respond(callable $fn, int $status = 200)
    {
        try {
            return $this->json($fn(), $status);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
