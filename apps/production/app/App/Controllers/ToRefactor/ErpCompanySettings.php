<?php

use App\Repositories\Company\ErpCompanyOtherSettingsRepository;

/**
 * home class
 */
class ErpCompanySettings
{
    use Controller;

    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        if(!isset($_POST) || empty($_POST)) exit();

        if($target == "settings" && $action == "update"){
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $erpCompanyOtherSettingsModel = new ErpCompanyOtherSettingsRepository();
            $erpCompanyOtherSettingsModel->update(1, $data);
            $resp = true;
        }

        echo json_encode($resp);
    }

}
