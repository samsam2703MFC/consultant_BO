<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Asystent « Faktury i KSeF dnia » [378] : lecture des expéditions (shipment_log : shipment_date, id_customer, id_wdt —
 * ShippingLogRepository), du client (customer.company, sinon name / surname), de leur facture WDT (document_wdt : doc_num,
 * invoiced_date, migration 077), de leur soumission KSeF (ksef_submission : status, environment, error_code, error_message,
 * submitted_at, updated_at, ksef_number — migrations 053 et 055, comme ShippingDayRepository::shipments et
 * OrderQueueRepository::ksefPending) et de la dernière erreur du journal KSeF de l'expédition (ksef_event_log : stage,
 * message, created_at — migration 054 ; validation refusée avant même la création de la soumission).
 * N'écrit que n_invoice_ksef_check.
 */
final class InvoicesKsefDayRepository extends WorkshopRepository
{
    protected string $table = 'n_invoice_ksef_check';

    /** Jour de la dernière validation de l'assistant avant le jour donné (null = jamais). */
    public function lastDoneDay(string $code, string $beforeDay): ?string
    {
        $r = $this->all('SELECT day FROM n_wizard_done WHERE code = ? AND day < ? ORDER BY day DESC LIMIT 1', [$code, $beforeDay])[0] ?? null;
        return $r ? substr((string) $r['day'], 0, 10) : null;
    }

    /**
     * Jour de la première expédition envoyée à KSeF (null = KSeF jamais utilisé) : avant, les WDT sont hors KSeF — même
     * règle que OrderQueueRepository::ksefPending.
     */
    public function ksefSince(): ?string
    {
        $r = $this->all('SELECT MIN(s.shipment_date) AS d FROM ksef_submission k INNER JOIN shipment_log s ON s.id = k.id_shipment_log')[0]['d'] ?? null;
        return $r ? substr((string) $r, 0, 10) : null;
    }

    /**
     * Expéditions entre deux jours (inclus) avec WDT, KSeF et résultat de l'assistant. $openOnly : seulement les expéditions
     * dont la facture n'est pas encore « accepted » (ou sans WDT / sans date de facture), et celles avec un résultat
     * enregistré le jour $openOnly (elles restent visibles le jour où elles ont été expliquées).
     */
    public function shipments(string $from, string $to, ?string $openOnly = null): array
    {
        $open = $openOnly === null ? '' : " AND (w.id IS NULL OR w.invoiced_date IS NULL OR k.id IS NULL OR k.status <> 'accepted' OR c.checked_on = ?)";
        $params = [$from, $to];
        if ($openOnly !== null) $params[] = $openOnly;
        return $this->all("SELECT sl.id, sl.shipment_date, sl.id_customer, sl.id_wdt AS sl_id_wdt,
                COALESCE(NULLIF(TRIM(cu.company), ''), NULLIF(TRIM(CONCAT(COALESCE(cu.name, ''), ' ', COALESCE(cu.surname, ''))), '')) AS customer,
                w.id AS wdt_id, w.doc_num AS wdt_num, w.invoiced_date,
                k.id AS ksef_id, k.status AS ksef_status, k.environment AS ksef_env, k.ksef_number, k.error_code, k.error_message,
                k.submitted_at, k.updated_at AS ksef_updated_at,
                (SELECT CONCAT(e.created_at, '|', e.stage, '|', e.message) FROM ksef_event_log e
                  WHERE e.id_shipment_log = sl.id AND e.level = 'error' ORDER BY e.created_at DESC, e.id DESC LIMIT 1) AS last_event,
                c.result, c.note, c.flags, c.ksef_status AS check_ksef_status, c.checked_on, c.updated_at AS check_at,
                COALESCE(NULLIF(TRIM(u.displayed_name), ''), u.login) AS check_by
            FROM shipment_log sl
            LEFT JOIN customer cu ON cu.id = sl.id_customer
            LEFT JOIN document_wdt w ON w.id = sl.id_wdt
            LEFT JOIN ksef_submission k ON k.id_shipment_log = sl.id
            LEFT JOIN n_invoice_ksef_check c ON c.id_shipment_log = sl.id
            LEFT JOIN user u ON u.id = c.id_user
            WHERE sl.shipment_date BETWEEN ? AND ?{$open}
            ORDER BY sl.shipment_date DESC, customer, sl.id", $params);
    }

    /** Un résultat par expédition : le dernier enregistré remplace le précédent. */
    public function saveCheck(int $shipmentId, string $shipmentDate, ?int $wdtId, string $result, ?string $note, string $flags, ?string $ksefStatus, string $checkedOn, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_invoice_ksef_check (id_shipment_log, shipment_date, id_wdt, result, note, flags, ksef_status, checked_on, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE shipment_date = VALUES(shipment_date), id_wdt = VALUES(id_wdt), result = VALUES(result), note = VALUES(note), flags = VALUES(flags),
                ksef_status = VALUES(ksef_status), checked_on = VALUES(checked_on), id_user = VALUES(id_user), updated_at = NOW()')
            ->execute([$shipmentId, $shipmentDate, $wdtId, $result, $note, mb_substr($flags, 0, 255), $ksefStatus, $checkedOn, $userId]);
    }
}
