<?php

namespace App\Controllers\Web\Settings;

use App\Core\Route;
use App\Core\Web\BaseController;
use Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * product class
 */
class SettingsController extends BaseController
{


    public function __construct(

    )
    {
    }

    public function index()
    {
        $this->view('settings/settings', []);
    }


}
