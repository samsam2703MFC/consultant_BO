<?php
namespace App\Services\Communication;

use App\Repositories\Communication\EmailAccountRepository;
use InvalidArgumentException;

final class EmailAccountService
{
    /** [341] Usages des comptes d'envoi (une adresse par usage) : libellé PL et adresse proposée. */
    public const PURPOSES = [
        'finance' => ['label' => 'Faktury, proformy i przypomnienia o płatności', 'hint' => 'accounting@'],
        'logistics' => ['label' => 'Informacje logistyczne : dostawy, wysyłki, potwierdzenia zamówień', 'hint' => 'logistyka@'],
        'production' => ['label' => 'Produkcja : niezgodności, zakupy i odpowiedzi dostawców', 'hint' => 'produkcja@'],
    ];

    /** [341] Type de message → usage ; tout le reste part du compte finance. */
    private const TYPE_PURPOSE = [
        'delivery_details' => 'logistics', 'delivery_planned' => 'logistics', 'shipment_sent' => 'logistics', 'order_confirmation' => 'logistics',
        'supplier_nc' => 'production', 'buy_request' => 'production', 'supplier_order' => 'production',
        'tender_request' => 'production', 'tender_award' => 'production',
    ];

    public function __construct(private EmailAccountRepository $accounts, private SmtpTransport $transport) {}
    public function active(): array { return $this->accounts->active(); }
    /** Usage d'un type de message (finance par défaut). */
    public static function purposeOf(string $messageType): string { return self::TYPE_PURPOSE[$messageType] ?? 'finance'; }
    /** Compte actif de l'usage, sinon le compte par défaut (finance). */
    public function forPurpose(string $purpose): array { return $this->accounts->forPurpose($purpose) ?: $this->accounts->active(); }
    /** Compte d'envoi d'un type de message (ex. delivery_details → logistyka@). */
    public function forMessageType(string $messageType): array { return $this->forPurpose(self::purposeOf($messageType)); }
    /** @return array<string, array> compte configuré par usage ([] si aucun) */
    public function byPurpose(): array
    {
        $out = [];
        foreach (array_keys(self::PURPOSES) as $p) $out[$p] = $this->accounts->forPurpose($p);
        if ($out['finance'] === []) { // compte d'avant la migration 204 (sans usage) = finance
            $a = $this->accounts->active();
            if ($a !== [] && ($a['purpose'] ?? 'finance') === 'finance') $out['finance'] = $a;
        }
        return $out;
    }
    public function configured(): bool { return $this->active() !== []; }
    public function save(array $data): int
    {
        $purpose = (string) ($data['purpose'] ?? 'finance');
        if (!isset(self::PURPOSES[$purpose])) throw new InvalidArgumentException('Nieznane przeznaczenie konta.');
        foreach (['name','from_name','from_email','reply_to_email','smtp_host','smtp_username','imap_host','imap_username'] as $key) if (trim((string)($data[$key] ?? '')) === '') throw new InvalidArgumentException("Uzupełnij: $key");
        foreach (['from_email','reply_to_email','smtp_username','imap_username'] as $key) if (!filter_var($data[$key], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Nieprawidłowy adres: $key");
        $old = $this->byPurpose()[$purpose];
        $smtpSecret = trim((string)($data['smtp_secret'] ?? ''));
        $imapSecret = trim((string)($data['imap_secret'] ?? ''));
        if ($smtpSecret === '' && trim((string)($old['smtp_secret'] ?? '')) === '') throw new InvalidArgumentException('Podaj hasło aplikacji SMTP.');
        if ($imapSecret === '' && trim((string)($old['imap_secret'] ?? '')) === '') throw new InvalidArgumentException('Podaj hasło aplikacji IMAP.');
        $payload = [
            'name'=>trim($data['name']),'purpose'=>$purpose,'from_name'=>trim($data['from_name']),'from_email'=>trim($data['from_email']),'reply_to_email'=>trim($data['reply_to_email']),
            'smtp_host'=>trim($data['smtp_host']),'smtp_port'=>(int)($data['smtp_port'] ?: 587),'smtp_encryption'=>in_array($data['smtp_encryption'] ?? '', ['tls','ssl'], true) ? $data['smtp_encryption'] : 'tls','smtp_username'=>trim($data['smtp_username']),
            'imap_host'=>trim($data['imap_host']),'imap_port'=>(int)($data['imap_port'] ?: 993),'imap_username'=>trim($data['imap_username']),'is_active'=>1,
            // Legacy columns from migration 030 remain required on installations that have not yet applied migration 038.
            'smtp_secret_encrypted'=>$old['smtp_secret_encrypted'] ?? '',
            'smtp_secret'=>$smtpSecret !== '' ? $smtpSecret : $old['smtp_secret'],
            'imap_secret_encrypted'=>$old['imap_secret_encrypted'] ?? '',
            'imap_secret'=>$imapSecret !== '' ? $imapSecret : $old['imap_secret'],
        ];
        return $this->accounts->saveAccount($payload, $old['id'] ?? null);
    }
    public function test(string $recipient, string $purpose = 'finance'): string
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Nieprawidłowy adres testowy.');
        $account = isset(self::PURPOSES[$purpose]) ? ($this->byPurpose()[$purpose] ?? []) : [];
        if ($account === []) throw new InvalidArgumentException('Brak skonfigurowanego konta dla tego przeznaczenia.');
        $id = sprintf('<test-%s@%s>', bin2hex(random_bytes(12)), substr(strrchr($account['from_email'], '@') ?: 'localhost', 1));
        $providerId = $this->transport->send($account, $recipient, 'Test konfiguracji poczty', 'To jest wiadomość testowa z systemu SL Food.', $id);
        $this->accounts->update($account['id'], ['last_tested_at' => date('Y-m-d H:i:s')]);
        return $providerId;
    }
}
