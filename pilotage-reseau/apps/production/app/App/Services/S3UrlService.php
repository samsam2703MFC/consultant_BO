<?php
namespace App\Services;

use Aws\S3\S3Client;

class S3UrlService
{
    public function getPresignedS3URL(
        string $s3Key,
        string $filename,
        string $mime = 'application/octet-stream',
        bool $inline = false,
        int $ttlMinutes = 15
    ): array {
        $minutes = max(1, min($ttlMinutes, 60));

        // np. bucketEnvPrefix = 'PROD' albo 'FRANCHISE'
        $region = $_ENV['AWS_REGION'];
        $bucket = $_ENV['AWS_S3_BUCKET'] ?? null;
        $key    = $_ENV['AWS_ACCESS_KEY_ID'] ?? null;
        $secret = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? null;

        if (!$bucket || !$key || !$secret) {
            throw new \RuntimeException("S3 configuration incomplete for {$_ENV['FRANCHISE_AWS_S3_BUCKET']}");
        }

        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ]);

        // Zabezpieczenie na znaki
        $safeName = str_replace('"', '', basename($filename));
        $disp     = $inline ? 'inline' : 'attachment';

        // Budowa komendy
        $cmd = $s3->getCommand('GetObject', [
            'Bucket'                     => $bucket,
            'Key'                        => $s3Key,
            'ResponseContentType'        => $mime,
            'ResponseContentDisposition' => sprintf('%s; filename="%s"', $disp, $safeName),
            'ResponseCacheControl'       => 'private, max-age=300',
        ]);

        $request = $s3->createPresignedRequest($cmd, sprintf('+%d minutes', $minutes));

        return [
            'url'       => (string)$request->getUri(),
            'expiresIn' => $minutes * 60,
            'inline'    => $inline ? 1 : 0,
        ];
    }
}