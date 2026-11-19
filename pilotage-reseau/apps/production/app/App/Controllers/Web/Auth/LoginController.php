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

    /** [300] Écran de droite : sections → personnes (compte lié). */
    private function qrSections(): array
    {
        try { return $this->qrLogin->sections(); } catch (\Throwable $e) { error_log('[login-qr] ' . $e->getMessage()); return []; }
    }

    /** GET /ajax/login-qr?base=… : kod dla tej przeglądarki (demande gardée dans sa session). */
    public function qr()
    {
        try {
            $for = ctype_digit((string) ($_GET['employee'] ?? '')) ? (int) $_GET['employee'] : null;
            $r = $this->qrLogin->qr($_SESSION['ERP_LOGIN_QR'] ?? null, (string) ($_GET['base'] ?? ''), $_SERVER['HTTP_USER_AGENT'] ?? null, $for);
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
            $to = $this->afterLogin();
            $this->loginService->loginUser($user);
            return $this->json(['data' => ['done' => true, 'redirect' => $to]]);
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
        $qr = $this->qrEnabled();
        $this->view('auth/login', ['entreeLibre' => $this->entreeLibre() !== '', 'qrLogin' => $qr, 'qrSections' => $qr ? $this->qrSections() : []]);
    }

    public function login()
    {
        $libre = $this->entreeLibre();
        $to = $this->afterLogin();
        if ($libre !== '') {
            if ($this->loginService->loginWithoutPassword($libre)) {
                header('Location: ' . $to);
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
            $qr = $this->qrEnabled();
            $this->view('auth/login', ['qrLogin' => $qr, 'qrSections' => $qr ? $this->qrSections() : [], 'showPassword' => true]);
        } else {
            header('Location: ' . $to);
        }
    }

    /** [354] Strona, na którą wraca się po zalogowaniu (otwarta bez sesji, np. kod QR) — inaczej /home. Raz. */
    private function afterLogin(): string
    {
        $to = (string) ($_SESSION['AFTER_LOGIN'] ?? '');
        unset($_SESSION['AFTER_LOGIN']);
        return preg_match('~^/(?!/)[A-Za-z0-9/_\-.%?=&]*$~', $to) ? ROOT . $to : ROOT . '/home';
    }

}
