<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;

/**
 * [271] Données personnelles du module HR (téléphone, date de naissance, contact d'urgence, notes, documents) :
 * AES-256-GCM, clé dérivée du secret du serveur (PRODUCTION_JWT_SECRET, .env), jamais du code — le dépôt est public.
 * Texte : « h1:iv:tag:données » en base64 ; fichier : « H1 » + iv (12) + tag (16) + données, en binaire.
 * Changer le secret du serveur rend ces données illisibles (à ressaisir) : comme les adresses de Czas pracy.
 */
final class HrCipher
{
    public function __construct(private WorkshopConfig $config)
    {
    }

    public function encrypt(string $plain): string
    {
        [$iv, $tag, $cipher] = $this->seal($plain);
        return 'h1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }

    /** null = vide ou illisible (autre secret du serveur). */
    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') return null;
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'h1') return null;
        [, $iv, $tag, $cipher] = array_map(fn($p) => base64_decode($p, true), $parts);
        if ($iv === false || $tag === false || $cipher === false) return null;
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    public function encryptBytes(string $bytes): string
    {
        [$iv, $tag, $cipher] = $this->seal($bytes);
        return 'H1' . $iv . $tag . $cipher;
    }

    public function decryptBytes(string $blob): ?string
    {
        if (strlen($blob) < 30 || substr($blob, 0, 2) !== 'H1') return null;
        $plain = openssl_decrypt(substr($blob, 30), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($blob, 2, 12), substr($blob, 14, 16));
        return $plain === false ? null : $plain;
    }

    /** @return array{0:string,1:string,2:string} iv, tag, données chiffrées */
    private function seal(string $plain): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new CustomException('Nie udało się zaszyfrować danych.', 'errors', 500);
        return [$iv, $tag, $cipher];
    }

    private function key(): string
    {
        $secret = $this->config->jwtSecret();
        if (strlen($secret) < 16) throw new CustomException('Brak sekretu serwera (PRODUCTION_JWT_SECRET) — dane HR nie mogą być zapisane.', 'errors', 500);
        return hash_hmac('sha256', 'n-hr-v1', $secret, true);
    }
}
