<?php
namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\LogisticsService;
use Symfony\Component\HttpFoundation\Request;

/** Logistyka : klienci dnia na trasach i pojazdach, palety i waga, trasy, pojazdy z pojemnością, lista załadunku. */
class LogisticsController extends BaseController
{
    use JsonErrors;

    public function __construct(private LogisticsService $logistics) {}

    public function day()
    {
        $date = (string) ($_GET['date'] ?? '') ?: $this->logistics->defaultDate();
        try {
            $data = $this->logistics->day($date);
        } catch (\Throwable $e) {
            $data = $this->logistics->day($this->logistics->defaultDate());
        }
        // view() nadpisuje „warnings” : ostrzeżenia dnia idą jako „notices”.
        $data['notices'] = $data['warnings'];
        unset($data['warnings']);
        $this->view('logistics/day', $data);
    }

    public function loadingList()
    {
        $data = $this->logistics->day((string) ($_GET['date'] ?? date('Y-m-d')));
        $vehicleId = (int) ($_GET['vehicle'] ?? 0);
        $truck = null;
        foreach ($data['fleet'] as $t) if ($t['id'] === $vehicleId) $truck = $t;
        $this->view('logistics/loading_list', ['day' => $data, 'truck' => $truck]);
    }

    public function vehicles()
    {
        $this->view('logistics/vehicles', $this->logistics->vehicles());
    }

    public function tours()
    {
        $data = $this->logistics->tours();
        $data['notices'] = $data['warnings'];
        unset($data['warnings']);
        $this->view('logistics/tours', $data);
    }

    public function createTour()
    {
        return $this->respond(fn() => ['message' => 'Dodano trasę.', 'data' => ['id' => $this->logistics->createTour($this->input(), $this->userId())]], 201);
    }

    public function updateTour($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano trasę.', 'data' => $this->logistics->updateTour((int) $id, $this->input(), $this->userId())]);
    }

    public function deleteTour($id)
    {
        return $this->respond(fn() => ['message' => 'Usunięto trasę.', 'data' => $this->logistics->deleteTour((int) $id)]);
    }

    public function setTourVehicles($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano pojazdy trasy.', 'data' => $this->logistics->setTourVehicles((int) $id, $this->input())]);
    }

    public function setTourCustomers($id)
    {
        return $this->respond(fn() => ['message' => 'Zapisano klientów trasy.', 'data' => $this->logistics->setTourCustomers((int) $id, $this->input())]);
    }

    public function assign()
    {
        try {
            $this->logistics->assign($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano przydział.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveVehicle($id)
    {
        try {
            $this->logistics->saveVehicle((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano pojazd.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function addVehicle()
    {
        try {
            $id = $this->logistics->addVehicle($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Dodano pojazd.', 'data' => ['id' => $id]], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function savePalletData($id)
    {
        try {
            $result = $this->logistics->savePalletData((int) $id, $this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => $result['consistent'] ? 'Zapisano dane paletowe.' : 'Zapisano — uwaga: warstwy × kartony na warstwę ≠ kartony na paletę.', 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
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
