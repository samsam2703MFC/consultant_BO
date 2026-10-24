<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Services\Client\ClientSalesSummaryService;
use App\Services\Client\SalesGoalPdfService;
use App\Services\Document\CMRService;
use App\Services\Document\InvoiceService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\ProformaService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;
use App\Services\Product\Price\ClientPriceListService;
use App\Services\Shipping\ShipmentPpwrPdfService;

/** Pièce jointe PDF d'un document de la Dystrybucja dokumentów (générateurs existants, même rendu qu'à l'écran). */
final class DistributionDocuments
{
    /** Document absent pour ce cas : pas d'emballages déclarés, client sans tarif. */
    private const MISSING = ['SHIPMENT_PACKAGING_NOT_FOUND', 'PRICE_LIST_DATA_NOT_FOUND'];

    public function __construct(
        private ProformaService $proformas,
        private InvoiceService $invoices,
        private WDTService $wdts,
        private WZService $wzs,
        private CMRService $cmrs,
        private OrderShippingDifferenceService $differences,
        private ShipmentPpwrPdfService $ppwr,
        private ClientPriceListService $priceLists,
        private ClientSalesSummaryService $salesSummary,
        private SalesGoalPdfService $salesGoal,
    ) {
    }

    /** @return ?array{filename:string, mime_type:string, content:string} null = document indisponible pour ce cas */
    public function pdf(string $doc, array $ref): ?array
    {
        try {
            $file = match ($doc) {
                'proforma' => $this->proformas->generateDocumentPDF((int) $ref['id']),
                'invoice' => $this->invoices->generateDocumentPDF((int) $ref['id']),
                'wdt' => $this->wdts->generateDocumentPDF((int) $ref['id']),
                'wz' => $this->wzs->generateDocumentPDF((int) $ref['id']),
                'cmr' => $this->cmrs->generateDocumentPDF((int) $ref['id']),
                'diff' => $this->differences->generateDocumentPDF((int) $ref['id']),
                'ppwr' => $this->ppwr->generateDocumentPDF((int) $ref['id']),
                'price' => $this->priceLists->generateDocumentPDF((int) $ref['id']),
                'summary' => $this->summary((int) $ref['id'], (string) $ref['from'], (string) $ref['to']),
                'goal' => $this->salesGoal->generate((int) $ref['id'], (string) $ref['month'], 'wdt'),
                default => null,
            };
        } catch (CustomException $e) {
            if (in_array($e->getMessage(), self::MISSING, true)) return null;
            throw $e;
        }
        if ($file === null) return null;
        $content = (string) ($file['file_string'] ?? '');
        if (!str_starts_with(ltrim($content), '%PDF')) {
            throw new \RuntimeException('Generator dokumentu « ' . $doc . ' » nie zwrócił pliku PDF.');
        }
        return ['filename' => (string) ($file['filename'] ?? $doc . '.pdf'), 'mime_type' => 'application/pdf', 'content' => $content];
    }

    private function summary(int $customerId, string $from, string $to): array
    {
        $pdf = $this->salesSummary->buildPdf($customerId, $from, $to);
        return ['filename' => $pdf['filename'], 'file_string' => $pdf['content']];
    }
}
