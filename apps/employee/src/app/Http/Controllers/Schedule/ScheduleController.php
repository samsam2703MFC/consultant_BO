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

    /** Le planning, semaine par semaine (24/10/2026) : « ?semaine=AAAA-MM-JJ » (un jour de la semaine) ; « ?date_from= », l'ancien lien, y mène aussi. */
    #[Route('GET', '/schedule')]
    public function index()
    {
        $jour = $_GET['semaine'] ?? ($_GET['date_from'] ?? null);
        $data['semaine'] = $this->scheduleService->semaine(is_string($jour) ? $jour : null);

        $this->view("schedule/schedule", $data);
    }
}
