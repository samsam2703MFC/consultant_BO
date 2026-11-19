<?php

namespace App\Employee\app\Http\Controllers\KnowledgeBase;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Schedule\ScheduleService;
use App\Employee\core\Support\Route;

class ResourceController extends Controller
{

    public function __construct(
        private KnowledgeBaseResourceService $knowledgeBaseResourceService
    ) {}

    #[Route('GET', '/knowledge-base/resources')]
    public function index()
    {
        $data['resources'] = $this->knowledgeBaseResourceService->getAll();

        $this->view("knowledge_base/resource_overview", $data);
    }
}