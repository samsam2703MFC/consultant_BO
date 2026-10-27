<?php
declare(strict_types=1);

namespace App\Services\Logistics\Sms;

/** Wysyłka SMS do klienta (powiadomienia o dostawie). */
interface SmsTransport
{
    /** Nazwa dostawcy w historii (np. log_only). */
    public function name(): string;

    /** Czy SMS naprawdę wychodzi do klienta (false = tylko zapis w historii). */
    public function delivers(): bool;

    /**
     * @return array{status: string, provider_id: ?string, error: ?string} status : sent, logged albo failed
     */
    public function send(string $to, string $text): array;
}
