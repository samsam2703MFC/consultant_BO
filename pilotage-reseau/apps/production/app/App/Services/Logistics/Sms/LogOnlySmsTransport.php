<?php
declare(strict_types=1);

namespace App\Services\Logistics\Sms;

/** Bez dostawcy SMS : treść zapisana w historii, nic nie wychodzi (testy ; w ERP domyślnie SmsGateway). */
final class LogOnlySmsTransport implements SmsTransport
{
    public function name(): string
    {
        return 'log_only';
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(string $to, string $text, array $context = []): array
    {
        return ['status' => 'logged', 'provider_id' => null, 'error' => null];
    }
}
