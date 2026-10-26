<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Client\ClientService;
use App\Services\Client\PriceList\ExternalPriceListService;
use App\Services\Client\PriceList\PriceListService;
use App\Services\Document\InvoiceService;
use App\Services\Product\ProductService;
use App\Repositories\Document\InvoicePrefillRepository;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends BaseController
{
    public function __construct(
        private ClientService $clientService,
        private ProductService $productService,
        private InvoiceService $invoiceService,
        private PriceListService $priceListService,
        private InvoicePrefillRepository $prefill,
    ) {
    }

    public function create($id)
    {
        try {
            $priceList = $this->priceListService->getPriceListByClient($id);
        } catch (\App\Exceptions\CustomException $e) {
            if ($e->getMessage() === 'MISSING_CLIENT') throw $e;
            // Cennik franczyzy (panel) niedostępny : faktura dalej możliwa, ceny z zamówienia albo ręcznie.
            error_log('[invoice create] price list: ' . $e->getMessage());
            $priceList = [];
            $this->errors[] = 'Cennik klienta (panel franczyzy) jest teraz niedostępny — ceny z zamówienia albo do wpisania.';
        }
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
        // P10 : faktura « inna » wypełniona z zamówienia (?order=) albo z WZ (?wz=) klienta — ceny i VAT z cennika klienta.
        $data['recent_orders'] = $this->prefill->recentOrders((int) $id);
        $data['recent_wz'] = $this->prefill->recentWz((int) $id);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!empty($_GET['order']) || !empty($_GET['wz']))) {
            $source = !empty($_GET['order']) ? $this->prefill->fromOrder((int) $_GET['order'], (int) $id) : $this->prefill->fromWz((int) $_GET['wz'], (int) $id);
            if ($source === null) {
                $this->errors[] = 'Dokument nie należy do tego klienta albo nie istnieje.';
            } else {
                $prices = [];
                foreach ($active as $price) $prices[(int) $price->getIdProduct()] = $price;
                $lines = [];
                foreach ($source['lines'] as $l) {
                    $pl = $prices[(int) $l['id_product']] ?? null;
                    $lines[(int) $l['id_product']] = [
                        'id' => (int) $l['id_product'], 'product_name' => $l['name'], 'quantity' => (int) $l['quantity'],
                        'single_price' => $pl ? (float) $pl->getPriceNet() : (float) ($l['price'] ?? 0),
                        'tax_perc' => $pl ? (float) $pl->getVatRate() : 0, 'ppc' => $pl ? (int) $pl->getPackageSize() : (int) ($l['ppc'] ?? 0),
                    ];
                }
                $data['old_values'] = ['invoice_product' => $lines];
                $data['prefill_label'] = $source['label'];
                $data['prefill_missing_price'] = count(array_filter($lines, fn($l) => !isset($prices[$l['id']])));
            }
        }

        if($_SERVER['REQUEST_METHOD'] == "POST"){
            $data['old_values'] = $_POST;
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