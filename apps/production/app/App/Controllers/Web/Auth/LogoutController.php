<?php

namespace App\Controllers\Web\Auth;


use App\Core\Web\BaseController;
use App\Services\Auth\LoginService;

/**
 * logout class
 */
class LogoutController extends BaseController
{
    private LoginService $loginService;
    public function __construct(LoginService $loginService)
    {
        $this->loginService = $loginService;
    }


    public function logout()
    {
        $this->loginService->logout();
        header('Location: ' . ROOT . '/');
    }

}
