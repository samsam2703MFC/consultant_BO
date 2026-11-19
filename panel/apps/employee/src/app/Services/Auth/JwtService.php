<?php
namespace App\Employee\app\Services\Auth;

use App\Employee\core\Cookie\CookieManager;
use App\Employee\app\Models\Me\LoggedUserModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
class JwtService {

    public function getTokenData($token){
        list($header, $token_payload, $signature) = explode('.', $token);
        $token_payload_decoded = json_decode($this->base64UrlDecode($token_payload), true);
        $token_payload_decoded['id_owner'] = $token_payload_decoded['sub'];
        $token_payload_decoded['owner_id_shop'] = $token_payload_decoded['id_shop'] ?? null;
        $token_payload_decoded['expiry_date'] = $this->getTokenExpireDate($token_payload_decoded['exp']);

        $loggedUserObj = new LoggedUserModel($token_payload_decoded);

        return [
            'logged_user' => $loggedUserObj,
            'expiry_token_date' => $token_payload_decoded['expiry_date']
        ];
    }


    public function getExpiryTokenDate($token)
    {
        $decoded_token = $this->getTokenData($token);
        return $decoded_token['expiry_token_date'];
    }


    public function getExpiryRefreshTokenDate($token)
    {
        list($header, $refresh_token_payload, $signature) = explode('.', $token);
        $refresh_token_payload_decoded = json_decode($this->base64UrlDecode($refresh_token_payload), true);

        $refresh_token_payload_decoded['expiry_date'] = $this->getTokenExpireDate($refresh_token_payload_decoded['exp']);

        return $refresh_token_payload_decoded['expiry_date'];
    }

    public function getLoggedUserObj()
    {
        $cookieManager = new CookieManager();
        $token = $cookieManager->getAccessToken();
        if(is_null($token)) return false;

        $token_data = $this->getTokenData($token);

        return $token_data['logged_user'];
    }

    private function base64UrlDecode($data) {
        // Zamień Base64 URL-safe na standardowy Base64
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        // Dodaj padding do danych, jeśli jest potrzebny
        $data = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($data);
    }

    public function getTokenExpireDate($unix_timestamp)
    {
        if(is_null($unix_timestamp)) return date('Y-m-d H:i:s');
        return date('Y-m-d H:i:s', $unix_timestamp);
    }
}