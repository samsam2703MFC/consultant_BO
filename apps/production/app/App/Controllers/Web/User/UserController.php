<?php

namespace App\Controllers\Web\User;

use App\Core\Web\BaseController;
use App\Services\User\UserService;

class UserController extends BaseController
{
    private UserService $userService;

    public function __construct(
        UserService $userService
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
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->userService->validate($_POST);

            if (empty($this->errors)) {
                $this->handleAction(function () {
                    $this->userService->insert($_POST);
                    header('Location: ' . ROOT . '/users');
                    exit;
                }, default: false);
            }
        }
        $this->view("user/new");
    }

    public function editUser($id)
    {
        $data['row'] = $this->userService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->errors = $this->userService->validate($_POST);

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