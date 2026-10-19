<?php
namespace App\Services\Auth;


use App\Repositories\User\UserRepository;

class LoginService
{
    private UserRepository $userRepository;

    public function __construct(
        UserRepository $userRepository
    )
    {
        $this->userRepository = $userRepository;
    }

    public function login($login, $password)
    {

        $row = $this->userRepository->first(['login' => $login]);

        if ($row) {
            if (hash_equals((string) $row['password'], (string) $password)) {
                $this->userRepository->authenticate($row);
                return true;
            }
        }

        return false;
    }

    public function logout()
    {
        return $this->userRepository->logout();
    }
}