<?php
namespace App\Services\Client\ApiCommunication;
use Aws\S3\S3Client;

class ProformaService
{
    private \App\Services\Document\ProformaService $proformaService;

    public function __construct(
        \App\Services\Document\ProformaService $proformaService

    )
    {
        $this->proformaService = $proformaService;
    }

    public function send($idProforma, $callbackUrl, $authToken, $franchiseeCode, $orderKey)
    {
        // 1) Dane proformy
        $proformaRawData = $this->proformaService->getWithProducts($idProforma);
        $proformaData    = $this->proformaService->getById($idProforma); // zawiera m.in. 'uuid'

        // 2) Klient S3
        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $_ENV['AWS_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ],
        ]);

        // 3) Ścieżka w S3
        $tenant       = $franchiseeCode;                 // np. FR001
        $proformaUuid = $proformaData['uuid'];           // UUID v4
        $key = sprintf('proformas/%s/%s/%s/%s.pdf', $tenant, date('Y'), date('m'), $proformaUuid);

        // 4) Surowe bajty PDF
        // generateDocumentPDF(...) u Ciebie zwraca ['file_string'] – upewnijmy się, że to BINARNY PDF, nie base64
        $pdfOut   = $this->proformaService->generateDocumentPDF($idProforma)['file_string'];
        $pdfBytes = $pdfOut;

        // Jeżeli nie zaczyna się od "%PDF", spróbujmy zdekodować (awaryjnie – gdyby był base64)
        if (strpos($pdfBytes, '%PDF') !== 0) {
            $decoded = base64_decode($pdfOut, true);
            if ($decoded !== false && strpos($decoded, '%PDF') === 0) {
                $pdfBytes = $decoded;
            }
        }

        // 5) Kontrola integralności
        $sha256Hex  = hash('sha256', $pdfBytes);              // do callbacku
        $contentMd5 = base64_encode(md5($pdfBytes, true));    // dla S3 (opcjonalnie, ale pomaga)

        // 6) Tagi do rozliczeń i lifecycle
        $tags = http_build_query([
            'tenant'    => $tenant,
            'order_key' => $orderKey
        ]);

        // 7) Upload do S3
        try {
            $s3->putObject([
                'Bucket'               => $_ENV['AWS_S3_BUCKET'],
                'Key'                  => $key,
                'Body'                 => $pdfBytes,
                'ContentType'          => 'application/pdf',
                'ContentMD5'           => $contentMd5,           // weryfikacja po stronie S3
                'ServerSideEncryption' => 'AES256',              // SSE-S3
                'Tagging'              => $tags
            ]);
        } catch (\Throwable $e) {
            // zaloguj błąd uploadu, ew. retry / DLQ
            return false;
        }

        // 8) Payload callbacku (TYLKO metadane)
        $payload = [
            'franchisee_code'  => $tenant,
            'order_reference'  => $orderKey,
            'proforma_uuid'    => $proformaUuid,
            's3_bucket'        => $_ENV['AWS_S3_BUCKET'],
            's3_key'           => $key,
            'checksum_sha256'  => $sha256Hex,
            'size_bytes'       => strlen($pdfBytes),
            'issued_at'        => gmdate('c'),
            'proforma_raw_data'=> $proformaRawData,   // minimalny snapshot do rekonstrukcji
        ];

        // 9) Callback do franczyzy
        $ch = curl_init($callbackUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 60, // PDF + sieć – daj komfort
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $authToken,
                'Idempotency-Key: ' . $orderKey . '|' . $proformaUuid
            ],
            CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $result     = curl_exec($ch);
        $curlErrNo  = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        $info       = curl_getinfo($ch);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            // log: $curlErrMsg
            return false;
        }

        $responseCode = (int)($info['http_code'] ?? 0);
        return in_array($responseCode, [200, 201, 202, 204], true);
    }

}
