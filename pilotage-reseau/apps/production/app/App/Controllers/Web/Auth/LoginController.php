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

    /** Konto otwierane bez hasła (ENTREE_LIBRE w .env — tylko serwer testowy); pusty = zwykłe logowanie */
    private function entreeLibre(): string
    {
        return trim((string) ($_ENV['ENTREE_LIBRE'] ?? getenv('ENTREE_LIBRE') ?: ''));
    }

    public function index()
    {
        $this->view('auth/login', ['entreeLibre' => $this->entreeLibre() !== '']);
    }

    public function login()
    {
        $libre = $this->entreeLibre();
        if ($libre !== '') {
            if ($this->loginService->loginWithoutPassword($libre)) {
                header('Location: ' . ROOT . '/home');
                return;
            }
            $this->errors[] = "Konto " . htmlspecialchars($libre) . " nie istnieje.";
            $this->view('auth/login', ['entreeLibre' => true]);
            return;
        }

        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if($login === '' || $password === '' || !$this->loginService->login($login, $password)) {
            $this->errors[] = "Nieprawidłowy login lub hasło.";
            $this->view('auth/login', []);
        } else {
            header('Location: ' . ROOT . '/home');
        }
    }

}
