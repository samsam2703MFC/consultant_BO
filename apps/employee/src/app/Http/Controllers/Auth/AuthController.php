<?php

namespace App\Employee\app\Http\Controllers\Auth;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Http\Requests\LoginRequest;
use App\Employee\app\Services\Auth\AuthService;
use App\Employee\core\Support\Route;

class AuthController extends Controller
{

    public function __construct(
        private AuthService $authService
    ) {}

    #[Route('GET', '/auth')]
    public function index() {

        if ($this->authService->isAuthenticated()) {
            redirect("/dashboard");
            return;
        }

        $this->view("auth/login");
    }

    #[Route('POST', '/auth')]
    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $this->errors = LoginRequest::validateLogin($_POST);
            if (!empty($this->errors)) {
                $this->view("auth/login");
            }

            $logged = $this->authService->login($_POST);

            if ($logged) {
                redirect("/dashboard");
            } else {
                $this->errors["invalid_credentials"] = "Invalid login or password.";
            }
        }

        $this->view("auth/login");
    }


    #[Route('GET', '/logout')]
    public function logout() {
        $this->authService->logout();
        redirect('/auth');
    }

}