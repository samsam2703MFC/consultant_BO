<?php

namespace App\Repositories\Ksef;

use App\Core\Helpers\BaseRepository;

final class KsefSubmissionRepository extends BaseRepository
{
    protected string $table = 'ksef_submission';
    protected $allowedColumns = [
        'id_document_wdt', 'id_shipment_log', 'status', 'environment', 'invoice_xml', 'invoice_hash',
        'session_reference_number', 'invoice_reference_number', 'ksef_number', 'upo_xml',
        'error_code', 'error_message', 'submitted_by', 'submitted_at', 'resolved_at',
    ];

    public function findByShipmentId(int $shipmentId): array
    {
        return $this->first(['id_shipment_log' => $shipmentId]);
    }

    public function findByWdtId(int $wdtId): array
    {
        return $this->first(['id_document_wdt' => $wdtId]);
    }

    public function createReady(int $shipmentId, int $wdtId, string $xml, string $environment): int
    {
        $id = $this->insert([
            'id_shipment_log' => $shipmentId,
            'id_document_wdt' => $wdtId,
            'status' => 'ready',
            'environment' => $environment,
            'invoice_xml' => $xml,
            'invoice_hash' => hash('sha256', $xml),
        ]);
        return (int) $id;
    }

    public function replacePreparedXml(int $id, string $xml): bool
    {
        return $this->update($id, [
            'status' => 'ready',
            'invoice_xml' => $xml,
            'invoice_hash' => hash('sha256', $xml),
            'error_code' => null,
            'error_message' => null,
            'session_reference_number' => null,
            'invoice_reference_number' => null,
            'submitted_at' => null,
            'resolved_at' => null,
            'ksef_number' => null,
            'upo_xml' => null,
        ]);
    }

    public function setStatus(int $id, string $status, array $details = []): bool
    {
        return $this->update($id, ['status' => $status] + $details);
    }

    /** @return list<array<string,mixed>> */
    public function getStatusesForShipments(): array
    {
        $rows = $this->query('SELECT id_shipment_log, status, ksef_number, error_message, updated_at FROM ksef_submission');
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    public function pendingForStatusRefresh(): array
    {
        $rows = $this->query("SELECT k.*, w.consignor_nip FROM ksef_submission k INNER JOIN document_wdt w ON w.id = k.id_document_wdt WHERE (k.status IN ('submitted', 'processing') OR (k.status = 'accepted' AND k.upo_xml IS NULL)) AND k.session_reference_number IS NOT NULL AND k.invoice_reference_number IS NOT NULL");
        return is_array($rows) ? $rows : [];
    }
}
