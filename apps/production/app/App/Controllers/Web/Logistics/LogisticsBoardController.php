<?php
namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Logistics\LogisticsService;
use Symfony\Component\HttpFoundation\Request;

/** Logistyka i pakowanie : ekran paletyzacji na żywo, załadunek ciężarówki (plan na tablet kierowcy) ; pakowanie = tablet /tablet/packing. */
class LogisticsBoardController extends BaseController
{
    use JsonErrors;

    public function __construct(private LogisticsBoardService $board, private LogisticsService $logistics) {}

    public function live()
    {
        $this->page('logistics/live', fn($d) => $this->board->live($d));
    }

    public function loading()
    {
        $vehicle = (int) ($_GET['vehicle'] ?? 0) ?: null;
        $this->page('logistics/loading', fn($d) => $this->board->loading($d, $vehicle));
    }

    public function liveData()
    {
        return $this->respond(fn() => ['data' => $this->board->live($this->date())]);
    }

    public function loadingData()
    {
        return $this->respond(fn() => ['data' => $this->board->loading($this->date(), (int) ($_GET['vehicle'] ?? 0) ?: null)]);
    }

    public function saveLoading()
    {
        return $this->respond(fn() => ['message' => 'Zapisano załadunek.', 'data' => $this->board->saveLoading($this->input(), $this->userId())]);
    }

    public function sendLoading()
    {
        return $this->respond(function () {
            $data = $this->board->sendLoading($this->input(), $this->userId());
            return ['message' => "Plan wysłany na tablet kierowcy (wersja {$data['vehicle']['sent']['version']}).", 'data' => $data];
        });
    }

    /** Strona dnia : wybrany dzień wysyłki (domyślnie najbliższy z zamówieniami) ; zła data → dzień domyślny. */
    private function page(string $view, callable $load): void
    {
        $date = $this->date();
        try {
            $data = $load($date);
        } catch (\Throwable $e) {
            $data = $load($this->logistics->defaultDate());
        }
        $this->view($view, ['state' => $data, 'date' => $data['date'], 'dates' => $data['dates']]);
    }

    private function date(): string
    {
        $date = (string) ($_GET['date'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $this->logistics->defaultDate();
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
