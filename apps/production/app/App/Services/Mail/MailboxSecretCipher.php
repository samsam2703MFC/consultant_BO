<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;

/**
 * Hasła skrzynek użytkowników w bazie (AES-256-GCM). Klucz z sekretu serwera (PRODUCTION_JWT_SECRET z .env),
 * nie z kodu : repozytorium jest publiczne. Zmiana sekretu = hasła do wpisania ponownie.
 */
final class MailboxSecretCipher
{
    public function __construct(private WorkshopConfig $config)
    {
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new CustomException('Nie udało się zaszyfrować hasła skrzynki.', 'errors', 500);
        return 'm1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }

    /** null = brak hasła albo nie do odczytania (inny sekret serwera). */
    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') return null;
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'm1') return null;
        [, $iv, $tag, $cipher] = array_map(fn($p) => base64_decode($p, true), $parts);
        if ($iv === false || $tag === false || $cipher === false) return null;
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        $secret = $this->config->jwtSecret();
        if (strlen($secret) < 16) throw new CustomException('Brak sekretu serwera (PRODUCTION_JWT_SECRET) — nie można zapisać hasła skrzynki.', 'errors', 500);
        return hash_hmac('sha256', 'n-user-mailbox-v1', $secret, true);
    }
}
