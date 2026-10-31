<?php
namespace App\Services\Production\Cost;

use App\Repositories\Production\Cost\CostMediaTypeRepository;

class CostMediaTypeService
{
    public function __construct(
        private CostMediaTypeRepository $mediaTypeRepo
    ) {}

    public function getAll(): array
    {
        return $this->mediaTypeRepo->getAll();
    }

    public function getActive(): array
    {
        return $this->mediaTypeRepo->getActive();
    }

    public function getById(int $id): array
    {
        return $this->mediaTypeRepo->getById($id);
    }

    public function create(array $data): int
    {
        $code = strtoupper(trim($data['code'] ?? ''));
        if (empty($code)) {
            throw new \Exception('Kod nie może być pusty');
        }

        return (int)$this->mediaTypeRepo->insert([
            'code' => $code,
            'name' => trim($data['name'] ?? ''),
            'unit' => trim($data['unit'] ?? 'PLN'),
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $code = strtoupper(trim($data['code'] ?? ''));
        if (empty($code)) {
            throw new \Exception('Kod nie może być pusty');
        }

        return (bool)$this->mediaTypeRepo->update($id, [
            'code' => $code,
            'name' => trim($data['name'] ?? ''),
            'unit' => trim($data['unit'] ?? 'PLN'),
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);
    }

    public function delete(int $id): bool
    {
        return (bool)$this->mediaTypeRepo->delete($id);
    }

    public function toggleActive(int $id): bool
    {
        $item = $this->getById($id);
        if (empty($item)) {
            return false;
        }

        $newStatus = $item['is_active'] == 1 ? 0 : 1;
        return (bool)$this->mediaTypeRepo->update($id, ['is_active' => $newStatus]);
    }
}

