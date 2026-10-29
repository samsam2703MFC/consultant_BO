<?php

namespace App\Repositories\Ksef;

use App\Core\Helpers\BaseRepository;

final class KsefEventLogRepository extends BaseRepository
{
    protected string $table = 'ksef_event_log';
    protected $allowedColumns = ['id_ksef_submission', 'id_shipment_log', 'level', 'stage', 'http_status', 'ksef_code', 'message', 'details_json'];

    /** @param array<string,mixed> $event */
    public function record(array $event): int
    {
        return (int) $this->insert($event);
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 200): array
    {
        $rows = $this->query(
            'SELECT e.*, w.doc_num FROM ksef_event_log e
             LEFT JOIN ksef_submission s ON s.id = e.id_ksef_submission
             LEFT JOIN document_wdt w ON w.id = s.id_document_wdt
             ORDER BY e.created_at DESC, e.id DESC LIMIT ' . max(1, min($limit, 500))
        );
        return is_array($rows) ? $rows : [];
    }
}
