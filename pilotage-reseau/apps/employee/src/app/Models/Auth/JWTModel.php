<?php

namespace App\Employee\app\Models\Auth;

class JWTModel{
    private $token;
    private $refresh_token;

    public function __construct($data)
    {
        $this->token = $data['token'];
        $this->refresh_token = $data['refresh_token'] ?? null;
    }

    public function getToken() {
        return $this->token;
    }

    public function getRefreshToken() {
        return $this->refresh_token;
    }


}