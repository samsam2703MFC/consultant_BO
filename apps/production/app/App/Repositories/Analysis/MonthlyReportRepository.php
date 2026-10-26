<?php
declare(strict_types=1);

namespace App\Repositories\Analysis;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Dane raportu miesięcznego spoza rentowności : reklamacje (liczba, wartość przyjęta, przyczyny, produkty, klienci,
 * czas obsługi), niezgodności i nazwy klientów do sprzedaży. Wartość reklamacji = przyjęta ilość × cena zakupu (EUR).
 */
final class MonthlyReportRepository extends WorkshopRepository
{
    protected string $table = 'complaint';

    /** Sumy reklamacji okresu. */
    public function complaintTotals(string $from, string $to): array
    {
        $row = $this->all(
            "SELECT COUNT(*) AS n,
                    SUM(acceptance_status = 'ACCEPTED') AS accepted, SUM(acceptance_status = 'REJECTED') AS rejected,
                    SUM(acceptance_status IN ('NEW', 'IN_REVIEW')) AS open,
                    SUM(complaint_type = 'PRODUCT') AS product, SUM(complaint_type = 'NON_PRODUCT') AS non_product,
                    COALESCE(SUM(CASE WHEN acceptance_status = 'ACCEPTED' THEN COALESCE(accepted_quantity, 0) * COALESCE(single_purchase_price, 0) ELSE 0 END), 0) AS value,
                    AVG(CASE WHEN acceptance_status IN ('ACCEPTED', 'REJECTED') AND acceptance_timestamp IS NOT NULL
                        THEN TIMESTAMPDIFF(HOUR, reported_at, acceptance_timestamp) / 24 END) AS days_to_decision
             FROM complaint WHERE reported_at >= ? AND reported_at < ? + INTERVAL 1 DAY",
            [$from, $to]
        )[0] ?? [];
        return [
            'count' => (int) ($row['n'] ?? 0), 'accepted' => (int) ($row['accepted'] ?? 0), 'rejected' => (int) ($row['rejected'] ?? 0),
            'open' => (int) ($row['open'] ?? 0), 'product' => (int) ($row['product'] ?? 0), 'non_product' => (int) ($row['non_product'] ?? 0),
            'value' => round((float) ($row['value'] ?? 0), 2),
            'days_to_decision' => isset($row['days_to_decision']) ? round((float) $row['days_to_decision'], 1) : null,
        ];
    }

    /** Reklamacje według przyczyny (klucz przyczyny). */
    public function complaintsByReason(string $from, string $to): array
    {
        return $this->all(
            "SELECT complaint_reason AS reason, COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN acceptance_status = 'ACCEPTED' THEN COALESCE(accepted_quantity, 0) * COALESCE(single_purchase_price, 0) ELSE 0 END), 0) AS value
             FROM complaint WHERE reported_at >= ? AND reported_at < ? + INTERVAL 1 DAY
             GROUP BY complaint_reason ORDER BY n DESC, value DESC",
            [$from, $to]
        );
    }

    public function complaintsByProduct(string $from, string $to, int $limit = 8): array
    {
        return $this->all(
            "SELECT c.id_product, COALESCE(fp.name, '—') AS name, COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN c.acceptance_status = 'ACCEPTED' THEN COALESCE(c.accepted_quantity, 0) * COALESCE(c.single_purchase_price, 0) ELSE 0 END), 0) AS value
             FROM complaint c LEFT JOIN final_product fp ON fp.id = c.id_product
             WHERE c.reported_at >= ? AND c.reported_at < ? + INTERVAL 1 DAY AND c.id_product IS NOT NULL
             GROUP BY c.id_product ORDER BY n DESC, value DESC LIMIT " . max(1, $limit),
            [$from, $to]
        );
    }

    public function complaintsByCustomer(string $from, string $to, int $limit = 8): array
    {
        return $this->all(
            "SELECT c.id_customer, COALESCE(NULLIF(TRIM(cu.company), ''), TRIM(CONCAT(cu.name, ' ', cu.surname)), '—') AS name, COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN c.acceptance_status = 'ACCEPTED' THEN COALESCE(c.accepted_quantity, 0) * COALESCE(c.single_purchase_price, 0) ELSE 0 END), 0) AS value
             FROM complaint c LEFT JOIN customer cu ON cu.id = c.id_customer
             WHERE c.reported_at >= ? AND c.reported_at < ? + INTERVAL 1 DAY
             GROUP BY c.id_customer ORDER BY n DESC, value DESC LIMIT " . max(1, $limit),
            [$from, $to]
        );
    }

    /** Niezgodności okresu według źródła : liczba, krytyczne, koszt. */
    public function nonConformities(string $from, string $to): array
    {
        try {
            return $this->all(
                "SELECT source, COUNT(*) AS n, SUM(severity = 'critical') AS critical, SUM(status <> 'closed') AS open, COALESCE(SUM(cost_impact), 0) AS cost
                 FROM n_nc WHERE reported_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC",
                [$from, $to]
            );
        } catch (\PDOException) {
            return [];
        }
    }

    /** Czy wiadomość danego typu od autora (np. « monthly-report:2026-09 ») jest już w kolejce lub wysłana. */
    public function alreadySent(string $type, string $author): bool
    {
        return (bool) $this->all(
            "SELECT 1 FROM communication_message WHERE message_type = ? AND created_by = ? AND status <> 'failed' LIMIT 1", [$type, $author]
        );
    }

    /** Ostatnie wysłane raporty (data, adres, status, autor). */
    public function sentHistory(string $type, int $limit = 8): array
    {
        return $this->all(
            "SELECT id, recipient_email, subject_text, status, created_by, created_at, sent_at FROM communication_message
             WHERE message_type = ? ORDER BY id DESC LIMIT " . max(1, $limit), [$type]
        );
    }

    /** @return array<int,string> nazwy klientów */
    public function customerNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column($this->all(
            "SELECT id, COALESCE(NULLIF(TRIM(company), ''), TRIM(CONCAT(name, ' ', surname))) AS name FROM customer WHERE id IN ({$in})", $ids
        ), 'name', 'id');
    }
}
