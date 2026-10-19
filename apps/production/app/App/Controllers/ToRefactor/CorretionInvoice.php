<?php

use App\Repositories\Document\Invoice\InvoiceRepository;

/**
 * Invoice class
 */
class CorretionInvoice
{
    use Controller;


    public function invoices($action = null, $id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $data['action'] = $action;
        $invoiceModel = new InvoiceRepository();




        $data['errors'] = $invoiceModel->errors;
        $this->view('invoice', $data);
    }

    public function getInvoicePDF($id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $invoiceModel = new InvoiceRepository();
        $invoiceModel->getPDF(['id' => $id]);
    }


}
