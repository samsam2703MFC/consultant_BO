<?php
declare(strict_types=1);

namespace App\Repositories\Client;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [313] Historia wysyłek klienta : e-maile automatyczne i z Komunikacji (communication_message — klient wprost albo przez
 * jego proformę / WDT / fakturę), e-maile z Poczty przypisane do klienta (n_user_mail_sent) i SMS-y (n_sms_log).
 * Trzy zapytania łączone w PHP (różne kodowania tabel) ; bez szkiców.
 */
final class ClientMessageLogRepository extends WorkshopRepository
{
    protected string $table = 'n_sms_log';

    public function emails(int $customerId, ?string $since, int $limit): array
    {
        $date = 'COALESCE(cm.sent_at, cm.queued_at, cm.created_at)';
        return $this->safe("SELECT 'email' AS channel, 'system' AS origin, cm.id, cm.message_type AS kind, cm.recipient_email AS recipient, cm.subject_text AS subject,
                LEFT(cm.body_text, 4000) AS body, cm.status, cm.error_message AS error, cm.provider_message_id AS provider_id, cm.created_by AS author,
                cm.id_proforma, cm.id_wdt, cm.id_invoice, {$date} AS at
            FROM communication_message cm
            WHERE cm.status <> 'draft'
              AND (cm.id_customer = :c
                   OR cm.id_proforma IN (SELECT id FROM document_proforma WHERE id_customer = :c2)
                   OR cm.id_wdt IN (SELECT id FROM document_wdt WHERE id_customer = :c3)
                   OR cm.id_invoice IN (SELECT id FROM document_invoice WHERE id_customer = :c4))"
            . ($since ? " AND {$date} >= :since" : '') . " ORDER BY at DESC LIMIT " . $limit,
            ['c' => $customerId, 'c2' => $customerId, 'c3' => $customerId, 'c4' => $customerId] + ($since ? ['since' => $since] : []));
    }

    public function mailbox(int $customerId, ?string $since, int $limit): array
    {
        return $this->safe("SELECT 'email' AS channel, 'mailbox' AS origin, s.id, 'mailbox' AS kind, s.to_list AS recipient, s.subject, LEFT(s.body_text, 4000) AS body,
                s.status, s.error, s.mode AS provider_id, TRIM(CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.surname, ''))) AS author, s.created_at AS at
            FROM n_user_mail_sent s LEFT JOIN user u ON u.id = s.id_user
            WHERE s.id_customer = :c" . ($since ? ' AND s.created_at >= :since' : '') . ' ORDER BY s.created_at DESC LIMIT ' . $limit,
            ['c' => $customerId] + ($since ? ['since' => $since] : []));
    }

    public function sms(int $customerId, ?string $since, int $limit): array
    {
        return $this->safe("SELECT 'sms' AS channel, 'system' AS origin, l.id, l.context AS kind, l.recipient, NULL AS subject, l.body, l.status, l.error,
                l.provider AS provider_id, l.created_by AS author, l.ref_id, l.created_at AS at
            FROM n_sms_log l WHERE l.id_customer = :c" . ($since ? ' AND l.created_at >= :since' : '') . ' ORDER BY l.created_at DESC LIMIT ' . $limit,
            ['c' => $customerId] + ($since ? ['since' => $since] : []));
    }

    /** Autorzy zapisani jako identyfikatory (« order_list:2 », « user:3 », « 2 ») → imię i nazwisko. */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $rows = $this->safe('SELECT id, TRIM(CONCAT(COALESCE(name, \'\'), \' \', COALESCE(surname, \'\'))) AS n FROM user WHERE id IN (' . implode(',', $ids) . ')', []);
        return array_column($rows, 'n', 'id');
    }

    private function safe(string $sql, array $params): array
    {
        try {
            return $this->all($sql, $params);
        } catch (\PDOException $e) {
            error_log('[client-messages] ' . $e->getMessage());
            return [];
        }
    }
}
