<?php

use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Client\Order\OrderProductRepository;
use App\Repositories\Document\Difference\MissingProductsInOrderRepository;
use App\Repositories\Product\FinalProductRepository;

/**
 * order class
 */
class CustomerOrder
{
    use Controller;

    public function order_difference()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $missingProductsInOrderModel = new MissingProductsInOrderRepository();
        $missingProductsInOrderModel->getPDF([]);

        //$data['action'] = '';
        //$this->view('customer_orders', $data);
    }

    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $resp = false;
        if($target == "status" && $action == "update"){
            $customerOrderModel = new OrderRepository();

            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $customerOrderModel->update($data['id_order'], ["status" => 3]);
            $resp = true;
        }

        echo json_encode($resp);
    }

}
