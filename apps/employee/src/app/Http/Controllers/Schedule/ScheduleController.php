<?php

namespace App\Employee\app\Http\Controllers\Schedule;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Schedule\ScheduleService;
use App\Employee\core\Support\Route;

class ScheduleController extends Controller
{

    public function __construct(
        private ScheduleService $scheduleService,
    ) {}

    #[Route('GET', '/schedule')]
    public function index()
    {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
        $dateTo = $_GET['date_to'] ??  date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days'));
        $data['date_from'] = $dateFrom;
        $data['date_to'] = $dateTo;
        $data['schedule'] = $this->scheduleService->getForMe($dateFrom, $dateTo);

        $this->view("schedule/schedule", $data);
    }
}