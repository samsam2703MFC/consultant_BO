<?php

namespace App\Services\Ksef;

use App\Repositories\Ksef\KsefSubmissionRepository;
use App\Repositories\Ksef\KsefWdtInvoiceSourceRepository;

final class KsefSubmissionService
{
    public function __construct(private KsefSubmissionRepository $submissions, private KsefWdtInvoiceSourceRepository $source, private KsefWdtInvoiceXmlMapper $mapper, private KsefInvoiceSender $sender, private KsefEventLogService $events) {}

    public function prepareForShipment(int $shipmentId): array
    {
        $invoice = $this->source->getForShipment($shipmentId);
        if ($invoice === []) throw new \InvalidArgumentException('Nie znaleziono WDT dla wybranej wysyłki.');
        $lines = $this->source->getLines((int)$invoice['id']);
        // Always validate current WDT rows before an invoice can be sent.
        try {
            $this->mapper->validate($invoice, $lines);
        } catch (\Throwable $exception) {
            $this->events->error(null, $shipmentId, 'walidacja faktury', $exception);
            throw $exception;
        }

        $existing = $this->submissions->findByShipmentId($shipmentId);
        if ($existing !== []) {
            // A rejected invoice was never accepted by KSeF. Rebuild its XML before
            // retrying, even though KSeF has assigned session/invoice references.
            if (in_array(($existing['status'] ?? ''), ['failed', 'rejected'], true)) {
                $xml = $this->mapper->build($invoice, $lines);
                $this->submissions->replacePreparedXml((int)$existing['id'], $xml);
                return ['submission' => $this->submissions->first(['id' => (int)$existing['id']]), 'created' => false];
            }
            return ['submission' => $existing, 'created' => false];
        }

        $xml = $this->mapper->build($invoice, $lines);
        $id = $this->submissions->createReady($shipmentId, (int)$invoice['id'], $xml, $this->sender->environment());
        return ['submission' => $this->submissions->first(['id' => $id]), 'created' => true];
    }
    public function sendForShipment(int $shipmentId): array { $prepared=$this->prepareForShipment($shipmentId); $row=$prepared['submission']; if (($row['status'] ?? '') === 'accepted') return $row; try { $invoice=$this->source->getForShipment($shipmentId); $result=$this->sender->send((string)$invoice['consignor_nip'], (string)$row['invoice_xml']); $this->submissions->setStatus((int)$row['id'],'processing',['session_reference_number'=>$result['sessionReference'],'invoice_reference_number'=>$result['invoiceReference'],'submitted_at'=>date('Y-m-d H:i:s')]); return $this->submissions->first(['id'=>(int)$row['id']]); } catch (\Throwable $e) { $this->submissions->setStatus((int)$row['id'],'failed',['error_code'=>$e instanceof KsefApiException ? $e->ksefCode() : null,'error_message'=>$e->getMessage()]); $this->events->error((int)$row['id'], $shipmentId, 'wysyłka do KSeF', $e); throw $e; } }

    /** @return array{checked:int,accepted:int,rejected:int,pending:int,errors:int,updates:list<array<string,mixed>>} */
    public function refreshPendingStatuses(): array
    {
        $result = ['checked'=>0, 'accepted'=>0, 'rejected'=>0, 'pending'=>0, 'errors'=>0, 'updates'=>[]];
        $tokens = [];
        foreach ($this->submissions->pendingForStatusRefresh() as $row) {
            $result['checked']++;
            try {
                $nip = (string)$row['consignor_nip'];
                $tokens[$nip] ??= $this->sender->authenticate($nip);
                $remote = $this->sender->invoiceStatus($tokens[$nip], (string)$row['session_reference_number'], (string)$row['invoice_reference_number']);
                $status = $remote['status'] ?? [];
                $code = (int)($status['code'] ?? 0);
                if ($code === 200) {
                    $details = ['ksef_number'=>$remote['ksefNumber'] ?? null, 'error_code'=>null, 'error_message'=>null, 'resolved_at'=>date('Y-m-d H:i:s')];
                    if (empty($row['upo_xml'])) {
                        try { $details['upo_xml'] = $this->sender->invoiceUpo($tokens[$nip], (string)$row['session_reference_number'], (string)$row['invoice_reference_number']); }
                        catch (\Throwable $exception) { $this->events->error((int)$row['id'], (int)$row['id_shipment_log'], 'pobieranie UPO KSeF', $exception); }
                    }
                    $this->submissions->setStatus((int)$row['id'], 'accepted', $details);
                    $result['updates'][] = ['shipmentId'=>(int)$row['id_shipment_log'], 'submission'=>['status'=>'accepted', 'ksef_number'=>$remote['ksefNumber'] ?? null, 'upo_available'=>!empty($details['upo_xml']) || !empty($row['upo_xml'])]];
                    $result['accepted']++;
                } elseif (in_array($code, [100, 150], true)) {
                    $result['pending']++;
                } else {
                    $message = (string)($status['description'] ?? 'KSeF odrzucił fakturę.');
                    if (!empty($status['details']) && is_array($status['details'])) $message .= ' ' . implode(' ', $status['details']);
                    $exception = new KsefApiException($message, 200, (string)$code, '/sessions/.../invoices/...', ['status'=>$status]);
                    $this->submissions->setStatus((int)$row['id'], 'rejected', ['error_code'=>(string)$code, 'error_message'=>$message, 'resolved_at'=>date('Y-m-d H:i:s')]);
                    $this->events->error((int)$row['id'], (int)$row['id_shipment_log'], 'końcowy status KSeF', $exception);
                    $result['updates'][] = ['shipmentId'=>(int)$row['id_shipment_log'], 'submission'=>['status'=>'rejected', 'ksef_number'=>null]];
                    $result['rejected']++;
                }
            } catch (\Throwable $exception) {
                $this->events->error((int)$row['id'], (int)$row['id_shipment_log'], 'odświeżanie statusu KSeF', $exception);
                $result['errors']++;
            }
        }
        return $result;
    }
}
