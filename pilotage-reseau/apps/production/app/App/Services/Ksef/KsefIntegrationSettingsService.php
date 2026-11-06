<?php

namespace App\Services\Ksef;

use App\Repositories\Ksef\KsefIntegrationSettingsRepository;
use InvalidArgumentException;

final class KsefIntegrationSettingsService
{
    public function __construct(
        private KsefIntegrationSettingsRepository $repository,
        private KsefTokenCipher $cipher,
        private KsefApiClient $api,
    ) {
    }

    public function getPublicSettings(): array
    {
        $row = $this->repository->get();
        return [
            'environment' => $row['environment'] ?? 'test',
            'tokenConfigured' => !empty($row['token_encrypted']),
            'lastConnectionCheckAt' => $row['last_connection_check_at'] ?? null,
            'lastConnectionCheckMessage' => $row['last_connection_check_message'] ?? null,
        ];
    }

    public function save(array $data): array
    {
        $environment = (string) ($data['environment'] ?? 'test');
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new InvalidArgumentException('Wybierz poprawne środowisko KSeF.');
        }

        $update = ['environment' => $environment];
        $token = trim((string) ($data['token'] ?? ''));
        if ($token !== '') {
            $update['token_encrypted'] = $this->cipher->encrypt($token);
        }
        $this->repository->save($update);

        return ['ok' => true, 'message' => 'Ustawienia KSeF zapisane.', 'settings' => $this->getPublicSettings()];
    }

    public function checkConnection(): array
    {
        $settings = $this->getPublicSettings();
        $message = $this->api->checkAvailability($settings['environment']);
        $this->repository->save([
            'last_connection_check_at' => date('Y-m-d H:i:s'),
            'last_connection_check_message' => $message,
        ]);

        return ['ok' => true, 'message' => $message, 'settings' => $this->getPublicSettings()];
    }
}
