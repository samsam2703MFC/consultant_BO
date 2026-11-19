<?php
namespace App\Services\Auth;


use App\Core\Security\Passwords;
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
            if (Passwords::matches((string) $row['password'], (string) $password)) {
                // Compte historique en clair (ou ancien coût) : rechiffré dès la connexion réussie.
                if (Passwords::needsRehash((string) $row['password'])) {
                    $this->userRepository->update($row['id'], ['password' => Passwords::hash((string) $password)]);
                }
                $this->userRepository->authenticate($row);
                return true;
            }
        }

        return false;
    }

    /** Logowanie bez hasła — tylko gdy ENTREE_LIBRE jest ustawione (serwer testowy). */
    public function loginWithoutPassword(string $login): bool
    {
        $row = $this->userRepository->first(['login' => $login]);

        if ($row) {
            $this->userRepository->authenticate($row);
            return true;
        }

        return false;
    }

    /** [300] Compte ouvert par le QR de connexion (validé sur le téléphone de la personne liée). */
    public function loginUser(array $row): void
    {
        $this->userRepository->authenticate($row);
    }

    public function logout()
    {
        return $this->userRepository->logout();
    }
}