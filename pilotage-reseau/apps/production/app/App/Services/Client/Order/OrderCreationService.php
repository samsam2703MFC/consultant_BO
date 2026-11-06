<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Exceptions\CustomException;
use App\Repositories\Communication\MailRuleRepository;
use App\Services\Client\ClientService;
use App\Services\Communication\DocumentDistributionService;
use App\Services\Document\ProformaService;

/**
 * Kreator « Nowe zamówienie » (audyt 2, P4) : 1 Klient i typ (standardowe, domówienie, gratis) → 2 Produkty z cennika
 * (dotychczasowy formularz zamówienia) → 3 Proforma wystawiona od razu → 4 Wysyłka (automat e-mail według Dystrybucji
 * dokumentów albo wzór wypełniony do wysłania) ; kończy się na zamówieniu, nie na liście. Proforma : jak dla zamówień
 * z API ; błąd proformy nie cofa zamówienia — trafia na listę « do uzupełnienia » (przycisk na zamówieniu).
 */
final class OrderCreationService
{
    public const TYPES = ['STANDARD' => 'Standardowe', 'SUPPLEMENT' => 'Domówienie', 'FREEBIE' => 'Gratis'];

    public function __construct(
        private ClientService $clients,
        private OrderService $orders,
        private ProformaService $proformas,
        private DocumentDistributionService $distribution,
        private MailRuleRepository $rules,
    ) {
    }

    /** Krok 1 : aktywni klienci (franczyza = domówienie możliwe), domyślna data wysyłki (następny dzień roboczy). */
    public function start(): array
    {
        $customers = [];
        foreach ($this->clients->getAll() ?: [] as $c) {
            if (isset($c['active']) && !(int) $c['active']) continue;
            $customers[] = ['id' => (int) $c['id'], 'company' => (string) ($c['company'] ?: trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''))),
                'franchise' => !empty($c['franchisee_uid'])];
        }
        usort($customers, fn($a, $b) => strcasecmp($a['company'], $b['company']));
        $date = new \DateTimeImmutable('tomorrow');
        while ((int) $date->format('N') > 5) $date = $date->modify('+1 day');
        return ['customers' => $customers, 'types' => self::TYPES, 'default_date' => $date->format('Y-m-d')];
    }

    /** Krok 1 → krok 2 : adres formularza produktów klienta z wybranym typem, datą i komentarzem. */
    public function productsUrl(array $input): string
    {
        $id = (int) ($input['id_customer'] ?? 0);
        $client = $id > 0 ? $this->clients->getById($id) : null;
        if (!$client) throw new CustomException('Wybierz klienta z listy.', 'errors', 422);
        $type = array_key_exists((string) ($input['type'] ?? ''), self::TYPES) ? (string) $input['type'] : 'STANDARD';
        if ($type === 'SUPPLEMENT' && empty($client['franchisee_uid'])) throw new CustomException('Domówienie tylko dla sklepu franczyzy.', 'errors', 422);
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new CustomException('Podaj datę wysyłki.', 'errors', 422);
        return ROOT . '/clients/' . $id . '/orders/create?' . http_build_query(['wizard' => 1, 'type' => $type, 'date' => $date,
            'note' => mb_substr(trim((string) ($input['note'] ?? '')), 0, 1000)]);
    }

    /** Krok 2 : wartości z kroku 1 (formularz produktów ; po błędzie wygrywa POST). */
    public function prefill(array $get): array
    {
        $type = (string) ($get['type'] ?? '');
        return ['wizard' => !empty($get['wizard']), 'order_type' => $type === 'SUPPLEMENT' ? 'SUPPLEMENT' : 'STANDARD', 'is_freebie' => $type === 'FREEBIE',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['date'] ?? '')) ? (string) $get['date'] : '', 'note' => (string) ($get['note'] ?? '')];
    }

    /** Krok 3 : proforma od razu po zapisie zamówienia. @return array{id:int, proforma_id:?int, problems:list<string>} */
    public function afterCreate(int $orderId): array
    {
        try {
            return ['id' => $orderId, 'proforma_id' => $this->orders->issueProforma($orderId), 'problems' => []];
        } catch (\Throwable $e) {
            return ['id' => $orderId, 'proforma_id' => null, 'problems' => ['Proforma : ' . $e->getMessage() . ' — wystaw ją przyciskiem na liście zamówień.']];
        }
    }

    /**
     * Krok 4 : jak proforma dotrze do klienta — automat (reguła « po wystawieniu proformy » włączona i adres w Dystrybucji
     * dokumentów) albo wzór e-maila do wysłania ręcznie.
     * @return array{proforma:?array, auto:bool, address:string, moment:string}
     */
    public function sending(int $customerId, ?int $proformaId): array
    {
        $proforma = $proformaId ? ($this->proformas->getById($proformaId) ?: null) : null;
        $cell = $this->distribution->forCustomer($customerId)['proforma'] ?? ['mail_to' => ''];
        $moment = $this->distribution->moments()['proforma'] ?? 'proforma_issued';
        $ruleOn = (bool) array_filter($this->rules->enabled(), fn($r) => ($r['event'] ?? '') === $moment);
        return ['proforma' => $proforma ? ['id' => (int) $proforma['id'], 'num' => (string) $proforma['doc_num'], 'sent' => !empty($proforma['is_sent_to_client'])] : null,
            'auto' => $ruleOn && ($cell['mail_to'] ?? '') !== '', 'address' => DocumentDistributionService::RECIPIENT_LABELS[$cell['mail_to'] ?? ''] ?? '',
            'moment' => DocumentDistributionService::MOMENTS[$moment] ?? $moment];
    }
}
