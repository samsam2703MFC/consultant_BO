<?php

namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Packaging\ShipmentPackagingReportRepository;

class ShipmentPackagingReportService
{
    public function __construct(private ShipmentPackagingReportRepository $reportRepository)
    {
    }

    /** @return array{date_from:string,date_to:string,summary:list<array<string,mixed>>,coverage:array<string,mixed>} */
    public function getReport(string $dateFrom, string $dateTo): array
    {
        $this->validateDateRange($dateFrom, $dateTo);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $this->reportRepository->getSummary($dateFrom, $dateTo),
            'coverage' => $this->reportRepository->getCoverage($dateFrom, $dateTo),
        ];
    }

    /** @param array{date_from:string,date_to:string,summary:list<array<string,mixed>>,coverage:array<string,mixed>} $report */
    public function generateCsv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Raport opakowań BDO'], ';');
        fputcsv($stream, ['Okres', $report['date_from'] . ' – ' . $report['date_to']], ';');
        fputcsv($stream, [], ';');
        fputcsv($stream, ['Kontrola kompletności', 'Liczba wysyłek'], ';');
        foreach ([
            'Wysyłki w okresie' => $report['coverage']['shipment_count'],
            'Ewidencje kompletne' => $report['coverage']['complete_evidence_count'],
            'Ewidencje z brakami' => $report['coverage']['requires_data_count'],
            'Wysyłki bez ewidencji' => $report['coverage']['without_evidence_count'],
            'Ewidencje utworzone z danych bieżących' => $report['coverage']['current_data_evidence_count'],
        ] as $label => $value) {
            fputcsv($stream, [$label, $value], ';');
        }
        fputcsv($stream, [], ';');
        fputcsv($stream, ['Kategoria BDO', 'Rodzaj użycia', 'Masa [g]', 'Masa [kg]'], ';');
        foreach ($report['summary'] as $row) {
            $grams = (float)$row['total_weight_g'];
            fputcsv($stream, [
                $row['category'],
                $row['usage_type'],
                number_format($grams, 2, ',', ''),
                number_format($grams / 1000, 2, ',', ''),
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv ?: '';
    }

    private function validateDateRange(string $dateFrom, string $dateTo): void
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if (!$from || !$to || $from->format('Y-m-d') !== $dateFrom || $to->format('Y-m-d') !== $dateTo) {
            throw new CustomException('Podaj prawidłowy zakres dat.', 'errors', 422);
        }
        if ($from > $to) {
            throw new CustomException('Data początku okresu nie może być późniejsza niż data końca.', 'errors', 422);
        }
    }
}
