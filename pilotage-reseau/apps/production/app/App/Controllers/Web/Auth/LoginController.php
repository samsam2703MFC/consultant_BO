<?php
namespace App\Controllers\Web\Auth;

use App\Core\Web\BaseController;
use App\Services\Auth\LoginService;
use App\Services\Organization\ErpLoginService;

/**
 * login class
 */
class LoginController extends BaseController
{

    private LoginService $loginService;
    public function __construct(LoginService $loginService, private ErpLoginService $qrLogin)
    {
        $this->loginService = $loginService;
    }

    /** [300] Logowanie kodem QR dostępne (nie na serwerze testowym bez hasła). */
    private function qrEnabled(): bool
    {
        if ($this->entreeLibre() !== '') return false;
        try { return $this->qrLogin->enabled(); } catch (\Throwable $e) { return false; }
    }

    /** GET /ajax/login-qr?base=… : kod dla tej przeglądarki (demande gardée dans sa session). */
    public function qr()
    {
        try {
            $r = $this->qrLogin->qr($_SESSION['ERP_LOGIN_QR'] ?? null, (string) ($_GET['base'] ?? ''), $_SERVER['HTTP_USER_AGENT'] ?? null);
            $_SESSION['ERP_LOGIN_QR'] = $r['request'];
            return $this->json(['data' => $r['qr']]);
        } catch (\Throwable $e) {
            error_log('[login-qr] ' . $e->getMessage());
            return $this->json(['message' => 'Kod QR niedostępny — zaloguj się hasłem.'], 503);
        }
    }

    /** GET /ajax/login-qr/status : zatwierdzone telefonem → sesja konta otwarta tutaj (raz). */
    public function qrStatus()
    {
        try {
            $user = $this->qrLogin->poll($_SESSION['ERP_LOGIN_QR'] ?? null);
            if ($user === null) return $this->json(['data' => ['done' => false]]);
            unset($_SESSION['ERP_LOGIN_QR']);
            $this->loginService->loginUser($user);
            return $this->json(['data' => ['done' => true, 'redirect' => ROOT . '/home']]);
        } catch (\Throwable $e) {
            error_log('[login-qr] ' . $e->getMessage());
            return $this->json(['data' => ['done' => false]]);
        }
    }

    /** Konto otwierane bez hasła (ENTREE_LIBRE w .env — tylko serwer testowy); pusty = zwykłe logowanie */
    private function entreeLibre(): string
    {
        return trim((string) ($_ENV['ENTREE_LIBRE'] ?? getenv('ENTREE_LIBRE') ?: ''));
    }

    public function index()
    {
        $this->view('auth/login', ['entreeLibre' => $this->entreeLibre() !== '', 'qrLogin' => $this->qrEnabled()]);
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
            $this->view('auth/login', ['qrLogin' => $this->qrEnabled(), 'showPassword' => true]);
        } else {
            header('Location: ' . ROOT . '/home');
        }
    }

}
