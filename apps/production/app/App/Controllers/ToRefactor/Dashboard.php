<?php

/**
 * home class
 */
class Dashboard
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $this->view('dashboard');
    }

}
