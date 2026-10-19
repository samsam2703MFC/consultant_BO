<?php

use App\Repositories\Complaint\ComplaintRepository;
use App\Repositories\Product\Category\CategoryProductRepository;
use App\Repositories\Shipping\Carrier\ProductCarrierVehicleRepository;
use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\PaletDeliveryRepository;
use App\Repositories\Shipping\Utils\MissingProductReasonRepository;
use App\Repositories\Stock\Complaint\CartonStockComplaintRepository;

/**
 * product class
 */
class Shipping
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $categoryProductModel = new CategoryProductRepository();
        $data['action'] = '';
        $data['rows'] = $categoryProductModel->getExistedCategoryInProductList();
        $this->view('document', $data);
    }

    public function document($action = null, $id = null)
    {

            if($_SERVER['REQUEST_METHOD'] == "POST") {

                if(isset($_POST['shipping'])){
                    $res = $shipmentLogModel->newShipping($_POST);

                    if($res['success']){
                        redirect('Shipping/generated/view/'.$res['data']['shipment_log']);
                    }
                } else if(isset($_POST['complaint'])){
                    $res = $complaintModel->newComplaint($_POST);

                    if($res['success']){
                        redirect('Shipping/generated/view_complaint/'.$res['data']['shipment_log']);
                    }
                } else {
                    $shipmentLogModel->errors = ["error" => "Wystąpił błąd"];
                }

            }


        $data['errors'] = $shipmentLogModel->errors;
        $this->view('shipping', $data);
    }

    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $data['success'] = false;
        $data['data'] = [];

        if($target == "customer" && $action == "get"){
//            $paletsDeliveryModel = new PaletDeliveryRepository();
//            $resp = $paletsDeliveryModel->getCustomerByDeliveryDate(['delivery_date' => decoded_ajax_json($_POST['data'])['value']]);
//            if(!$resp) return $data;
//
//            $data['data'] = $resp;
//            $data['success'] = true;

        }else if($target == "complaint_customer" && $action == "get"){
            $cartonStockComplaintModel = new CartonStockComplaintRepository();
            $resp = $cartonStockComplaintModel->getCustomerByShippingDate(['shipping_date' => decoded_ajax_json($_POST['data'])['value']]);
            if(!$resp) return $data;

            $data['data'] = $resp;
            $data['success'] = true;
        }else if($target == "vehicle" && $action == "get"){
//            $carrierVehicleModel = new CarrierVehicleRepository();
//            $resp = $carrierVehicleModel->findByIdCarrier(['id_carrier' => decoded_ajax_json($_POST['data'])['value']]);
//            if(!$resp) return $data;
//
//            $data['data'] = $resp;
//            $data['success'] = true;
        }else if($target == "wdt_price" && $action == "update"){
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);

            $wdtProductModel = new WdtProductModel();
            $orderShippingDifferenceProductModel = new OrderShippingDifferenceProductRepository();
            $shipmentLogModel = new ShipmentLogModel();
            $shipment = $shipmentLogModel->where(["id_wdt" => $data['id_document_wdt']])[0];

            $resp = $wdtProductModel->updatePrice($data);
            $orderShippingDifferenceProductModel->updateInvoicedPrice(['id_diff' => $shipment->id_diff, "price_carton" => $data['price_carton'], "id_product" => $data['id_product']]);

            $data['data'] = $resp;
            $data['success'] = true;
        }else if($target == "wdt" && $action == "send_to_franchisee"){
            $data = json_decode(json_encode(json_decode($_POST['data'])), true);

            $shipmentLogModel = new ShipmentLogModel();
            $resp = $shipmentLogModel->sendToFranchisee($data['id_wdt']);
            $data['success'] = $resp;
        }else if($target == "diff" && $action == "update"){
            $orderShippingDifferenceProductModel = new OrderShippingDifferenceProductRepository();

            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $resp = $orderShippingDifferenceProductModel->updateDiffReason([
                "difference_reason" => $data['value'],
                "id_doc" => $data['id_doc'],
                "id_product" => $data['id_product']
            ]);

            $data['data'] = $resp;
            $data['success'] = true;
        }


        echo json_encode($data);
    }
}
