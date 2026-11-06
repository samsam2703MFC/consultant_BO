<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Services\Ksef\KsefPurchaseInvoiceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KsefPurchaseInvoiceController extends BaseController
{
    public function __construct(private KsefPurchaseInvoiceService $invoices, private \App\Repositories\Material\Purchase\DocumentRepository $manualPurchases,
        private \App\Repositories\Workshop\InvoiceRepository $control)
    {
    }

    public function index(): void
    {
        $this->view('settings/ksef_purchase_invoices', ['manual_purchases' => $this->manualPurchases->countAll()]);
    }

    public function ajaxList(): Response
    {
        try {
            $list = $this->invoices->list($this->getJson(Request::createFromGlobals()));
            // Une seule chaîne d'achat : les factures déjà importées dans la kontrola faktur (Zaopatrzenie › Faktury) sont marquées.
            $imported = array_flip($this->control->importedKsef(array_values(array_filter(array_map(fn($i) => $i['ksefNumber'] ?? null, $list['invoices'] ?? [])))));
            foreach ($list['invoices'] ?? [] as $k => $i) $list['invoices'][$k]['inControl'] = isset($imported[$i['ksefNumber'] ?? '']);
            return $this->json(['ok' => true] + $list);
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
