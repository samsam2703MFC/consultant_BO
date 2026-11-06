<?php
namespace App\Services\Communication;

use App\Repositories\Company\ErpCompanyDataRepository;
use App\Services\Client\ClientSpaceService;
use App\Services\Utils\DocumentLabels;

/** Kontekst wiadomości « Cel obrotu » (franczyza) : klient, miesiąc, zakupy, cel i cel dzienny — PDF w załączniku. */
final class SalesGoalEmailContextService
{
    public function __construct(private ClientSpaceService $space, private ErpCompanyDataRepository $company) {}

    public function forCustomer(int $customerId, string $month, string $source): array
    {
        $client = $this->space->client($customerId);
        $goal = $this->space->goal($customerId, $month, $source);
        $company = $this->company->first(['id' => 1]) ?: [];
        $recipient = (string) (($client['email_head'] ?? '') ?: ($client['email_accountant'] ?? '') ?: ($client['email_salesman'] ?? ''));
        $period = DocumentLabels::month($goal['month'], $client['lang']);
        return [
            'id_customer' => $customerId,
            'recipient_email' => $recipient,
            'document' => ['id' => $customerId, 'number' => 'Cel obrotu ' . DocumentLabels::month($goal['month'], 'pl'), 'date' => $goal['month'], 'label' => 'Cel obrotu'],
            'month' => $goal['month'],
            'source' => $goal['source'],
            'variables' => [
                'client' => ['company' => (string) ($client['company'] ?? ''), 'contact_name' => trim((string) (($client['name'] ?? '') . ' ' . ($client['surname'] ?? ''))) ?: (string) ($client['company'] ?? '')],
                'goal' => [
                    'period' => $period,
                    'purchases' => number_format($goal['totals']['purchases_sell'], 2, ',', ' '),
                    'target' => number_format($goal['totals']['target'], 0, ',', ' '),
                    'per_day' => number_format($goal['per_day'], 0, ',', ' '),
                    'coefficient' => $goal['totals']['coefficient'] !== null ? number_format($goal['totals']['coefficient'], 2, ',', ' ') : '',
                ],
                'company' => ['name' => (string) ($company['proforma_company_name'] ?? '')],
                'today' => ['date' => date('Y-m-d')],
            ],
        ];
    }
}
