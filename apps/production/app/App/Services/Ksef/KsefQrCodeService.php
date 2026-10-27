<?php

namespace App\Services\Ksef;

use DateTimeImmutable;
use InvalidArgumentException;

final class KsefQrCodeService
{
    public function invoiceVerificationUrl(string $environment, string $sellerNip, string $issueDate, string $invoiceXml): string
    {
        $host = match ($environment) {
            'test' => 'https://qr-test.ksef.mf.gov.pl',
            'production' => 'https://qr.ksef.mf.gov.pl',
            default => throw new InvalidArgumentException('Nieznane środowisko QR KSeF.'),
        };
        $nip = preg_replace('/\D/', '', $sellerNip) ?? '';
        if (!preg_match('/^\d{10}$/', $nip)) throw new InvalidArgumentException('Nieprawidłowy NIP sprzedawcy dla kodu QR KSeF.');
        try { $date = new DateTimeImmutable($issueDate); } catch (\Throwable) { throw new InvalidArgumentException('Nieprawidłowa data wystawienia dla kodu QR KSeF.'); }
        $hash = rtrim(strtr(base64_encode(hash('sha256', $invoiceXml, true)), '+/', '-_'), '=');
        return $host . '/invoice/' . $nip . '/' . $date->format('d-m-Y') . '/' . $hash;
    }
}
