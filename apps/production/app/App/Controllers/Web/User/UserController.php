<?php

namespace App\Controllers\Web\User;

use App\Core\Web\BaseController;
use App\Services\User\UserCreationService;
use App\Services\User\UserService;

class UserController extends BaseController
{
    private UserService $userService;

    public function __construct(
        UserService $userService,
        private UserCreationService $creation
    )
    {
        $this->userService = $userService;
    }

    public function overview()
    {
        $data['rows'] = $this->userService->getAll();
        $this->view("user/overview", $data);
    }

    public function newUser()
    {
        $actor = user('id') ? (int) user('id') : null;
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            // Wyświetlana nazwa domyślnie z imienia i nazwiska.
            if (trim((string) ($_POST['displayed_name'] ?? '')) === '') $_POST['displayed_name'] = trim(($_POST['name'] ?? '') . ' ' . ($_POST['surname'] ?? ''));
            $this->errors = $this->userService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($actor) {
                    // Kreator : konto, potem stanowisko, rola i strona startowa — i karta użytkownika (nie lista).
                    $fields = ['name', 'surname', 'displayed_name', 'email', 'phone', 'permission', 'login', 'password'];
                    $id = (int) $this->userService->insert(array_intersect_key($_POST, array_flip($fields)));
                    $_SESSION['user_created'] = ['id' => $id, 'problems' => $this->creation->complete($id, $_POST, $actor)];
                    header('Location: ' . ROOT . '/users/' . $id . '/edit');
                    exit;
                }, default: false);
            }
        }
        $this->view("user/new", $this->creation->choices($actor));
    }

    public function editUser($id)
    {
        $data['row'] = $this->userService->getById($id);
        if (($_SESSION['user_created']['id'] ?? null) === (int) $id) {
            $data['created'] = $_SESSION['user_created'];
            unset($_SESSION['user_created']);
        }

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->userService->validate($_POST, false, (int) $id);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id) {
                    $this->userService->update($id, $_POST);
                    header('Location: ' . ROOT . '/users');
                    exit;
                }, default: false);
            }
        }
        $this->view("user/edit", $data);
    }

    public function deleteUser($id)
    {
        $data['row'] = $this->userService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->userService->delete($id);
                header('Location: ' . ROOT . '/users');
                exit;
            }, default: false);
        }

        $this->view("user/delete", $data);
    }

}