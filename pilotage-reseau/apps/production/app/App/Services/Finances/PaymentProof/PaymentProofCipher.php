<?php
declare(strict_types=1);

namespace App\Services\Finances\PaymentProof;

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;

/**
 * Wpłata z e-maila [233] : potwierdzenie przelewu (plik, odczytany tekst, płacący) to dane klienta — zapis tylko
 * zaszyfrowany (AES-256-GCM, klucz z sekretu serwera, prefiks p1:), jak instrukcje dostawy.
 */
final class PaymentProofCipher
{
    public function __construct(private WorkshopConfig $config)
    {
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new CustomException('Nie udało się zaszyfrować potwierdzenia wpłaty.', 'errors', 500);
        return 'p1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }

    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') return null;
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'p1') return null;
        [, $iv, $tag, $cipher] = array_map(fn($p) => base64_decode($p, true), $parts);
        if ($iv === false || $tag === false || $cipher === false) return null;
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        $secret = $this->config->jwtSecret();
        if (strlen($secret) < 16) throw new CustomException('Brak sekretu serwera (PRODUCTION_JWT_SECRET) — nie można zapisać potwierdzenia wpłaty.', 'errors', 500);
        return hash_hmac('sha256', 'n-payment-proof-v1', $secret, true);
    }
}
