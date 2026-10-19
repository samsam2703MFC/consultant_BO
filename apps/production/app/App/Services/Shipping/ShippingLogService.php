<?php
namespace App\Services\Shipping;


use App\Repositories\Shipping\Log\ShippingLogRepository;

class ShippingLogService
{
    private ShippingLogRepository $shippingLogRepository;

    public function __construct(
        ShippingLogRepository $shippingLogRepository
    )
    {
        $this->shippingLogRepository = $shippingLogRepository;
    }

    public function getAllShippings()
    {
        return $this->shippingLogRepository->getAll();
    }

    /** @return list<array<string, mixed>> */
    public function getAllByDocumentType(string $documentType): array
    {
        return $this->shippingLogRepository->getAllByDocumentType($documentType);
    }
}
