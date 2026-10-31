<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Płatności dnia [230] : wpłaty proform (data i kwota z wyciągu), przypomnienia wysłane w dniu, zakończenie kreatora.
 */
final class PaymentsDayRepository extends WorkshopRepository
{
    protected string $table = 'n_proforma_payment';

    public function savePayment(int $proformaId, string $paidOn, ?float $amount, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_proforma_payment (id_proforma, paid_on, amount, recorded_by, recorded_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE paid_on = VALUES(paid_on), amount = VALUES(amount), recorded_by = VALUES(recorded_by), recorded_at = VALUES(recorded_at)'
        )->execute([$proformaId, $paidOn, $amount, $userId]);
    }

    /** Wpłaty zapisane w dniu (kreator) : proforma, klient, data i kwota. */
    public function paymentsRecorded(string $day): array
    {
        return $this->all(
            "SELECT pp.id_proforma, pp.paid_on, pp.amount, dp.doc_num, dp.established_date, c.company
             FROM n_proforma_payment pp JOIN document_proforma dp ON dp.id = pp.id_proforma LEFT JOIN customer c ON c.id = dp.id_customer
             WHERE pp.recorded_at >= ? AND pp.recorded_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY pp.recorded_at DESC",
            [$day, $day]
        );
    }

    /** Średnio dni od wystawienia do wpłaty (ostatnie 90 dni wpłat). */
    public function averageDaysToPay(string $day): ?float
    {
        $v = $this->all(
            'SELECT AVG(DATEDIFF(pp.paid_on, dp.established_date)) AS d FROM n_proforma_payment pp JOIN document_proforma dp ON dp.id = pp.id_proforma
             WHERE pp.paid_on > DATE_SUB(?, INTERVAL 90 DAY) AND pp.paid_on <= ? AND pp.paid_on >= dp.established_date',
            [$day, $day]
        )[0]['d'] ?? null;
        return $v === null ? null : round((float) $v, 1);
    }

    /** Przypomnienia proform wysłane w dniu (ręczne i automatyczne). */
    public function remindersSent(string $day): int
    {
        return (int) ($this->all(
            "SELECT COUNT(*) AS n FROM communication_message
             WHERE id_proforma IS NOT NULL AND (message_type LIKE 'proforma\\_reminder\\_%' OR message_type LIKE 'proforma\\_payment\\_reminder%')
               AND status NOT IN ('failed', 'cancelled', 'draft') AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",
            [$day, $day]
        )[0]['n'] ?? 0);
    }

    public function done(string $code, string $day): ?array
    {
        return $this->all('SELECT summary, done_at, done_by FROM n_wizard_done WHERE code = ? AND day = ?', [$code, $day])[0] ?? null;
    }

    public function markDone(string $code, string $day, string $summary, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE summary = VALUES(summary), done_by = VALUES(done_by), done_at = VALUES(done_at)'
        )->execute([$code, $day, mb_substr($summary, 0, 255), $userId]);
    }
}
