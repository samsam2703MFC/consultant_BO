<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

/**
 * Où vont les images de signature : base de données par défaut (copie de test isolée, rien n'est
 * envoyé à l'extérieur) ; bucket privé S3 / Cloudflare R2 seulement si TFB_SIGNATURE_S3_BUCKET est
 * défini (identifiants AWS_*, point d'accès R2 éventuel dans TFB_SIGNATURE_S3_ENDPOINT). Une signature
 * est une donnée personnelle : jamais dans le bucket public des photos.
 */
final class SignatureStorage
{
    /** @return array{storage:string, image_png:?string, s3_key:?string} */
    public function store(int $employeeId, string $png, string $sha256): array
    {
        $bucket = $_ENV['TFB_SIGNATURE_S3_BUCKET'] ?? getenv('TFB_SIGNATURE_S3_BUCKET') ?: '';
        if ($bucket === '') return ['storage' => 'db', 'image_png' => $png, 's3_key' => null];
        $key = sprintf('production/signatures/%d/%s/%s.png', $employeeId, date('Y/m'), $sha256);
        $this->client()->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $png, 'ContentType' => 'image/png']);
        return ['storage' => 's3', 'image_png' => null, 's3_key' => $key];
    }

    public function load(array $row): ?string
    {
        if ($row['storage'] === 'db') return $row['image_png'];
        $bucket = $_ENV['TFB_SIGNATURE_S3_BUCKET'] ?? getenv('TFB_SIGNATURE_S3_BUCKET') ?: '';
        if ($bucket === '' || !$row['s3_key']) return null;
        return (string) $this->client()->getObject(['Bucket' => $bucket, 'Key' => $row['s3_key']])['Body'];
    }

    private function client(): \Aws\S3\S3Client
    {
        $env = fn(string $k) => $_ENV[$k] ?? getenv($k) ?: null;
        $config = ['version' => 'latest', 'region' => $env('AWS_REGION') ?: 'auto',
            'credentials' => ['key' => (string) $env('AWS_ACCESS_KEY_ID'), 'secret' => (string) $env('AWS_SECRET_ACCESS_KEY')]];
        if ($env('TFB_SIGNATURE_S3_ENDPOINT')) $config += ['endpoint' => $env('TFB_SIGNATURE_S3_ENDPOINT'), 'use_path_style_endpoint' => true];
        return new \Aws\S3\S3Client($config);
    }
}
