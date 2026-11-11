<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\ActivityService;
use Symfony\Component\HttpFoundation\Request;

/** Aktywność w ERP : zapis z przeglądarki (wyświetlenie, puls) i raport administratora (czas, kliknięcia, koszt). */
class ActivityController extends BaseController
{
    use JsonErrors;

    public function __construct(private ActivityService $activity)
    {
    }

    public function index()
    {
        $from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
        $to = (string) ($_GET['to'] ?? date('Y-m-d'));
        $user = isset($_GET['user']) && $_GET['user'] !== '' ? (int) $_GET['user'] : null;
        $this->view('organization/activity', $this->activity->report($from, $to, $user));
    }

    public function start()
    {
        try {
            return $this->json(['data' => $this->activity->view($this->userId() ?? 0, $this->body())], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function beat()
    {
        try {
            $this->activity->beat($this->userId() ?? 0, $this->body());
            return $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function cost($id)
    {
        try {
            $v = $this->activity->setCost((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano koszt godziny.', 'data' => ['hourly_cost' => $v]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** JSON albo text/plain (navigator.sendBeacon przy zamykaniu strony). */
    private function body(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
