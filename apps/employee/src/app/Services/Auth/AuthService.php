<?php
namespace App\Employee\app\Services\Auth;


use App\Employee\core\Cookie\CookieManager;
use App\Employee\app\Models\Auth\JWTModel;
use App\Employee\app\Repositories\Auth\LoginRepository;
use App\Employee\app\Services\Auth\JwtService;
use DateTime;

class AuthService {
    private $loginRepository;
    private $cookieManager;
    private $jwtService;

    public function __construct(
        LoginRepository $loginRepository,
        CookieManager $cookieManager,
        JwtService $jwtService) {
        $this->loginRepository = $loginRepository;
        $this->cookieManager = $cookieManager;
        $this->jwtService = $jwtService;
    }

    public function login($data) {
        $jwtObj = $this->loginRepository->login($data);
        if(is_null($jwtObj)) return false;

        return $this->setCookiesProcedure($jwtObj);
    }

    private function setCookiesProcedure(JWTModel $jwtObj){

        $expiryTokenDate = $this->jwtService->getExpiryTokenDate($jwtObj->getToken());
        $expiryRefreshTokenDate = $this->jwtService->getExpiryRefreshTokenDate($jwtObj->getRefreshToken());

        if(!$this->cookieManager->setAuthCookie($jwtObj, $expiryTokenDate)) return false;
        if(!$this->cookieManager->setRefreshCookie($jwtObj, $expiryRefreshTokenDate)) return false;

        return true;
    }

    public function logout() {
        $this->cookieManager->unsetCookies();
    }

    public function refreshTokens($refreshToken){
        $jwtObj = $this->loginRepository->refresh($refreshToken);

        if(!$jwtObj) return false;

        return $this->setCookiesProcedure($jwtObj);
    }

    public function isAuthenticated(): bool
    {
        $accessExpiry = $this->cookieManager->getAccessTokenExpiryTime();
        $refreshExpiry = $this->cookieManager->getRefreshTokenExpiryTime();

        if (!$accessExpiry || !$refreshExpiry) {
            return false;
        }

        $now = new DateTime();

        // access token nadal ważny
        if (new DateTime($accessExpiry) > $now) {
            return true;
        }

        // access wygasł, ale refresh nadal ważny
        if (new DateTime($refreshExpiry) > $now) {
            return true;
        }

        return false;
    }

    private function hasValidAccessToken(): bool
    {
        $expiry = $this->cookieManager->getAccessTokenExpiryTime();
        return $expiry && new DateTime($expiry) > new DateTime();
    }

    private function canRefreshToken(): bool
    {
        $expiry = $this->cookieManager->getRefreshTokenExpiryTime();
        return $expiry && new DateTime($expiry) > new DateTime();
    }

    public function ensureValidSession(): bool
    {
        if ($this->hasValidAccessToken()) {
            return true;
        }

        if ($this->canRefreshToken()) {
            return $this->refreshTokens($this->cookieManager->getRefreshToken());
        }

        return false;
    }
}