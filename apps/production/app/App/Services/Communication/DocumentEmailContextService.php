<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Document\Invoice\InvoiceProductRepository;
use App\Repositories\Document\Invoice\InvoiceRepository;
use App\Repositories\Document\Wdt\WDTRepository;

final class DocumentEmailContextService
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceProductRepository $invoiceProducts,
        private WDTRepository $wdts,
        private ClientRepository $clients
    ) {}

    public function forInvoice(int $id): array
    {
        $invoice = $this->invoices->first(['id' => $id]);
        if ($invoice === []) throw new CustomException('Nie znaleziono faktury.', 'errors', 404);
        $products = $this->invoiceProducts->getByInvoiceId($id);
        if ($products === []) throw new CustomException('Faktura nie ma pozycji.', 'errors', 422);
        $amount = 0.0;
        foreach ($products as $product) $amount += (float) $product['quantity'] * (float) $product['single_price'];
        return $this->context('invoice', $id, $invoice, $amount, 'EUR');
    }

    public function forWdt(int $id): array
    {
        $wdt = $this->wdts->first(['id' => $id]);
        if ($wdt === []) throw new CustomException('Nie znaleziono dokumentu WDT.', 'errors', 404);
        return $this->context('wdt', $id, $wdt, (float) ($wdt['total'] ?? $wdt['total_net'] ?? 0), (string) ($wdt['currency'] ?? 'EUR'));
    }

    private function context(string $type, int $id, array $document, float $amount, string $currency): array
    {
        $client = $this->clients->first(['id' => $document['id_customer']]);
        if ($client === []) throw new CustomException('Nie znaleziono klienta dokumentu.', 'errors', 404);
        $recipient = (string) (($client['email_accountant'] ?? '') ?: ($client['email_head'] ?? '') ?: ($client['email_salesman'] ?? ''));
        $date = (string) ($document['invoiced_date'] ?? $document['established_date'] ?? $document['create_timestamp'] ?? '');
        $number = (string) ($document['doc_num'] ?? '');
        $label = $type === 'wdt' ? 'WDT' : 'Faktura';
        return [
            'id_customer' => (int) $document['id_customer'],
            'id_invoice' => $type === 'invoice' ? $id : null,
            'id_wdt' => $type === 'wdt' ? $id : null,
            'recipient_email' => $recipient,
            'document' => ['id' => $id, 'number' => $number, 'date' => $date, 'label' => $label],
            'variables' => [
                'client' => ['company' => (string) ($client['company'] ?? $document['consignee_name'] ?? ''), 'contact_name' => trim((string) (($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''))) ],
                'document' => ['number' => $number, 'date' => $date],
                'amount' => ['value' => number_format($amount, 2, ',', ' '), 'currency' => $currency],
                'company' => ['name' => (string) ($document['consignor_name'] ?? '')],
                'today' => ['date' => date('Y-m-d')],
            ],
        ];
    }
}
