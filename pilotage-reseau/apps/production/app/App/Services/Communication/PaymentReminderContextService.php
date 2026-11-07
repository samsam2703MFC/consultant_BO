<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceProductRepository;
use App\Repositories\Shipping\Difference\OrderShippingDifferenceRepository;
use App\Repositories\Shipping\Log\ShippingLogRepository;
use App\Repositories\Document\Wdt\WDTRepository;
use App\Repositories\Client\ClientRepository;

class PaymentReminderContextService
{
    public function __construct(
        private OrderShippingDifferenceRepository $differenceRepository,
        private OrderShippingDifferenceProductRepository $productRepository,
        private ShippingLogRepository $shippingLogRepository,
        private WDTRepository $wdtRepository,
        private ClientRepository $clientRepository
    ) {}

    public function selectableDifferences(): array
    {
        return array_map(function (array $row): array {
            $amount = (float)($row['difference_amount'] ?? 0);
            $currency = $row['currency'] ?: 'EUR';
            $row['difference_amount_abs'] = number_format(abs($amount), 2, '.', '');
            $row['difference_direction'] = $amount >= 0 ? 'dopłata' : 'zwrot';
            $row['label'] = sprintf(
                '%s | %s | %s | %s %s (%s)',
                $row['invoice_number'] ?: 'brak numeru faktury',
                $row['client_name'] ?: 'brak klienta',
                $row['difference_date'] ?: 'brak daty',
                $row['difference_amount_abs'],
                $currency,
                $row['difference_direction']
            );

            return $row;
        }, $this->differenceRepository->getForPaymentReminderList());
    }

    public function forDifference(int $differenceId): array
    {
        $difference = $this->differenceRepository->first(['id' => $differenceId]);
        $log = $this->shippingLogRepository->first(['id_diff' => $differenceId]);
        if (!$difference || !$log) throw new CustomException('Nie znaleziono dokumentu różnic.', 'errors', 404);
        $wdt = $this->wdtRepository->first(['id' => $log['id_wdt']]);
        $client = $this->clientRepository->first(['id' => $difference['id_customer']]);
        if (!$wdt || !$client) throw new CustomException('Nie znaleziono danych klienta lub faktury WDT.', 'errors', 404);

        $amount = 0.0;
        foreach ($this->productRepository->getAllByDifferenceId($differenceId) as $item) {
            $amount += ((float)$item['invoiced_quantity'] * (float)$item['price_during_invoicing']) - ((float)$item['quantity_ordered'] * (float)$item['price_during_ordering']);
        }
        return [
            'id_customer' => (int)$difference['id_customer'], 'id_difference' => $differenceId, 'id_wdt' => (int)$log['id_wdt'],
            'difference_amount' => $amount,
            'recipient_email' => (string)($client['email_accountant'] ?: $client['email_head'] ?: $client['email_salesman']),
            'document' => [
                'id' => $differenceId,
                'number' => (string) $differenceId,
                'date' => (string) ($difference['established_date'] ?? ''),
                'label' => 'Dokument różnic',
            ],
            'variables' => [
                'client' => ['company' => $client['company'], 'contact_name' => trim(($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''))],
                'document' => ['number' => (string)$differenceId, 'date' => (string)($difference['established_date'] ?? '')],
                'invoice' => ['number' => $wdt['doc_num'], 'date' => (string)($wdt['invoiced_date'] ?? $wdt['established_date'] ?? '')],
                'difference' => ['amount' => number_format(abs($amount), 2, '.', ''), 'currency' => $wdt['currency'] ?? 'EUR', 'type' => $amount >= 0 ? 'dopłata' : 'zwrot'],
                'company' => ['name' => 'ATELIER WRO01 SP. Z O.O.'], 'today' => ['date' => date('Y-m-d')]
            ]
        ];
    }
}
