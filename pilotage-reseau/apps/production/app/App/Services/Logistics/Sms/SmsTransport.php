<?php
declare(strict_types=1);

namespace App\Services\Logistics\Sms;

/** Wysyłka SMS (powiadomienia o dostawie, przypomnienia o płatności, notatki HR). */
interface SmsTransport
{
    /** Nazwa dostawcy w historii (smsapi, log_only). */
    public function name(): string;

    /** Czy SMS naprawdę wychodzi (false = tylko zapis w historii). */
    public function delivers(): bool;

    /**
     * @param array{customer?: ?int, context?: string, ref?: ?int, author?: ?string} $context dziennik SMS (n_sms_log) : klient, skąd, czego dotyczy
     * @return array{status: string, provider_id: ?string, error: ?string} status : sent, logged albo failed
     */
    public function send(string $to, string $text, array $context = []): array;
}
