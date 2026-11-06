<?php
declare(strict_types=1);

namespace App\Services\Logistics\Sms;

/**
 * Tryb bez dostawcy SMS (parametr sms.provider = log_only) : treść i numer zapisane w historii powiadomień,
 * nic nie wychodzi do klienta. Dostawcę (np. bramkę SMS z kluczem API) podłącza się, implementując SmsTransport.
 */
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

    public function send(string $to, string $text): array
    {
        return ['status' => 'logged', 'provider_id' => null, 'error' => null];
    }
}
