<?php

require __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Shipping\Packaging\ShipmentPpwrReportRepository;
use App\Services\Shipping\ShipmentPpwrReportService;

final class InMemoryShipmentPpwrReportRepository extends ShipmentPpwrReportRepository
{
    public function __construct() {}

    public function getSummary(string $dateFrom, string $dateTo): array
    {
        return [['material' => 'PAPER_CARDBOARD', 'total_weight_g' => '1200.0']];
    }

    public function getCustomerSummaries(string $dateFrom, string $dateTo): array
    {
        return [
            ['id_customer' => 1, 'customer_name' => 'Klient A', 'material' => 'PAPER_CARDBOARD', 'total_weight_g' => '900.0'],
            ['id_customer' => 2, 'customer_name' => 'Klient B', 'material' => 'PLASTIC', 'total_weight_g' => '300.0'],
        ];
    }

    public function getCustomers(string $dateFrom, string $dateTo): array
    {
        return [
            ['id_customer' => 1, 'customer_name' => 'Klient A'],
            ['id_customer' => 2, 'customer_name' => 'Klient B'],
            ['id_customer' => 3, 'customer_name' => 'Klient C'],
        ];
    }

    public function getCoverage(string $dateFrom, string $dateTo): array
    {
        return ['shipment_count' => 2, 'without_evidence_count' => 0, 'requires_data_count' => 0, 'current_data_evidence_count' => 0, 'current_ppwr_reconstruction_count' => 1, 'missing_ppwr_snapshot_count' => 0];
    }
}

function ppwrReportExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$service = new ShipmentPpwrReportService(new InMemoryShipmentPpwrReportRepository());
$report = $service->getReport('2026-08-01', '2026-08-31');
ppwrReportExpect(count($report['customers']) === 3, 'Every customer with a shipment must have a report section.');
ppwrReportExpect($report['customers'][0]['customer_name'] === 'Klient A', 'Customer name must be retained.');
ppwrReportExpect($report['customers'][1]['summary'][0]['material'] === 'PLASTIC', 'Customer material summary must be retained.');
ppwrReportExpect($report['customers'][2]['summary'] === [], 'A customer without calculable PPWR mass must remain visible.');
ppwrReportExpect((int)$report['coverage']['shipment_count'] === 2, 'Coverage must be included in the report.');
ppwrReportExpect((int)$report['coverage']['current_ppwr_reconstruction_count'] === 1, 'PPWR reconstruction coverage must be included.');

echo "shipment_ppwr_report_service_test: OK\n";
