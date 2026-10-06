<?php
namespace App\Employee\core\Cookie;

use App\Employee\app\Models\Auth\JWTModel;

class CookieManager {


    public function setAuthCookie(JWTModel $jwtObj, $expiry_token_date)
    {

        $res_access = setcookie('employee_access_token', $jwtObj->getToken(), [
            'expires' => strtotime($expiry_token_date), // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if(!$res_access) return false;

        $res_expiry = setcookie('employee_access_token_expiry', $expiry_token_date, [
            'expires' => strtotime($expiry_token_date), // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if(!$res_expiry) return false;

        return true;
    }

    public function setRefreshCookie(JWTModel $jwtObj, $expiry_token_date)
    {
        $res_access = setcookie('employee_refresh_token', $jwtObj->getRefreshToken(), [
            'expires' => strtotime($expiry_token_date), // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if(!$res_access) return false;

        $res_expiry = setcookie('employee_refresh_token_expiry', $expiry_token_date, [
            'expires' => strtotime($expiry_token_date), // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if(!$res_expiry) return false;

        return true;
    }

    public function unsetCookies()
    {
        setcookie('employee_refresh_token', '', [
            'expires' => time() - 3600, // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        setcookie('employee_refresh_token_expiry', '', [
            'expires' => time() - 3600, // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        setcookie('employee_access_token', '', [
            'expires' => time() - 3600, // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        setcookie('employee_access_token_expiry', '', [
            'expires' => time() - 3600, // Poprawiony timestamp
            'path' => (defined('EMPLOYEE_BASE_PATH') ? EMPLOYEE_BASE_PATH : '') . '/',
            'secure' => defined('IS_HTTPS') ? IS_HTTPS : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',]);
    }


    public function getAccessToken()
    {
        return $_COOKIE['employee_access_token'] ?? null;
    }

    public function getRefreshToken()
    {
        return $_COOKIE['employee_refresh_token'] ?? null;
    }

    public function getAccessTokenExpiryTime()
    {
        return $_COOKIE['employee_access_token_expiry'] ?? null;
    }

    public function getRefreshTokenExpiryTime()
    {
        return $_COOKIE['employee_refresh_token_expiry'] ?? null;
    }


}