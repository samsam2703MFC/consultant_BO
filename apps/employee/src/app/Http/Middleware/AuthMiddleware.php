<?php
namespace App\Employee\app\Http\Middleware;

use App\Employee\app\Services\Auth\AuthService;
use DateTime;

class AuthMiddleware {
    private $authService;

    public function __construct(
        AuthService $authService) {
        $this->authService = $authService;
    }

    public function handle()
    {
        if ($this->authService->ensureValidSession()) {
            return;
        }

        $this->authService->logout();
        redirect("auth");
        exit;
    }


}