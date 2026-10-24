<?php
namespace App\Services\User;

use App\Core\Security\Passwords;
use App\Repositories\User\UserRepository;

class UserService
{
    private UserRepository $userRepository;
    
    public function __construct(
        UserRepository $userRepository
    )
    {
        $this->userRepository = $userRepository;
    }

    /** $passwordRequired = false przy edycji: puste hasło = bez zmiany. */
    public function validate($data, bool $passwordRequired = true)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź imię";
        }
        if(empty($data['surname']))
        {
            $errors['surname'] = "Wprowadź nazwisko";
        }
        if(empty($data['displayed_name']))
        {
            $errors['displayed_name'] = "Wprowadź nazwę wyświetlaną";
        }
        if(empty($data['email']))
        {
            $errors['email'] = "Email jest wymagany";
        }else
            if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))
            {
                $errors['email'] = "Email jest nieprawidłowy";
            }
        if(empty($data['phone']))
        {
            $errors['phone'] = "Wprowadź telefon kontaktowy";
        }
        if(empty($data['login']))
        {
            $errors['login'] = "Login jest wymagany";
        }
        if($passwordRequired && empty($data['password']))
        {
            $errors['password'] = "Hasło jest wymagane";
        }
        if(empty($data['permission']) || $data['permission'] === "-1")
        {
            $errors['permission'] = "Wybierz uprawnienia";
        }

        return $errors;
    }

    public function getAll()
    {
        return $this->userRepository->findAll();
    }

    public function getById($id)
    {
        return $this->userRepository->first(['id' => $id]);
    }

    public function insert($data)
    {
        return $this->userRepository->insert($this->hashPassword($data));
    }

    public function update($id, $data)
    {
        // Puste pole hasła w edycji: obecne hasło zostaje.
        if (empty($data['password'])) {
            unset($data['password']);
        }
        return $this->userRepository->update($id, $this->hashPassword($data));
    }

    /** Mot de passe saisi → bcrypt (une valeur déjà chiffrée n'est jamais chiffrée deux fois). */
    private function hashPassword(array $data): array
    {
        if (isset($data['password']) && $data['password'] !== '' && !Passwords::isHash((string) $data['password'])) {
            $data['password'] = Passwords::hash((string) $data['password']);
        }
        return $data;
    }

    public function delete($id)
    {
        return $this->userRepository->delete($id);
    }
}