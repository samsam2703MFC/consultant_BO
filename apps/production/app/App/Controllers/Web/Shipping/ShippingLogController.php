<?php
namespace App\Controllers\Web\Shipping;


use App\Core\Web\BaseController;
use App\Services\Shipping\Pallet\PalletDeliveryService;
use App\Services\Shipping\ShippingLogService;
use App\Services\Shipping\ShippingService;

class ShippingLogController extends BaseController
{
    private ShippingLogService $shippingLogService;

    public function __construct(
        ShippingLogService $shippingLogService
    )
    {
        $this->shippingLogService = $shippingLogService;
    }

    /** Jedna lista wysyłek i ich dokumentów ; ?type= = filtr rodzaju dokumentu (wz, cmr, wdt, difference, bdo-missing…). */
    public function overview()
    {
        $this->view('document/shipping/overview', [
            'generated_docs' => $this->shippingLogService->getAllShippings(),
            'docType' => (string) ($_GET['type'] ?? ''),
        ]);
    }

    /** Dawna lista « Dokumenty transportowe » (CMR, BDO, PPWR) : te same wysyłki w jednej liście. */
    public function transportDocuments(): void
    {
        header('Location: ' . ROOT . '/shipments');
        exit;
    }

    /** Dawne archiwum WZ / CMR / WDT / różnic : jedna lista z filtrem rodzaju dokumentu. */
    public function archiveByType(string $type): void
    {
        header('Location: ' . ROOT . '/shipments?type=' . rawurlencode($type));
        exit;
    }
}
