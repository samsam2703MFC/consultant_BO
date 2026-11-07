<?php
namespace App\Controllers\Web\Client\Complaint;

use App\Core\Web\BaseController;
use App\Repositories\Client\Complaint\ReturnsRepository;

/**
 * Zwroty do wysłania (audit 2, P8) : une page pour tout ce qui doit repartir vers les clients — remplacements acceptés à
 * préparer, remplacements préparés à expédier (« Wysłane » se pose seul au WZ du client), cartons retournés à renvoyer
 * avec WZ + CMR, réclamations sans décision. Les actions restent celles des pages existantes.
 */
class ReturnsController extends BaseController
{
    public function __construct(private ReturnsRepository $returns)
    {
    }

    /** GET /client-complaints/to-ship */
    public function index()
    {
        $toShip = $this->returns->toShip();
        $this->view('client/complaint/to_ship', [
            'pending' => $this->returns->pendingReplacements(),
            'to_ship' => $toShip,
            'returns' => $this->returns->returnCartons(),
            'complaints' => $this->returns->openComplaints(),
            'today' => date('Y-m-d'),
        ]);
    }
}
