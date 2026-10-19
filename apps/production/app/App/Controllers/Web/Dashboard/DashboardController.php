<?php
namespace App\Controllers\Web\Dashboard;

use App\Core\Web\BaseController;
use App\Services\Dashboard\TodayService;

class DashboardController extends BaseController
{
    public function __construct(private TodayService $today) {}

    public function index()
    {
        $this->view('dashboard/dashboard', $this->today->overview());
    }
}
