<?php
namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Forecast\PanelProbeService;

/** Prognoza 13 tygodni [220] : sonda API panelu TF Buddy (administrator, tylko GET). */
class ForecastController extends BaseController
{
    use JsonErrors;

    public function __construct(private PanelProbeService $probe)
    {
    }

    public function panelProbe()
    {
        try {
            return $this->json(['data' => ($_GET['step'] ?? '') === '2' ? $this->probe->deep($this->userId()) : $this->probe->probe($this->userId())]);
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
