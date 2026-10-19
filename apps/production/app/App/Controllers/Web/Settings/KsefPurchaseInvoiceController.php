<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Services\Ksef\KsefPurchaseInvoiceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KsefPurchaseInvoiceController extends BaseController
{
    public function __construct(private KsefPurchaseInvoiceService $invoices)
    {
    }

    public function index(): void
    {
        $this->view('settings/ksef_purchase_invoices');
    }

    public function ajaxList(): Response
    {
        try {
            return $this->json(['ok' => true] + $this->invoices->list($this->getJson(Request::createFromGlobals())));
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function xml(string $ksefNumber): Response
    {
        try {
            $xml = $this->invoices->invoiceXml($ksefNumber);
            return new Response($xml, 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Faktura_KSeF_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ksefNumber) . '.xml"',
            ]);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

}
