<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Organization\PositionReportService;

/** [285] Raport stanowisk (makieta 39) : minutes des tâches, remplissage jour / semaine / mois, fait / dû — administrateur. */
final class PositionReportController extends BaseController
{
    public function __construct(private PositionReportService $report)
    {
    }

    /** GET /organization/position-report */
    public function index()
    {
        try {
            $r = $this->report->report(user('id') ? (int) user('id') : null);
        } catch (CustomException $e) {
            http_response_code((int) $e->getCode() ?: 403);
            $this->view('organization/forbidden', ['page_label' => 'Raport stanowisk', 'admin_only' => true]);
            return;
        }
        $this->view('organization/position_report', ['r' => $r]);
    }
}
