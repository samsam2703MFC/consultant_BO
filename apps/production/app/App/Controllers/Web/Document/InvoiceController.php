<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Client\PriceList\ExternalPriceListService;
use App\Services\Client\PriceList\PriceListService;
use App\Services\Document\InvoiceService;
use App\Services\Product\ProductService;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends BaseController
{
    public function __construct(
        private ClientService $clientService,
        private ProductService $productService,
        private InvoiceService $invoiceService,
        private PriceListService $priceListService,
    ) {
    }

    public function create($id)
    {
        $priceList = $this->priceListService->getPriceListByClient($id);
        $active = [];
        foreach ($priceList as $price){
            if($price->getIsActive()){
                $active[] = $price;
            }
        }
        $data['price_list'] = $active;
        $data['id_client'] = $id;
        $data['products'] = $this->productService->getAll();
        $data['client'] = $this->clientService->getById($id);

        if($_SERVER['REQUEST_METHOD'] == "POST"){
            $_POST['id_client'] = $id;
            $this->handleAction(function () {
                $this->invoiceService->insertNewInvoice($_POST);
                header('Location: ' . ROOT . '/invoice-documents');
                exit;
            }, default: false);
        }

        $this->view('document/invoice/new', $data);
    }

//    public function newInvoice()
//    {
//        $data['customers'] = $this->clientService->getActive();
//        $data['products'] = $this->productService->getAll();
//        $data['price_list'] = $this->externalPriceListService->getPriceListForClientByDate();
//        if($_SERVER['REQUEST_METHOD'] == "POST"){
//            $this->handleAction(function () {
//                $this->invoiceService->insertNewInvoice($_POST);
//                header('Location: ' . ROOT . '/invoice-documents');
//                exit;
//            }, default: false);
//        }
//
//        $this->view('document/invoice/new', $data);
//    }

    public function overview()
    {
        $data['existed_invoices'] = $this->invoiceService->getExisted();
        $data['clients'] = $this->clientService->getActive();
        $this->view('document/invoice/overview', $data);
    }

    public function ajaxGenerateDocument($id)
    {

        $respDoc = $this->handleAction(function () use ($id) {
            return $this->invoiceService->generateDocumentPDF($id);
        }, default: false);


        if (!$respDoc || !is_array($respDoc)) {
            return $this->json(['error' => implode('; ', $this->errors)], 500);
        }

        $pdfData  = $respDoc['file_string'];

        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }
}