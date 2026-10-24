<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Document\Proforma\ProformaProductRepository;
use App\Repositories\Document\Proforma\ProformaRepository;

class ProformaEmailContextService
{
    public function __construct(
        private ProformaRepository $proformas,
        private ProformaProductRepository $products,
        private ClientRepository $clients
    ) {}

    public function forProforma(int $proformaId): array
    {
        $proforma = $this->proformas->first(['id' => $proformaId]);
        if ($proforma === []) {
            throw new CustomException('Nie znaleziono proformy.', 'errors', 404);
        }

        $client = $this->clients->first(['id' => $proforma['id_customer']]);
        if ($client === []) {
            throw new CustomException('Nie znaleziono klienta proformy.', 'errors', 404);
        }

        $amount = 0.0;
        foreach ($this->products->getByIdProforma($proformaId) ?: [] as $product) {
            $amount += (float) ($product['total_price_net'] ?? 0);
        }

        $contactName = trim(($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''));
        $recipient = (string) (
            ($client['email_accountant'] ?? '')
            ?: ($client['email_head'] ?? '')
            ?: ($client['email_salesman'] ?? '')
        );

        return [
            'id_customer' => (int) $proforma['id_customer'],
            'id_proforma' => $proformaId,
            'proforma_status' => strtolower(trim((string) ($proforma['status'] ?? 'issued'))),
            'document' => [
                'id' => $proformaId,
                'number' => (string) $proforma['doc_num'],
                'date' => (string) $proforma['established_date'],
                'pdf_url' => ROOT . '/ajax/proforma-documents/' . $proformaId . '/document',
            ],
            'recipient_email' => $recipient,
            'variables' => [
                'client' => [
                    'company' => (string) ($client['company'] ?? $proforma['consignee_name']),
                    'contact_name' => $contactName,
                ],
                'document' => [
                    'number' => (string) $proforma['doc_num'],
                    'date' => (string) $proforma['established_date'],
                ],
                'amount' => [
                    'value' => number_format($amount, 2, ',', ' '),
                    'currency' => 'EUR',
                ],
                'proforma' => [
                    'number' => (string) $proforma['doc_num'],
                    'date' => (string) $proforma['established_date'],
                    'amount' => number_format($amount, 2, ',', ' '),
                    'currency' => 'EUR',
                ],
                'company' => ['name' => (string) ($proforma['consignor_name'] ?? '')],
                'today' => ['date' => date('Y-m-d')],
            ],
        ];
    }
}
