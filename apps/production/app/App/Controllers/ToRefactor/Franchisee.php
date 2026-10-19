<?php

use App\Repositories\Client\Complaint\FranchiseeFeedbackRepository;

/**
 * home class
 */
class Franchisee
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $this->view('employees');
    }

    public function feedback($action = null, $id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $franchiseeFeedbackModel = new FranchiseeFeedbackRepository();

        $data['action'] = $action;

        $franchiseeFeedbackModel->getNew();

        if($action == "new"){

            $data['status'] = $franchiseeFeedbackModel->getStatusName(['id_status' => 1]);
            $data['order_notes'] = $franchiseeFeedbackModel->getOwnLogByStatus(['id_status' => 1]);

        } elseif($action == "progress"){

            $data['status'] = $franchiseeFeedbackModel->getStatusName(['id_status' => 2]);
            $data['order_notes'] = $franchiseeFeedbackModel->getOwnLogByStatus(['id_status' => 2]);

        } elseif($action == "close"){

            $data['status'] = $franchiseeFeedbackModel->getStatusName(['id_status' => 3]);
            $data['order_notes'] = $franchiseeFeedbackModel->getOwnLogByStatus(['id_status' => 3]);

        } elseif($action == "response"){

            $data['order_note'] = $franchiseeFeedbackModel->getById(['id' => $id]);
            if($_SERVER['REQUEST_METHOD'] == "POST") {
                $franchiseeFeedbackModel->update($id, $_POST);
                $franchiseeFeedbackModel->updateFranchisee(['id' => $id]);
                redirect('Franchisee/feedback/progress');
            }
        } elseif($action == "closed_response"){

            $data['order_note'] = $franchiseeFeedbackModel->getById(['id' => $id]);
            if($_SERVER['REQUEST_METHOD'] == "POST") {
                $franchiseeFeedbackModel->update($id, $_POST);
                $franchiseeFeedbackModel->updateFranchisee(['id' => $id]);
                redirect('Franchisee/feedback/close');
            }
        } elseif($action == "pdf"){

            $franchiseeFeedbackModel->getPDF(['id' => $id]);

        } elseif($action == "correction_invoice"){

            //$franchiseeFeedbackModel->correction(['id' => $id]);

        } elseif($action == "edit"){

            $data['order_note'] = $franchiseeFeedbackModel->getById(['id' => $id]);

            if($_SERVER['REQUEST_METHOD'] == "POST") {
                $franchiseeFeedbackModel->update($id, $_POST);
                redirect('Franchisee/feedback/progress');
            }

        } else {
            $data['order_notes'] = $franchiseeFeedbackModel->getOwnLog();
        }


        $data['errors'] = $franchiseeFeedbackModel->errors;

        $this->view("franchisee_feedback", $data);
    }

    public function feedback_photo($id=null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        //$data['errors'] = $franchiseeFeedbackModel->errors;
        $data['errors'] = [];

        $this->view("franchisee_feedback", $data);
    }

    public function ajax($target = null, $action = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $resp = false;

        if($target == "note_status" && $action == "update"){
            $franchiseeFeedbackModel  = new FranchiseeFeedbackRepository();

            $data = json_decode(json_encode(json_decode($_POST['data'])), true);
            $franchiseeFeedbackModel->update($data['id'], $data);
            $franchiseeFeedbackModel->updateFranchisee(['id' => $data['id']]);
            $resp = true;
        }

        echo json_encode($resp);
    }

}
