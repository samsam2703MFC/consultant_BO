<?php

namespace App\Employee\app\Http\Controllers\Dashboard;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\core\Support\Route;

class DashboardController extends Controller
{

    public function __construct(

    ) {}

    #[Route('GET', '/dashboard')]
    public function index()
    {

        $this->view("dashboard/dashboard");
    }
}