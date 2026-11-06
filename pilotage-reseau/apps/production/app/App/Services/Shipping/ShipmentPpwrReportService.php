<?php

namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Packaging\ShipmentPpwrReportRepository;

class ShipmentPpwrReportService
{
    public function __construct(private ShipmentPpwrReportRepository $repository)
    {
    }

    public function getReport(string $dateFrom, string $dateTo): array
    {
        $this->validateDateRange($dateFrom, $dateTo);
        $customers = [];
        foreach ($this->repository->getCustomers($dateFrom, $dateTo) as $customer) {
            $customers[(int)$customer['id_customer']] = [
                'customer_name' => $customer['customer_name'],
                'summary' => [],
            ];
        }
        foreach ($this->repository->getCustomerSummaries($dateFrom, $dateTo) as $row) {
            $customerId = (int)$row['id_customer'];
            if (!isset($customers[$customerId])) {
                $customers[$customerId] = [
                    'customer_name' => $row['customer_name'],
                    'summary' => [],
                ];
            }
            $customers[$customerId]['summary'][] = $row;
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $this->repository->getSummary($dateFrom, $dateTo),
            'customers' => array_values($customers),
            'coverage' => array_merge([
                'shipment_count' => 0,
                'without_evidence_count' => 0,
                'requires_data_count' => 0,
                'current_data_evidence_count' => 0,
                'current_ppwr_reconstruction_count' => 0,
                'missing_ppwr_snapshot_count' => 0,
            ], $this->repository->getCoverage($dateFrom, $dateTo)),
        ];
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
