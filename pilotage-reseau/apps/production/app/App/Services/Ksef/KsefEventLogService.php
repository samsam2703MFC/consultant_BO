<?php

namespace App\Services\Ksef;

use App\Repositories\Ksef\KsefEventLogRepository;

final class KsefEventLogService
{
    public function __construct(private KsefEventLogRepository $events) {}

    /** @param array<string,mixed> $details */
    public function error(?int $submissionId, int $shipmentId, string $stage, \Throwable $exception, array $details = []): void
    {
        $httpStatus = null;
        $ksefCode = null;
        if ($exception instanceof KsefApiException) {
            $httpStatus = $exception->httpStatus();
            $ksefCode = $exception->ksefCode();
            $details = ['path' => $exception->path()] + $exception->details() + $details;
        }
        try {
            $this->events->record([
                'id_ksef_submission' => $submissionId,
                'id_shipment_log' => $shipmentId,
                'level' => 'error',
                'stage' => $stage,
                'http_status' => $httpStatus,
                'ksef_code' => $ksefCode,
                'message' => mb_substr($exception->getMessage(), 0, 4000),
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $loggingException) {
            // A missing log table must never hide the original KSeF error.
            error_log('Nie udało się zapisać logu KSeF: ' . $loggingException->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public function latest(): array { return $this->events->latest(); }
}
