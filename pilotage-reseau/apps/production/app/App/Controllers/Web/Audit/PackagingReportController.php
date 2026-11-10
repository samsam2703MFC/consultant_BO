<?php

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Shipping\ShipmentPackagingReportPdfService;
use App\Services\Shipping\ShipmentPackagingReportService;
use Symfony\Component\HttpFoundation\Response;

class PackagingReportController extends BaseController
{
    public function __construct(
        private ShipmentPackagingReportService $reportService,
        private ShipmentPackagingReportPdfService $reportPdfService
    ) {
    }

    public function index(): void
    {
        [$dateFrom, $dateTo] = $this->getPeriod();
        try {
            $this->view('audit/packaging_report/index', [
                'report' => $this->reportService->getReport($dateFrom, $dateTo),
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'errors' => [],
            ]);
        } catch (CustomException $exception) {
            $this->view('audit/packaging_report/index', [
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
            $document = $this->reportPdfService->generate($this->reportService->getReport($dateFrom, $dateTo));

            return new Response($document['file_string'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . htmlspecialchars($document['filename']) . '"',
                'Content-Length' => strlen($document['file_string']),
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    public function downloadCsv(): Response
    {
        try {
            [$dateFrom, $dateTo] = $this->getPeriod();
            $csv = $this->reportService->generateCsv($this->reportService->getReport($dateFrom, $dateTo));

            return new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="RAPORT_BDO_' . $dateFrom . '_' . $dateTo . '.csv"',
                'Content-Length' => strlen($csv),
            ]);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        }
    }

    /** @return array{0:string,1:string} */
    private function getPeriod(): array
    {
        return [
            (string)($_GET['date_from'] ?? date('Y-m-01')),
            (string)($_GET['date_to'] ?? date('Y-m-d')),
        ];
    }
}
