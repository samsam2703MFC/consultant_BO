<?php
namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Services\Finances\ClientVolumeService;

/** Finanse › Klienci : wolumen i marża (miesiące, N-1, marża po surowcach i po koszcie pełnym, alerty). */
class ClientVolumeController extends BaseController
{
    public function __construct(private ClientVolumeService $volumes) {}

    public function index()
    {
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > (int) date('Y') + 1) $year = (int) date('Y');
        $this->view('finances/client_volume', $this->volumes->report($year) + ['metric' => in_array($_GET['metric'] ?? '', ['value', 'cartons', 'margin'], true) ? $_GET['metric'] : 'value']);
    }
}
