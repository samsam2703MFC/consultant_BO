<?php
namespace App\Services\Shipping\Pallet;

use App\Repositories\Shipping\PaletDeliveryRepository;

class PalletDeliveryService
{
    private PaletDeliveryRepository $paletDeliveryRepository;

    public function __construct(
        PaletDeliveryRepository $paletDeliveryRepository
    )
    {
        $this->paletDeliveryRepository = $paletDeliveryRepository;
    }

    public function getDistinctDateWithoutDocument()
    {
        return $this->paletDeliveryRepository->getDistinctDateWithoutDocument();
    }

    public function getDistinctDateWithoutDocumentForComplaint()
    {
        return $this->paletDeliveryRepository->getDistinctDateWithoutDocumentForComplaint();
    }

    public function getCustomerByShippingDate($shippingDate)
    {
        return $this->paletDeliveryRepository->getCustomerByShippingDate($shippingDate);
    }

    /**
     * Zwraca porównanie produktów zamówionych vs zeskanowanych na paletach.
     * Wynik zawiera: id_product, product_name, quantity_ordered, quantity_scanned, quantity_diff.
     * quantity_diff < 0 → za mało zeskanowanych; > 0 → nadmiar; = 0 → OK.
     */
    public function getPendingProductsPreview(string $shippingDate, int $customerId): array
    {
        $rows = $this->paletDeliveryRepository->getPendingProductsPreview($shippingDate, $customerId);
        return $rows ?: [];
    }

    /**
     * Zwraca inne aktywne daty zamówień klienta różne od podanej daty wysyłki.
     */
    public function getOtherActiveOrderDatesByCustomer(string $shippingDate, int $customerId): array
    {
        $rows = $this->paletDeliveryRepository->getOtherActiveOrderDatesByCustomer($shippingDate, $customerId);
        return $rows ?: [];
    }

}