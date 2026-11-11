<?php
namespace App\Employee\app\Repositories\Auth;


use App\Employee\app\Models\Auth\JWTModel;
use App\Employee\core\Http\ApiClient;

class LoginRepository{
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function login($data)
    {
        $response = $this->apiClient->login("/employees/authenticate", $data);
        if (isset($response['token']) && isset($response['refresh_token'])) {
            return new JWTModel($response);
        }
        return null;
    }

    public function refresh($refreshToken) {
        $response = $this->apiClient->login('/employees/refresh', ['refresh_token' => $refreshToken]);

        if (isset($response['token']) && isset($response['refresh_token'])) {
            return new JWTModel($response);
        }
        return null;
    }
}