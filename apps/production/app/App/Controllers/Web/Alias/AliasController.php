<?php


namespace App\Controllers\Web\Alias;

use App\Core\Route;
use App\Core\Web\AbstractAliasController;
use App\Core\Web\BaseController;
use Symfony\Component\HttpFoundation\Request;

class AliasController extends BaseController
{

    public function index()
    {
        $this->view('alias/index', []);
    }

}