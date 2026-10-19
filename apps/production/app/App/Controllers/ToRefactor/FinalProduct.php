<?php

use App\Repositories\Client\ClientRepository;

/**
 * home class
 */
class FinalProduct
{
    use Controller;


    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $resp = false;
        if ($target == "price" && $action == "update") {
            $finalProductCustomerPriceModel = new FinalProductCustomerPriceRepository();

            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $finalProductCustomerPriceModel->updatePrice($data);
            $resp = true;
        }

        if ($target == "franchisee" && $action == "update") {
            $finalProductCustomerPriceModel = new FinalProductCustomerPriceRepository();

            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $finalProductCustomerPriceModel->updatePrice($data);
            $resp = true;
        }

        if ($target == "franchisee" && $action == "price_list") {
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $priceListModel = new PriceListRepository();
            $resp = $priceListModel->checkProductQty($data);
        }

        if ($target == "marge" && $action == "get") {
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $margeModel = new MargeRepository();
            $resp = $margeModel->getProductMargeByCategoryAndCustomer($data);
        }

        if ($target == "final_product" && $action == "update") {
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $finalProductModel = new FinalProductRepository();
            $finalProductModel->update($data['id'], $data);
            $resp = true;
        }

        if ($target == "final_product_price" && $action == "update") {
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);

            if ($data['id_customer'] == -1) {
                $finalProductModel = new FinalProductRepository();
                $finalProductModel->update($data['id'], [$data['param'] => $data['value']]);
                $resp = true;
            } else {
                $finalProductCustomerPriceModel = new FinalProductCustomerPriceRepository();
                $finalProductCustomerPriceModel->updatePrice([
                    'id_customer' => $data['id_customer'],
                    'price' => $data['price'],
                    'available' => 1,
                    'id_final_product' => $data['id']
                ]);
                $resp = true;
            }
        }

        echo json_encode($resp);
    }




    public function synchronizeFranchisee($id)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $finalProductCustomerPriceModel = new FinalProductCustomerPriceRepository();

        $finalProductCustomerPriceModel->synchronizeProductPriceInFranchisee(['id_customer' => $id]);

    }

    public function marge($action = null, $id = null)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $categoryProductModel = new CategoryProductRepository();
        $customerModel = new ClientRepository();
        $erpCompanyOtherSettingsModel = new ErpCompanyOtherSettingsRepository();

        $data['action'] = $action;
        $data['categories'] = $categoryProductModel->getExistedCategoryInProductList();
        $customerModel->order_column = "company";
        $data['customers'] = $customerModel->getActive();
        $data['exchange_rate_euro'] = $erpCompanyOtherSettingsModel->findAll()[0];


        $this->view("product_marge", $data);
    }


}
