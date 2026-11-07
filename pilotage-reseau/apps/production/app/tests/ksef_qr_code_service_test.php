<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Services/Ksef/KsefQrCodeService.php';

use App\Services\Ksef\KsefQrCodeService;

function expectKsefQr(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$service = new KsefQrCodeService();
$xml = '<Faktura>test</Faktura>';
$hash = rtrim(strtr(base64_encode(hash('sha256', $xml, true)), '+/', '-_'), '=');

$testUrl = $service->invoiceVerificationUrl('test', 'PL 123-456-78-90', '2026-09-01', $xml);
expectKsefQr($testUrl === 'https://qr-test.ksef.mf.gov.pl/invoice/1234567890/01-09-2026/' . $hash, 'Test QR URL must use the KSeF Code I format.');

$productionUrl = $service->invoiceVerificationUrl('production', '1234567890', '2026-09-01', $xml);
expectKsefQr(str_starts_with($productionUrl, 'https://qr.ksef.mf.gov.pl/invoice/1234567890/01-09-2026/'), 'Production QR URL must use the production KSeF QR host.');

echo "ksef_qr_code_service_test: OK\n";
