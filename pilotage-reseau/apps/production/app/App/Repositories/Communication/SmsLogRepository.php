<?php
declare(strict_types=1);

namespace App\Repositories\Communication;

use App\Repositories\Workshop\WorkshopRepository;

/** [313] Dziennik wszystkich SMS-ów ERP (n_sms_log) i klucz SMSAPI wspólny dla ERP (erp_cles, portal commun). */
final class SmsLogRepository extends WorkshopRepository
{
    protected string $table = 'n_sms_log';

    public function log(array $row): void
    {
        try {
            $this->db->prepare('INSERT INTO n_sms_log (id_customer, context, ref_id, recipient, body, status, provider, provider_id, error, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$row['id_customer'], $row['context'], $row['ref_id'], mb_substr($row['recipient'], 0, 40), mb_substr($row['body'], 0, 1000),
                    $row['status'], $row['provider'], $row['provider_id'], $row['error'] !== null ? mb_substr($row['error'], 0, 255) : null, $row['created_by']]);
        } catch (\PDOException $e) {
            error_log('[sms-log] ' . $e->getMessage()); // przed migracją 198 : SMS i tak wychodzi
        }
    }

    /** Klucz wspólny ERP (tabela erp_cles, gdy istnieje w tej bazie) ; '' gdy brak. */
    public function sharedKey(string $name): string
    {
        try {
            $rows = $this->all("SELECT valeur FROM erp_cles WHERE cle = ? AND portail IN ('commun', 'pil') LIMIT 1", [$name]);
            return trim((string) ($rows[0]['valeur'] ?? ''));
        } catch (\PDOException) {
            return '';
        }
    }

    /** Ostatnie SMS-y (Ustawienia › SMS). */
    public function recent(int $limit = 10): array
    {
        try {
            return $this->all('SELECT l.id, l.id_customer, c.company, l.context, l.recipient, l.status, l.provider, l.error, l.created_at
                               FROM n_sms_log l LEFT JOIN customer c ON c.id = l.id_customer ORDER BY l.id DESC LIMIT ' . max(1, min(50, $limit)));
        } catch (\PDOException) {
            return [];
        }
    }
}
