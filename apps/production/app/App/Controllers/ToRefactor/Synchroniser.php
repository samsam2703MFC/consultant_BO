<?php

/**
 * home class
 */
class Synchroniser
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $this->view('synchronizer');
    }

}
