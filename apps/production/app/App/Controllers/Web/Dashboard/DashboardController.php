<?php
namespace App\Controllers\Web\Dashboard;;

use App\Core\Web\BaseController;
use App\Services\Auth\LoginService;

/**
 * login class
 */
class DashboardController extends BaseController
{

    public function index()
    {
        $this->view('dashboard/dashboard', []);
    }


}
