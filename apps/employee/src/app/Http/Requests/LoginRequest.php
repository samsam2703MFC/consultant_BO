<?php

namespace App\Employee\app\Http\Requests;

class LoginRequest {

    public static function validateLogin($data) {
        $errors = [];

        if (empty($data['login'])) {
            $errors['login'] = 'Login is required';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        }

        return $errors;
    }

}