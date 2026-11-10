<?php

namespace App\Employee\app\Http\Controllers\KnowledgeBase;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Schedule\ScheduleService;
use App\Employee\core\Support\Route;

class OperationalProcedureController extends Controller
{

    public function __construct(
        private OperationalProcedureService $operationalProcedureService
    ) {}

    #[Route('GET', '/knowledge-base/operational-procedures')]
    public function index()
    {
        $data['procedures'] = $this->operationalProcedureService->getAll();

        $this->view("knowledge_base/operational_procedure_overview", $data);
    }
}