<?php
namespace App\Services\Settings;

use App\Exceptions\CustomException;
use App\Repositories\Settings\LanguageRepository;

class LanguageService
{
    public function __construct(private LanguageRepository $languageRepository)
    {
    }

    public function getAll(): array
    {
        return $this->languageRepository->findAll();
    }

    public function getActive(): array
    {
        return $this->languageRepository->findActive();
    }

    public function create(array $data): int|bool
    {
        $code = strtolower(trim($data['code'] ?? ''));
        $name = trim($data['name'] ?? '');

        $this->validateCode($code);
        $this->validateName($name);

        $existing = $this->languageRepository->findByCode($code);
        if (!empty($existing)) {
            throw new CustomException('LANGUAGE_CODE_ALREADY_EXISTS', 'settings', 409);
        }

        return $this->languageRepository->insert([
            'code'      => $code,
            'name'      => $name,
            'is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $code = strtolower(trim($data['code'] ?? ''));
        $name = trim($data['name'] ?? '');

        $this->validateCode($code);
        $this->validateName($name);

        return (bool)$this->languageRepository->update($id, [
            'code'      => $code,
            'name'      => $name,
            'is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->languageRepository->delete($id);
    }

    private function validateCode(string $code): void
    {
        if (!preg_match('/^[a-z]{2}$/', $code)) {
            throw new CustomException('INVALID_LANGUAGE_CODE', 'settings', 400);
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new CustomException('LANGUAGE_NAME_REQUIRED', 'settings', 400);
        }
    }
}
