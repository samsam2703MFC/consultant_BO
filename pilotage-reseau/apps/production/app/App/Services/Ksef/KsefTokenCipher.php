<?php

namespace App\Services\Ksef;

use RuntimeException;

/**
 * Lightweight reversible protection for the KSeF token stored in the local DB.
 * The key intentionally lives in the application code, per the current deployment model.
 */
final class KsefTokenCipher
{
    private const KEY_MATERIAL = 'slfood-ksef-local-db-protection-v1';

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Nie udało się zaszyfrować tokenu KSeF.');
        }

        return 'v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipherText);
    }

    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new RuntimeException('Zapisany token KSeF ma nieprawidłowy format.');
        }

        [$version, $ivBase64, $tagBase64, $cipherTextBase64] = $parts;
        $iv = base64_decode($ivBase64, true);
        $tag = base64_decode($tagBase64, true);
        $cipherText = base64_decode($cipherTextBase64, true);
        if ($iv === false || $tag === false || $cipherText === false) {
            throw new RuntimeException('Nie udało się odczytać tokenu KSeF z bazy.');
        }

        $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plainText === false) {
            throw new RuntimeException('Nie udało się odszyfrować tokenu KSeF.');
        }

        return $plainText;
    }

    private function key(): string
    {
        return hash('sha256', self::KEY_MATERIAL, true);
    }
}
