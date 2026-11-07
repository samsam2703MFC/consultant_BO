<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Shipping\ShipmentPpwrReportPdfService;
use App\Services\Shipping\ShipmentPpwrReportService;
use Symfony\Component\HttpFoundation\Response;

class PpwrReportController extends BaseController
{
    public function __construct(
        private ShipmentPpwrReportService $reportService,
        private ShipmentPpwrReportPdfService $pdfService
    ) {
    }

    public function index(): void
    {
        [$dateFrom, $dateTo] = $this->getPeriod();
        try {
            $this->view('audit/ppwr_report/index', [
                'report' => $this->reportService->getReport($dateFrom, $dateTo),
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'errors' => [],
            ]);
        } catch (CustomException $exception) {
            $this->view('audit/ppwr_report/index', [
                'report' => null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    public function downloadPdf(): Response
    {
        try {
            [$dateFrom, $dateTo] = $this->getPeriod();
            $document = $this->pdfService->generate($this->reportService->getReport($dateFrom, $dateTo));
            return new Response($document['file_string'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . htmlspecialchars($document['filename']) . '"',
                'Content-Length' => strlen($document['file_string']),
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    private function getPeriod(): array
    {
        return [
            (string)($_GET['date_from'] ?? date('Y-m-01')),
            (string)($_GET['date_to'] ?? date('Y-m-d')),
        ];
    }
}
