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

    public function overview()
    {
        $data['generated_docs'] = $this->shippingLogService->getAllShippings();

        $this->view('document/shipping/overview', $data);
    }

    public function transportDocuments(): void
    {
        $this->view('document/shipping/transport_overview', [
            'generated_docs' => $this->shippingLogService->getAllShippings(),
        ]);
    }

    public function archiveByType(string $type): void
    {
        $labels = [
            'wz' => 'WZ',
            'cmr' => 'CMR',
            'wdt' => 'WDT',
            'difference' => 'dokumentów różnic',
        ];
        if (!isset($labels[$type])) {
            http_response_code(404);
            $this->errors[] = 'Nieznany typ archiwum dokumentów.';
            $this->overview();
            return;
        }

        $this->view('document/shipping/overview', [
            'generated_docs' => $this->shippingLogService->getAllByDocumentType($type),
            'archiveDocumentType' => $type,
            'archiveDocumentLabel' => $labels[$type],
        ]);
    }
}
