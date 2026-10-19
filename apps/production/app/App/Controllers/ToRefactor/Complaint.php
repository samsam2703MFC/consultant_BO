<?php

use App\Repositories\Client\Complaint\ComplaintReasonRepository;
use App\Repositories\Client\Complaint\FranchiseeFeedbackRepository;
use App\Repositories\Complaint\ComplaintCompensationMethodRepository;
use App\Repositories\Complaint\ComplaintRepository;
use App\Repositories\Complaint\Product\ComplaintProductPendingRepository;
use App\Repositories\Complaint\Product\ComplaintProductToSendLogRepository;
use App\Repositories\Shipping\Complaint\ComplaintShipmentLogRepository;

/**
 * Invoice class
 */
class Complaint
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $this->complaints();
    }

    public function complaints($action = null, $id = null)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $data['action'] = $action;
        $complaintModel = new ComplaintRepository();
        $complaintReasonModel = new ComplaintReasonRepository();
        $complaintCompenstaionMethodModel = new ComplaintCompensationMethodRepository();

        $data['complaint_reason'] = $complaintReasonModel->findAll();
        $data['complaint_compensation_method'] = $complaintCompenstaionMethodModel->findAll();

        if ($action == "new") {

        } elseif ($action == "new_from_note") {
            $franchiseeFeedbackModel = new FranchiseeFeedbackRepository();
            $complaintProductPendingModel = new ComplaintProductPendingRepository();
            $data['order_note'] = $franchiseeFeedbackModel->getById(['id' => $id]);
            if ($_SERVER['REQUEST_METHOD'] == "POST") {

                if ($complaintModel->validateInsert($_POST)) {
                    $_POST['id_customer'] = $data['order_note']->id_customer;
                    $_POST['invoice_num'] = $data['order_note']->invoice_num;
                    $_POST['id_product'] = $data['order_note']->id_product;
                    $_POST['product_name'] = $data['order_note']->product_name;

                    $complaintModel->insert($_POST);
                    $franchiseeFeedbackModel->update($id, ['id_order_note_status' => 3]);
                    $franchiseeFeedbackModel->updateFranchisee(["id" => $id]);
                    $complaintProductPendingModel->insert([
                        "id_customer" => $_POST['id_customer'],
                        "id_final_product" => $_POST['id_product'],
                        "pending_product_qty" => $_POST['product_quantity']]);

                    redirect("Franchisee/feedback/new");
                }
            }
        }

        $data['errors'] = $complaintModel->errors;
        $this->view('complaint', $data);
    }




    public function documents($action = null, $id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');


//        $helperModel = new HelperModel();
//        $helperModel->getData();


        $complaintShipmentLogModel = new ComplaintShipmentLogRepository();
        $data['action'] = $action;

        if($action == "view"){
            if($id != null){
                $complaintShipmentLogModel->getShippingDocsInOneFile(['id_shipment_log' => $id]);
            }
        }else{

        }

        $data['errors'] = $complaintShipmentLogModel->errors;
        $this->view('complaint', $data);
    }

}
