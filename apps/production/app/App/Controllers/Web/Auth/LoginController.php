<?php
namespace App\Controllers\Web\Auth;

use App\Core\Web\BaseController;
use App\Services\Auth\LoginService;

/**
 * login class
 */
class LoginController extends BaseController
{

    private LoginService $loginService;
    public function __construct(LoginService $loginService)
    {
        $this->loginService = $loginService;
    }

    public function index()
    {
        $this->view('auth/login', []);
    }

    public function login()
    {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if($login === '' || $password === '' || !$this->loginService->login($login, $password)) {
            $this->errors[] = "Nieprawidłowy login lub hasło.";
            $this->view('auth/login', []);
        } else {
            header('Location: ' . ROOT . '/dashboard');
        }
    }

}
