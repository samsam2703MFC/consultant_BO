<?php

namespace App\Employee\app\Http\Controllers\Me;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Employee\EmployeeService;
use App\Employee\app\Services\Shop\ShopService;
use App\Employee\core\Support\Route;

class ProfileController extends Controller
{

    public function __construct(
        private EmployeeService $employeeService,
        private ShopService $shopService
    ) {}

    #[Route('GET', '/me')]
    public function index()
    {
        $data['employee'] = $this->employeeService->getMe(['positions', 'competencies']);
        $data['shop'] = $this->shopService->getMe();

        $this->view("me/profile", $data);
    }
}