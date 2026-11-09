<?php
declare(strict_types=1);

namespace App\Repositories\Mail;

use App\Repositories\Workshop\WorkshopRepository;

/** Stopki e-maili firmy na język (n_mail_footer) i język wiadomości z kolejki Komunikacji. */
final class MailFooterRepository extends WorkshopRepository
{
    protected string $table = 'n_mail_footer';

    /** @return array<string, string> język => tekst */
    public function footers(): array
    {
        try {
            return array_column($this->all('SELECT lang, body FROM n_mail_footer WHERE body IS NOT NULL'), 'body', 'lang');
        } catch (\PDOException) {
            return []; // przed migracją 117
        }
    }

    public function save(string $lang, ?string $body, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_mail_footer (lang, body, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by)')
            ->execute([$lang, $body, $userId]);
    }

    public function updatedAt(): ?string
    {
        return $this->all('SELECT MAX(updated_at) AS t FROM n_mail_footer')[0]['t'] ?? null;
    }

    /** Dane firmy do propozycji stopki (jak na WDT). */
    public function company(): array
    {
        return $this->all('SELECT wdt_company_name AS name, wdt_company_address_1 AS address_1, wdt_company_address_2 AS address_2, wdt_company_nip AS nip
                           FROM erp_company_data ORDER BY id LIMIT 1')[0] ?? [];
    }

    /** Język wiadomości z kolejki : język szablonu, inaczej język z karty klienta. */
    public function messageLang(array $message): ?string
    {
        if (!empty($message['template_id'])) {
            $lang = $this->all('SELECT language_code FROM communication_template WHERE id = ?', [(int) $message['template_id']])[0]['language_code'] ?? null;
            if ($lang) return strtolower((string) $lang);
        }
        if (!empty($message['id_customer'])) {
            $lang = $this->all('SELECT communication_lang FROM customer WHERE id = ?', [(int) $message['id_customer']])[0]['communication_lang'] ?? null;
            if ($lang) return strtolower((string) $lang);
        }
        return null;
    }
}
