<?php

use App\Repositories\Client\Order\OrderRepository;

/**
 * product class
 */
class Proforma
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $this->view('document');
    }



    public function getByIdOut($id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $proformaModel = new ProformaModel();
        $customerOrderModel = new OrderRepository();
        $data = [];

        $data['proforma'] = $proformaModel->getFromApi(['id_proforma' => $id]);
        $data['order_data'] = $customerOrderModel->getByIdFranchiseeCollector(['id_franchisee_collector' => $id]);

        $this->view("proforma", $data);
    }

    public function getById($id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $proformaModel = new ProformaModel();
        $proformaModel->getProformaPDF(['id_saved_proforma' => $id]);
    }



    public function genProforma($id_order = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $proformaModel = new ProformaModel();
        $proformaModel->genProforma(["id_order" => $id_order]);

        redirect('CustomerOrder/orders');
    }


}
